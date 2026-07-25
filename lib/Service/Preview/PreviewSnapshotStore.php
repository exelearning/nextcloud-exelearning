<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

use RuntimeException;
use ZipArchive;

/**
 * File-backed store for opaque editor-preview snapshots.
 *
 * The editor sends the WHOLE project as one ZIP on every opaque refresh and gets
 * back an unguessable capability id; the authless serving route then hands that
 * tree out without a session. PHP is request-scoped and those GETs carry no
 * session, so the capability must live on disk:
 *
 * ```
 * {root}/{previewId}/
 *   meta.json    { ownerUserId, createdAt, bytes }
 *   .accessed    touch-marker; its mtime is the idle-TTL / LRU clock
 *   content/     the extracted snapshot
 * ```
 *
 * This replaces the layered protocol-v2 store (immutable asset keys, incremental
 * revisions, a fixed-resource layer, per-revision manifests and their publish
 * critical section) — machinery whose whole purpose was to avoid re-uploading
 * unchanged bytes, for a protocol the editor no longer speaks.
 *
 * What survives the simplification, because Nextcloud is multi-user and the
 * others are not: the per-user snapshot cap and the global byte budget, both
 * with LRU eviction, plus the idle TTL. One author cannot fill a shared
 * instance's disk, and the feature as a whole stays bounded.
 *
 * Design invariants:
 *
 *  - **Atomic publication.** A snapshot is extracted into a staging directory
 *    beside the live one and swapped in with `rename()`. A concurrent GET sees
 *    the previous snapshot or the new one, never a half-written tree, and a
 *    failure anywhere leaves the live snapshot untouched.
 *  - **Content isolation.** Author bytes live under `content/`, so no path in
 *    the archive can collide with the store's own metadata — there are no
 *    reserved names to police.
 *  - **Traversal safety.** Archive entries are vetted by {@see SnapshotArchive}
 *    before extraction; served paths go through
 *    {@see PreviewPolicy::normalizePath()} and are then confirmed with `realpath()`
 *    to sit inside the content directory.
 *
 * OCP-free: the root is an injected plain directory and time comes from an
 * injected clock, so the whole store is unit-testable against a temp dir.
 */
final class PreviewSnapshotStore {
	/** @var callable():int */
	private $clock;

	/**
	 * @param string $root Absolute path to the snapshots root directory.
	 * @param PreviewSnapshotLimits $limits DoS bounds.
	 * @param (callable():int)|null $clock Unix-time source (tests stub it).
	 */
	public function __construct(
		private readonly string $root,
		private readonly PreviewSnapshotLimits $limits,
		?callable $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	// =========================================================================
	// Publication
	// =========================================================================

	/**
	 * Create or atomically replace a snapshot.
	 *
	 * $previewId is absent on the first refresh (mint a capability) and present
	 * afterwards (replace in place). An id that is unknown or owned by somebody
	 * else is refused, so a capability cannot be claimed from another author.
	 *
	 * @return array{previewId:string}|array{error:string,status:int}
	 */
	public function replace(string $ownerUserId, string $zipPath, ?string $previewId = null): array {
		$this->ensureDir($this->root);
		$this->sweepExpired();

		$replacing = $previewId !== null && $previewId !== '';
		if ($replacing) {
			$guard = $this->authorize((string)$previewId, $ownerUserId);
			if ($guard !== null) {
				return $guard;
			}
		} else {
			$this->enforceUserSnapshotCap($ownerUserId);
		}
		$id = $replacing ? (string)$previewId : $this->generateUuidV4();

		$staging = $this->root . '/.staging-' . bin2hex(random_bytes(12));
		if (!@mkdir($staging . '/content', 0770, true) && !is_dir($staging . '/content')) {
			return ['error' => 'Could not create the preview staging directory.', 'status' => 500];
		}

		$extracted = $this->extractInto($zipPath, $staging . '/content');
		if (isset($extracted['error'])) {
			$this->removeTree($staging);
			return $extracted;
		}

		$budget = $this->reserveBudget($id, $extracted['bytes']);
		if ($budget !== null) {
			$this->removeTree($staging);
			return $budget;
		}

		$this->writeFileAtomic($staging . '/meta.json', (string)json_encode([
			'ownerUserId' => $ownerUserId,
			'createdAt' => ($this->clock)(),
			'bytes' => $extracted['bytes'],
		], JSON_UNESCAPED_SLASHES));
		@touch($staging . '/.accessed', ($this->clock)());

		return $this->publish($staging, $id);
	}

	/**
	 * The authorization verdict for a capability this user is claiming.
	 *
	 * Both management verbs run through here, so publish and delete can never
	 * disagree about what owner scoping means: a malformed id is a 400, one
	 * nobody holds a 404 and somebody else's a 403. Keeping the rule in the
	 * store — rather than letting each caller assemble it from `exists()` and
	 * `ownerOf()` — is what makes that guarantee structural instead of a
	 * convention two controllers have to remember.
	 *
	 * @return array{error:string,status:int}|null Null when the caller may proceed.
	 */
	public function authorize(string $previewId, string $ownerUserId): ?array {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return ['error' => 'Invalid preview capability.', 'status' => 400];
		}
		$owner = $this->ownerOf($previewId);
		if ($owner === null) {
			return ['error' => 'Preview snapshot not found.', 'status' => 404];
		}
		if ($owner !== $ownerUserId) {
			return ['error' => 'Preview snapshot belongs to another user.', 'status' => 403];
		}
		return null;
	}

	/**
	 * Vet and extract the uploaded archive into $contentDir.
	 *
	 * @return array{bytes:int}|array{error:string,status:int}
	 */
	private function extractInto(string $zipPath, string $contentDir): array {
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			return ['error' => 'Invalid preview archive.', 'status' => 400];
		}
		try {
			SnapshotArchive::inspect($zip, $this->limits);
			$bytes = SnapshotArchive::extract($zip, $contentDir, $this->limits);
		} catch (RuntimeException $e) {
			return ['error' => $e->getMessage(), 'status' => 400];
		} finally {
			$zip->close();
		}
		return ['bytes' => $bytes];
	}

	/**
	 * Make room for $incoming bytes in the global budget, evicting other
	 * snapshots LRU-first.
	 *
	 * @return array{error:string,status:int}|null Null when the budget allows it.
	 */
	private function reserveBudget(string $currentId, int $incoming): ?array {
		$global = $this->globalBytes() - $this->snapshotBytes($currentId);
		if ($this->evictOthersForBudget($currentId, $incoming, $global) === null) {
			return ['error' => 'Preview storage budget exhausted.', 'status' => 507];
		}
		return null;
	}

	/**
	 * Swap the staged tree in for $id, keeping the previous one until the new one
	 * is live so a failure never destroys a working snapshot.
	 *
	 * @return array{previewId:string}|array{error:string,status:int}
	 */
	private function publish(string $staging, string $id): array {
		$target = $this->root . '/' . $id;
		$backup = $target . '.old-' . bin2hex(random_bytes(6));
		if (is_dir($target) && !@rename($target, $backup)) {
			$this->removeTree($staging);
			return ['error' => 'Could not replace the preview snapshot.', 'status' => 500];
		}
		if (!@rename($staging, $target)) {
			if (is_dir($backup)) {
				@rename($backup, $target);
			}
			$this->removeTree($staging);
			return ['error' => 'Could not publish the preview snapshot.', 'status' => 500];
		}
		if (is_dir($backup)) {
			$this->removeTree($backup);
		}
		return ['previewId' => $id];
	}

	// =========================================================================
	// Serving
	// =========================================================================

	/**
	 * Resolve a served path inside a snapshot and refresh its idle clock, so a
	 * preview in use never expires under the author.
	 *
	 * A scriptable type is a `document` (rewritten on every refresh, so served
	 * whole and uncached); everything else is an `asset` (revalidated with an
	 * ETag and range-capable). The kind IS the scriptability — there is no second
	 * flag to disagree with it.
	 *
	 * @return array{kind:string,contentType:string,bytes?:string,filePath?:string,size?:int,etag?:string}|null
	 */
	public function resolve(string $previewId, string $rawPath): ?array {
		if (!PreviewPolicy::isValidPreviewId($previewId) || $this->isExpired($previewId)) {
			return null;
		}
		$path = PreviewPolicy::normalizePath($rawPath);
		if ($path === null) {
			return null;
		}
		$file = $this->resolveInsideContent($previewId, $path);
		if ($file === null) {
			return null;
		}
		$this->touch($previewId);

		$contentType = PreviewPolicy::mimeForPath($path);
		if (PreviewPolicy::isScriptable($contentType)) {
			$bytes = @file_get_contents($file);
			if ($bytes === false) {
				return null;
			}
			return [
				'kind' => 'document',
				'contentType' => $contentType,
				'bytes' => $bytes,
			];
		}
		$size = (int)@filesize($file);
		// The content directory's inode is part of the identity on purpose: mtime
		// has one-second granularity, so an author refreshing twice within the
		// same second with an edit that keeps a file the same length would
		// otherwise produce the same tag and be handed a 304 for the previous
		// bytes. Every publish extracts into a fresh directory and renames it in,
		// so the inode always turns over.
		$generation = (string)@fileinode($this->snapshotDir($previewId) . '/content');
		return [
			'kind' => 'asset',
			'contentType' => $contentType,
			'filePath' => $file,
			'size' => $size,
			'etag' => sha1($path . '|' . $generation . '|' . (string)@filemtime($file) . '|' . $size),
		];
	}

	/**
	 * Map a normalized relative path onto a real file inside the snapshot's
	 * content directory.
	 *
	 * `normalizePath()` has already rejected traversal, but the tree is author
	 * controlled, so the resolved path is confirmed with `realpath()` as well: a
	 * symlink that survived extraction could otherwise point outside.
	 */
	private function resolveInsideContent(string $previewId, string $path): ?string {
		$contentDir = @realpath($this->snapshotDir($previewId) . '/content');
		if ($contentDir === false) {
			return null;
		}
		$file = @realpath($contentDir . '/' . $path);
		if ($file === false || !is_file($file) || !str_starts_with($file, $contentDir . '/')) {
			return null;
		}
		return $file;
	}

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/** The owner user id of a snapshot, or null when it does not exist. */
	public function ownerOf(string $previewId): ?string {
		$meta = $this->readMeta($previewId);
		$owner = $meta['ownerUserId'] ?? null;
		return is_string($owner) ? $owner : null;
	}

	/**
	 * Delete a snapshot after {@see authorize()} has cleared the caller.
	 *
	 * @return array{error:string,status:int}|null Null once it is gone.
	 */
	public function deleteOwned(string $previewId, string $ownerUserId): ?array {
		$guard = $this->authorize($previewId, $ownerUserId);
		if ($guard !== null) {
			return $guard;
		}
		$this->removeTree($this->snapshotDir($previewId));
		return null;
	}

	/** Whether a snapshot has been idle longer than the TTL. */
	public function isExpired(string $previewId): bool {
		$marker = $this->snapshotDir($previewId) . '/.accessed';
		clearstatcache(true, $marker);
		$accessed = @filemtime($marker);
		if ($accessed !== false) {
			return (($this->clock)() - $accessed) > $this->limits->ttlSeconds;
		}
		// A missing/unreadable `.accessed` marker must NOT make a snapshot
		// immortal — that would keep it counting against the global byte budget
		// forever and never let sweepExpired reclaim it. Fall back to meta.json
		// createdAt as the age clock; if meta.json is missing/corrupt too, the
		// directory is unusable (it can neither be owned nor served), so treat it
		// as expired and let sweepExpired reclaim it.
		$meta = $this->readMeta($previewId);
		$createdAt = isset($meta['createdAt']) ? (int)$meta['createdAt'] : null;
		if ($createdAt === null) {
			return true;
		}
		return (($this->clock)() - $createdAt) > $this->limits->ttlSeconds;
	}

	/**
	 * Remove every snapshot idle longer than the TTL. Returns the count swept.
	 * Called by the background cleanup job and opportunistically on publish.
	 */
	public function sweepExpired(): int {
		$swept = 0;
		foreach ($this->listSnapshotIds() as $previewId) {
			if ($this->isExpired($previewId)) {
				$this->removeTree($this->snapshotDir($previewId));
				$swept++;
			}
		}
		return $swept;
	}

	// =========================================================================
	// Budgets
	// =========================================================================

	/** Evict this user's LRU snapshots until they are under the per-user cap. */
	private function enforceUserSnapshotCap(string $ownerUserId): void {
		$owned = [];
		foreach ($this->listSnapshotIds() as $previewId) {
			if ($this->ownerOf($previewId) === $ownerUserId) {
				$owned[$previewId] = $this->lastAccess($previewId);
			}
		}
		while (count($owned) >= $this->limits->maxSnapshotsPerUser && $owned !== []) {
			$lru = array_keys($owned, min($owned))[0];
			$this->removeTree($this->snapshotDir($lru));
			unset($owned[$lru]);
		}
	}

	/**
	 * Evict OTHER snapshots (never $currentId) in LRU order until $incoming fits
	 * the global budget. Returns the updated total, or null when it cannot fit
	 * even after evicting every other snapshot.
	 */
	private function evictOthersForBudget(string $currentId, int $incoming, int $globalBytes): ?int {
		while ($globalBytes + $incoming > $this->limits->globalMaxBytes) {
			$candidates = [];
			foreach ($this->listSnapshotIds() as $previewId) {
				if ($previewId !== $currentId) {
					$candidates[$previewId] = $this->lastAccess($previewId);
				}
			}
			if ($candidates === []) {
				return null;
			}
			$lru = array_keys($candidates, min($candidates))[0];
			$globalBytes -= $this->snapshotBytes($lru);
			$this->removeTree($this->snapshotDir($lru));
		}
		return $globalBytes;
	}

	/** Sum of logical bytes across every snapshot. */
	private function globalBytes(): int {
		$total = 0;
		foreach ($this->listSnapshotIds() as $previewId) {
			$total += $this->snapshotBytes($previewId);
		}
		return $total;
	}

	/**
	 * The byte total a snapshot holds, recorded at publish time.
	 *
	 * Read from meta.json rather than walked: the extraction already counted
	 * every byte it wrote, so re-walking the tree on each budget check would be
	 * the same answer at a much higher cost.
	 */
	private function snapshotBytes(string $previewId): int {
		$meta = $this->readMeta($previewId);
		return isset($meta['bytes']) ? (int)$meta['bytes'] : 0;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function snapshotDir(string $previewId): string {
		return $this->root . '/' . $previewId;
	}

	/** @return array<string,mixed>|null */
	private function readMeta(string $previewId): ?array {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return null;
		}
		$raw = @file_get_contents($this->snapshotDir($previewId) . '/meta.json');
		if ($raw === false) {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** Refresh the idle-TTL / LRU clock. */
	private function touch(string $previewId): void {
		$marker = $this->snapshotDir($previewId) . '/.accessed';
		$now = ($this->clock)();
		if (!@touch($marker, $now)) {
			@file_put_contents($marker, '');
			@touch($marker, $now);
		}
		clearstatcache(true, $marker);
	}

	/** The last-access unix time of a snapshot (0 when unknown). */
	private function lastAccess(string $previewId): int {
		$marker = $this->snapshotDir($previewId) . '/.accessed';
		clearstatcache(true, $marker);
		$mtime = @filemtime($marker);
		return $mtime === false ? 0 : $mtime;
	}

	/** @return list<string> */
	private function listSnapshotIds(): array {
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
