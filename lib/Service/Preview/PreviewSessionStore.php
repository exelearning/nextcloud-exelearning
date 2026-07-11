<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * File-backed store for editor preview sessions (serving contract v2).
 *
 * PHP is request-scoped — an in-memory map does not survive between the
 * management POSTs and the authless GETs — so sessions live on disk under a
 * per-installation root. Each session directory holds three layers with
 * different lifecycles, exactly as the contract prescribes:
 *
 * ```
 * {root}/{previewId}/
 *   meta.json              { ownerUserId, createdAt }
 *   .accessed              touch-marker; its mtime is the idle-TTL / LRU clock
 *   .lock                  advisory flock for the revision critical section
 *   current                the active revision number (absent until first publish)
 *   assets/{sha1(key)}     immutable per assetKey, session-lifetime (layer 2)
 *   documents/{sha1(bytes)} content-addressed generated-document blobs (layer 3)
 *   revisions/{N}.json     { assetRefs, fixedRefs, documents:{path:{hash,size}}, documentBytes }
 * ```
 *
 * Design invariants that make the security/atomicity guarantees testable:
 *
 *  - **Atomic revisions.** A revision is published by (1) writing its new
 *    content-addressed blobs, (2) writing `revisions/{N+1}.json` via temp+rename,
 *    (3) swapping the `current` pointer via temp+rename. A concurrent GET reads
 *    `current` once and then an immutable revision manifest, so it always
 *    observes revision N or N+1 — never a mixture. Blobs and revision manifests
 *    are append-only for the session lifetime (never mutated or deleted until
 *    the session is removed), so a GET can never race a delete of what it reads.
 *  - **Asset immutability.** Assets are created with an atomic link() keyed by
 *    sha1(assetKey); re-uploading an existing key never replaces bytes (it is
 *    reported `alreadyStored`). The server never hashes asset *bytes*.
 *  - **Traversal safety.** Every client path (served path, revision writes /
 *    deletes, both ref-map key sets) goes through {@see PreviewPolicy::normalizePath()};
 *    stored blobs are addressed by hash, so a request can only ever name a
 *    stored entry — never a filesystem location.
 *  - **DoS bounds.** Per-session file-count and byte caps, a per-asset cap, a
 *    per-user session cap and a global cap (both with LRU eviction), and an idle
 *    TTL swept by a background job and opportunistically on access.
 *
 * OCP-free: the root is an injected plain directory and time comes from an
 * injected clock, so the whole store is unit-testable against a temp dir. A
 * small factory in {@see \OCA\ExeLearning\AppInfo\Application} resolves the real
 * root from the Nextcloud data directory. It requires a POSIX-local directory
 * (atomic rename + link + flock); this matches the contract's PHP-host note
 * ("atomic pointer swap ... rename a `current` marker file").
 */
final class PreviewSessionStore {
	/** @var callable():int */
	private $clock;

	/**
	 * @param string $root Absolute path to the sessions root directory.
	 * @param PreviewSessionLimits $limits DoS bounds.
	 * @param (callable():int)|null $clock Unix-time source (tests stub it).
	 */
	public function __construct(
		private readonly string $root,
		private readonly PreviewSessionLimits $limits,
		?callable $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function limits(): PreviewSessionLimits {
		return $this->limits;
	}

	// =========================================================================
	// Session lifecycle
	// =========================================================================

	/**
	 * Create a new session owned by $ownerUserId and return its capability id.
	 * Enforces the per-user session cap by evicting that user's least-recently
	 * accessed session first.
	 */
	public function create(string $ownerUserId): string {
		$this->ensureDir($this->root);
		$this->enforceUserSessionCap($ownerUserId);

		$previewId = $this->generateUuidV4();
		$dir = $this->sessionDir($previewId);
		$this->ensureDir($dir);
		$this->ensureDir($dir . '/assets');
		$this->ensureDir($dir . '/documents');
		$this->ensureDir($dir . '/revisions');
		$this->writeFileAtomic($dir . '/meta.json', json_encode([
			'ownerUserId' => $ownerUserId,
			'createdAt' => ($this->clock)(),
		], JSON_UNESCAPED_SLASHES));
		$this->touch($previewId);
		return $previewId;
	}

	/** Whether the session exists on disk (independent of TTL). */
	public function exists(string $previewId): bool {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return false;
		}
		$meta = $this->sessionDir($previewId) . '/meta.json';
		clearstatcache(true, $meta);
		return is_file($meta);
	}

	/** The owner user id of a session, or null when it does not exist. */
	public function ownerOf(string $previewId): ?string {
		$meta = $this->readMeta($previewId);
		return $meta['ownerUserId'] ?? null;
	}

	/** Delete a session and everything under it. Returns whether it existed. */
	public function delete(string $previewId): bool {
		if (!$this->exists($previewId)) {
			return false;
		}
		$this->removeTree($this->sessionDir($previewId));
		return true;
	}

	/** The active revision number, or 0 when nothing has been published yet. */
	public function activeRevision(string $previewId): int {
		$raw = @file_get_contents($this->sessionDir($previewId) . '/current');
		if ($raw === false) {
			return 0;
		}
		$n = (int)trim($raw);
		return $n > 0 ? $n : 0;
	}

	// =========================================================================
	// TTL / cleanup
	// =========================================================================

	/** Whether a session has been idle longer than the TTL. */
	public function isExpired(string $previewId): bool {
		$marker = $this->sessionDir($previewId) . '/.accessed';
		clearstatcache(true, $marker);
		$accessed = @filemtime($marker);
		if ($accessed === false) {
			return false;
		}
		return (($this->clock)() - $accessed) > $this->limits->ttlSeconds;
	}

	/**
	 * Remove every session idle longer than the TTL. Returns the count swept.
	 * Called by the background cleanup job and opportunistically on access.
	 */
	public function sweepExpired(): int {
		$swept = 0;
		foreach ($this->listSessionIds() as $previewId) {
			if ($this->isExpired($previewId)) {
				$this->removeTree($this->sessionDir($previewId));
				$swept++;
			}
		}
		return $swept;
	}

	// =========================================================================
	// Assets (layer 2)
	// =========================================================================

	/**
	 * Store uploaded assets. Keys are immutable: an existing key is reported in
	 * `alreadyStored` and its bytes are NOT replaced. Per-entry failures land in
	 * `rejected` with a reason. Budgets are enforced on the actual buffered
	 * bytes; a declared/actual size mismatch rejects that entry.
	 *
	 * @param list<array{key:string,declaredSize:int,bytes:string}> $entries
	 * @return array{stored:list<string>,alreadyStored:list<string>,rejected:list<array{key:string,reason:string}>}
	 */
	public function storeAssets(string $previewId, array $entries): array {
		$assetDir = $this->sessionDir($previewId) . '/assets';
		$this->ensureDir($assetDir);
		clearstatcache();

		$stored = [];
		$alreadyStored = [];
		$rejected = [];

		$sessionBytes = $this->sessionBytes($previewId);
		$globalBytes = $this->globalBytes();

		foreach ($entries as $entry) {
			$key = $entry['key'] ?? '';
			$bytes = $entry['bytes'] ?? '';
			$declared = $entry['declaredSize'] ?? -1;
			$size = strlen($bytes);

			if (!PreviewPolicy::isValidAssetKey($key)) {
				$rejected[] = ['key' => $key, 'reason' => 'invalid-key'];
				continue;
			}
			$target = $assetDir . '/' . sha1($key);
			if (is_file($target)) {
				$alreadyStored[] = $key;
				continue;
			}
			if ($declared !== $size) {
				$rejected[] = ['key' => $key, 'reason' => 'size-mismatch'];
				continue;
			}
			if ($size > $this->limits->maxAssetBytes) {
				$rejected[] = ['key' => $key, 'reason' => 'asset-too-large'];
				continue;
			}
			if ($sessionBytes + $size > $this->limits->maxBytesPerSession) {
				$rejected[] = ['key' => $key, 'reason' => 'session-budget-exceeded'];
				continue;
			}
			$globalBytes = $this->evictOthersForBudget($previewId, $size, $globalBytes);
			if ($globalBytes === null) {
				$rejected[] = ['key' => $key, 'reason' => 'global-budget-exceeded'];
				$globalBytes = $this->globalBytes();
				continue;
			}
			if (!$this->writeBlobAtomic($assetDir, sha1($key), $bytes)) {
				// Lost an immutability race: the key appeared concurrently.
				$alreadyStored[] = $key;
				continue;
			}
			$sessionBytes += $size;
			$globalBytes += $size;
			$stored[] = $key;
		}

		if ($stored !== []) {
			$this->touch($previewId);
		}
		return ['stored' => $stored, 'alreadyStored' => $alreadyStored, 'rejected' => $rejected];
	}

	// =========================================================================
	// Revisions (layer 3 publication)
	// =========================================================================

	/**
	 * Publish a revision. Validation order per the contract: revision check
	 * (409) → path normalization (400) → asset existence (422 missing-assets) →
	 * fixed-resource existence (422 unknown-fixed-resources) → file-count / byte
	 * budgets (413). The publish itself is an atomic blob-write → manifest-write
	 * → pointer-swap under an advisory lock.
	 *
	 * @param array{baseRevision:int,nextRevision:int,writes:list<array{path:string,bytes:string}>,deletes:list<string>,assetRefs:array<string,string>,fixedRefs:array<string,string>} $meta
	 * @return array{status:int,revision?:int,currentRevision?:int,reason?:string,missing?:list<string>,resources?:list<string>,message?:string}
	 */
	public function applyRevision(string $previewId, array $meta, FixedResourceManifest $fixed): array {
		$dir = $this->sessionDir($previewId);
		$lock = $this->acquireLock($dir);

		try {
			clearstatcache();
			$active = $this->activeRevision($previewId);
			$base = $meta['baseRevision'] ?? -1;
			$next = $meta['nextRevision'] ?? -1;
			if ($base !== $active || $next !== $active + 1) {
				return ['status' => 409, 'currentRevision' => $active];
			}

			// 2. Normalize every client-supplied path.
			$writes = [];
			foreach ($meta['writes'] ?? [] as $write) {
				$normalized = PreviewPolicy::normalizePath($write['path'] ?? '');
				if ($normalized === null) {
					return ['status' => 400, 'message' => 'Unsafe path in writes: ' . ($write['path'] ?? '')];
				}
				$writes[$normalized] = $write['bytes'] ?? '';
			}
			$deletes = [];
			foreach ($meta['deletes'] ?? [] as $rawPath) {
				$normalized = PreviewPolicy::normalizePath($rawPath);
				if ($normalized === null) {
					return ['status' => 400, 'message' => 'Unsafe path in deletes: ' . $rawPath];
				}
				$deletes[] = $normalized;
			}
			$assetRefs = [];
			foreach (($meta['assetRefs'] ?? []) as $rawPath => $key) {
				$normalized = PreviewPolicy::normalizePath((string)$rawPath);
				if ($normalized === null) {
					return ['status' => 400, 'message' => 'Unsafe path in assetRefs: ' . $rawPath];
				}
				$assetRefs[$normalized] = (string)$key;
			}
			$fixedRefs = [];
			foreach (($meta['fixedRefs'] ?? []) as $rawPath => $id) {
				$normalized = PreviewPolicy::normalizePath((string)$rawPath);
				if ($normalized === null) {
					return ['status' => 400, 'message' => 'Unsafe path in fixedRefs: ' . $rawPath];
				}
				$fixedRefs[$normalized] = (string)$id;
			}

			// 3. Every referenced asset must already be stored.
			$missing = [];
			foreach (array_unique(array_values($assetRefs)) as $key) {
				if (!is_file($dir . '/assets/' . sha1($key))) {
					$missing[] = $key;
				}
			}
			if ($missing !== []) {
				return ['status' => 422, 'reason' => 'missing-assets', 'missing' => array_values($missing)];
			}

			// 4. Every referenced fixed resource must be manifest-listed.
			$unknown = [];
			foreach (array_unique(array_values($fixedRefs)) as $id) {
				if (!$fixed->has($id)) {
					$unknown[] = $id;
				}
			}
			if ($unknown !== []) {
				return ['status' => 422, 'reason' => 'unknown-fixed-resources', 'resources' => array_values($unknown)];
			}

			// 5. Compute the post-delta document set (deletes first, then writes)
			//    and enforce the budgets before touching disk.
			$documents = $active > 0 ? ($this->readRevision($previewId, $active)['documents'] ?? []) : [];
			foreach ($deletes as $del) {
				if (!array_key_exists($del, $writes)) {
					unset($documents[$del]);
				}
			}
			foreach ($writes as $path => $bytes) {
				$documents[$path] = ['hash' => sha1($bytes), 'size' => strlen($bytes)];
			}
			$documentBytes = 0;
			foreach ($documents as $entry) {
				$documentBytes += $entry['size'];
			}
			$fileCount = count($documents) + count($assetRefs) + count($fixedRefs);
			if ($fileCount > $this->limits->maxFilesPerSession) {
				return ['status' => 413, 'message' => 'Too many files (max ' . $this->limits->maxFilesPerSession . ')'];
			}
			$assetBytes = $this->assetBytes($previewId);
			if ($documentBytes + $assetBytes > $this->limits->maxBytesPerSession) {
				return ['status' => 413, 'message' => 'Session over byte budget'];
			}
			$previousDocumentBytes = $active > 0 ? ($this->readRevision($previewId, $active)['documentBytes'] ?? 0) : 0;
			$byteDelta = $documentBytes - $previousDocumentBytes;
			if ($byteDelta > 0 && $this->evictOthersForBudget($previewId, $byteDelta, $this->globalBytes()) === null) {
				return ['status' => 413, 'message' => 'Preview storage budget exceeded'];
			}

			// 6. Publish. Write new content-addressed blobs, then the revision
			//    manifest, then atomically swap the pointer.
			foreach ($writes as $bytes) {
				$this->writeBlobAtomic($dir . '/documents', sha1($bytes), $bytes);
			}
			$this->writeFileAtomic($dir . '/revisions/' . $next . '.json', json_encode([
				'assetRefs' => (object)$assetRefs,
				'fixedRefs' => (object)$fixedRefs,
				'documents' => (object)$documents,
				'documentBytes' => $documentBytes,
			], JSON_UNESCAPED_SLASHES));
			$this->writeFileAtomic($dir . '/current', (string)$next);
			$this->touch($previewId);

			return ['status' => 200, 'revision' => $next];
		} finally {
			$this->releaseLock($lock);
		}
	}

	// =========================================================================
	// Serving (three-layer resolution)
	// =========================================================================

	/**
	 * Resolve a served path against the active revision, touching the idle-TTL
	 * clock. Returns a descriptor, or null for a missing/expired session, an
	 * unpublished session (revision 0), an unsafe path, or a miss.
	 *
	 * @return array{kind:string,contentType:string,isScriptable:bool,bytes?:string,filePath?:string,size?:int,etag?:string}|null
	 */
	public function resolve(string $previewId, string $rawPath, FixedResourceManifest $fixed): ?array {
		if (!$this->exists($previewId)) {
			return null;
		}
		if ($this->isExpired($previewId)) {
			$this->removeTree($this->sessionDir($previewId));
			return null;
		}
		$this->touch($previewId);

		$active = $this->activeRevision($previewId);
		if ($active === 0) {
			return null;
		}
		$path = PreviewPolicy::normalizePath($rawPath);
		if ($path === null) {
			return null;
		}
		$revision = $this->readRevision($previewId, $active);
		if ($revision === null) {
			return null;
		}
		$dir = $this->sessionDir($previewId);
		$contentType = PreviewPolicy::mimeForPath($path);
		$isScriptable = PreviewPolicy::isScriptable($contentType);

		$documents = $revision['documents'] ?? [];
		if (isset($documents[$path])) {
			$bytes = @file_get_contents($dir . '/documents/' . $documents[$path]['hash']);
			if ($bytes === false) {
				return null;
			}
			return ['kind' => 'document', 'contentType' => $contentType, 'isScriptable' => $isScriptable, 'bytes' => $bytes];
		}

		$assetRefs = $revision['assetRefs'] ?? [];
		if (isset($assetRefs[$path])) {
			$key = $assetRefs[$path];
			$file = $dir . '/assets/' . sha1($key);
			clearstatcache(true, $file);
			if (is_file($file)) {
				return [
					'kind' => 'asset',
					'contentType' => $contentType,
					'isScriptable' => $isScriptable,
					'filePath' => $file,
					'size' => (int)filesize($file),
					'etag' => $key,
				];
			}
		}

		$fixedRefs = $revision['fixedRefs'] ?? [];
		if (isset($fixedRefs[$path])) {
			$resource = $fixed->get($fixedRefs[$path]);
			if ($resource !== null) {
				return [
					'kind' => 'fixed',
					'contentType' => $contentType,
					'isScriptable' => $isScriptable,
					'bytes' => $resource['bytes'],
				];
			}
		}

		return null;
	}

	// =========================================================================
	// Internals
	// =========================================================================

	private function sessionDir(string $previewId): string {
		return $this->root . '/' . $previewId;
	}

	/** @return array<string,mixed>|null */
	private function readMeta(string $previewId): ?array {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return null;
		}
		$raw = @file_get_contents($this->sessionDir($previewId) . '/meta.json');
		if ($raw === false) {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Read a revision manifest with its inner maps decoded as associative
	 * arrays.
	 *
	 * @return array{assetRefs:array<string,string>,fixedRefs:array<string,string>,documents:array<string,array{hash:string,size:int}>,documentBytes:int}|null
	 */
	private function readRevision(string $previewId, int $revision): ?array {
		$raw = @file_get_contents($this->sessionDir($previewId) . '/revisions/' . $revision . '.json');
		if ($raw === false) {
			return null;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return null;
		}
		return [
			'assetRefs' => $decoded['assetRefs'] ?? [],
			'fixedRefs' => $decoded['fixedRefs'] ?? [],
			'documents' => $decoded['documents'] ?? [],
			'documentBytes' => $decoded['documentBytes'] ?? 0,
		];
	}

	/** Refresh the idle-TTL / LRU clock for a session. */
	private function touch(string $previewId): void {
		$marker = $this->sessionDir($previewId) . '/.accessed';
		$now = ($this->clock)();
		if (!@touch($marker, $now)) {
			@file_put_contents($marker, '');
			@touch($marker, $now);
		}
		clearstatcache(true, $marker);
	}

	/** The last-access unix time of a session (0 when unknown). */
	private function lastAccess(string $previewId): int {
		$marker = $this->sessionDir($previewId) . '/.accessed';
		clearstatcache(true, $marker);
		$mtime = @filemtime($marker);
		return $mtime === false ? 0 : $mtime;
	}

	/** Logical bytes a session holds: active-revision documents + all assets. */
	private function sessionBytes(string $previewId): int {
		return $this->documentBytes($previewId) + $this->assetBytes($previewId);
	}

	private function documentBytes(string $previewId): int {
		$active = $this->activeRevision($previewId);
		if ($active === 0) {
			return 0;
		}
		return $this->readRevision($previewId, $active)['documentBytes'] ?? 0;
	}

	private function assetBytes(string $previewId): int {
		$total = 0;
		$assetDir = $this->sessionDir($previewId) . '/assets';
		if (!is_dir($assetDir)) {
			return 0;
		}
		clearstatcache();
		foreach (scandir($assetDir) ?: [] as $entry) {
			// Skip '.', '..', staging temps and any dotfile marker.
			if (str_starts_with($entry, '.')) {
				continue;
			}
			$file = $assetDir . '/' . $entry;
			if (is_file($file)) {
				$total += (int)filesize($file);
			}
		}
		return $total;
	}

	/** Sum of logical bytes across every session. */
	private function globalBytes(): int {
		$total = 0;
		foreach ($this->listSessionIds() as $previewId) {
			$total += $this->sessionBytes($previewId);
		}
		return $total;
	}

	/** @return list<string> */
	private function listSessionIds(): array {
		$ids = [];
		if (!is_dir($this->root)) {
			return $ids;
		}
		foreach (scandir($this->root) ?: [] as $entry) {
			if (PreviewPolicy::isValidPreviewId($entry) && is_dir($this->root . '/' . $entry)) {
				$ids[] = $entry;
			}
		}
		return $ids;
	}

	/** Evict this user's LRU sessions until they are under the per-user cap. */
	private function enforceUserSessionCap(string $ownerUserId): void {
		$owned = [];
		foreach ($this->listSessionIds() as $previewId) {
			if ($this->ownerOf($previewId) === $ownerUserId) {
				$owned[$previewId] = $this->lastAccess($previewId);
			}
		}
		while (count($owned) >= $this->limits->maxSessionsPerUser && $owned !== []) {
			$lru = array_keys($owned, min($owned))[0];
			$this->removeTree($this->sessionDir($lru));
			unset($owned[$lru]);
		}
	}

	/**
	 * Evict OTHER sessions (never $currentId) in LRU order until $incomingBytes
	 * fits the global budget. Returns the updated global-byte total, or null when
	 * it cannot fit even after evicting every other session.
	 */
	private function evictOthersForBudget(string $currentId, int $incomingBytes, int $globalBytes): ?int {
		while ($globalBytes + $incomingBytes > $this->limits->globalMaxBytes) {
			$candidates = [];
			foreach ($this->listSessionIds() as $previewId) {
				if ($previewId !== $currentId) {
					$candidates[$previewId] = $this->lastAccess($previewId);
				}
			}
			if ($candidates === []) {
				return null;
			}
			$lru = array_keys($candidates, min($candidates))[0];
			$globalBytes -= $this->sessionBytes($lru);
			$this->removeTree($this->sessionDir($lru));
		}
		return $globalBytes;
	}

	/**
	 * Atomically create $dir/$name with $bytes. Returns true when newly created,
	 * false when it already existed (immutability preserved — bytes untouched).
	 */
	private function writeBlobAtomic(string $dir, string $name, string $bytes): bool {
		$target = $dir . '/' . $name;
		clearstatcache(true, $target);
		if (is_file($target)) {
			return false;
		}
		$tmp = $dir . '/.tmp.' . bin2hex(random_bytes(8));
		if (@file_put_contents($tmp, $bytes) === false) {
			@unlink($tmp);
			return false;
		}
		// link() is atomic and fails if the target already exists, giving both
		// atomic publication and immutability (never overwrite) in one step.
		if (@link($tmp, $target)) {
			@unlink($tmp);
			return true;
		}
		// Filesystem without hardlink support (or a lost race): publish via
		// rename ONLY when the target is still absent, so existing bytes are
		// never replaced.
		clearstatcache(true, $target);
		if (!is_file($target) && @rename($tmp, $target)) {
			return true;
		}
		@unlink($tmp);
		return false;
	}

	/** Write a small file atomically via temp + rename. */
	private function writeFileAtomic(string $path, string $contents): void {
		$tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
		if (@file_put_contents($tmp, $contents) === false) {
			@unlink($tmp);
			return;
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
		}
	}

	/** @return resource|null An exclusive advisory lock handle, or null. */
	private function acquireLock(string $dir) {
		$this->ensureDir($dir);
		$handle = @fopen($dir . '/.lock', 'c');
		if ($handle === false) {
			return null;
		}
		@flock($handle, LOCK_EX);
		return $handle;
	}

	/** @param resource|null $handle */
	private function releaseLock($handle): void {
		if (is_resource($handle)) {
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}
	}

	private function ensureDir(string $dir): void {
		if (!is_dir($dir)) {
			@mkdir($dir, 0770, true);
		}
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			@unlink($dir);
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path) && !is_link($path)) {
				$this->removeTree($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

	private function generateUuidV4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}
}
