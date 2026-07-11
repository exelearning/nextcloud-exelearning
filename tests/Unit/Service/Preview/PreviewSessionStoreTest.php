<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\FixedResourceManifest;
use OCA\ExeLearning\Service\Preview\PreviewSessionLimits;
use OCA\ExeLearning\Service\Preview\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PreviewSessionStore} — the file-backed session store:
 * lifecycle, per-user session cap, asset immutability, atomic revision ordering,
 * the full revision validation ladder, three-layer resolution, budgets and TTL.
 */
final class PreviewSessionStoreTest extends TestCase {
	private string $root;
	private string $distRoot;
	private int $now = 1000;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-store-' . bin2hex(random_bytes(6));
		$this->distRoot = sys_get_temp_dir() . '/exe-store-dist-' . bin2hex(random_bytes(6));
		mkdir($this->distRoot . '/bundles', 0770, true);
		mkdir($this->distRoot . '/libs/jquery', 0770, true);
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
		$this->removeTree($this->distRoot);
	}

	private function store(?PreviewSessionLimits $limits = null): PreviewSessionStore {
		return new PreviewSessionStore(
			$this->root,
			$limits ?? new PreviewSessionLimits(),
			fn (): int => $this->now,
		);
	}

	/** Manifest with two fixed resources (a JS lib and a scriptable SVG). */
	private function fixedManifest(): FixedResourceManifest {
		file_put_contents($this->distRoot . '/libs/jquery/jquery.min.js', 'window.jQuery=function(){};');
		file_put_contents(
			$this->distRoot . '/libs/jquery/icon.svg',
			'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
		);
		file_put_contents($this->distRoot . '/bundles/preview-fixed-resources.json', json_encode([
			'resources' => [
				'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js'],
				'theme:base/icon.svg' => ['path' => 'libs/jquery/icon.svg'],
			],
		]));
		return new FixedResourceManifest($this->distRoot);
	}

	/** An empty (disabled) fixed layer. */
	private function noFixed(): FixedResourceManifest {
		return new FixedResourceManifest($this->distRoot . '/absent');
	}

	private function asset(string $key, string $bytes): array {
		return ['key' => $key, 'declaredSize' => strlen($bytes), 'bytes' => $bytes];
	}

	// -- Lifecycle -----------------------------------------------------------

	public function testCreateAndOwnership(): void {
		$store = $this->store();
		$id = $store->create('alice');

		self::assertTrue($store->exists($id));
		self::assertSame('alice', $store->ownerOf($id));
		self::assertSame(0, $store->activeRevision($id));
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$id,
		);
	}

	public function testDeleteRemovesSession(): void {
		$store = $this->store();
		$id = $store->create('alice');
		self::assertTrue($store->delete($id));
		self::assertFalse($store->exists($id));
		self::assertFalse($store->delete($id));
		self::assertNull($store->ownerOf($id));
	}

	public function testPerUserSessionCapEvictsLeastRecentlyUsed(): void {
		$store = $this->store(new PreviewSessionLimits(maxSessionsPerUser: 2));
		$this->now = 1000;
		$first = $store->create('alice');
		$this->now = 1001;
		$second = $store->create('alice');
		$this->now = 1002;
		$third = $store->create('alice');

		self::assertFalse($store->exists($first), 'oldest owned session evicted');
		self::assertTrue($store->exists($second));
		self::assertTrue($store->exists($third));
		// A different user is unaffected by alice's cap.
		$bob = $store->create('bob');
		self::assertTrue($store->exists($bob));
	}

	// -- Assets --------------------------------------------------------------

	public function testStoreAssetsAndImmutability(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$key = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

		$first = $store->storeAssets($id, [$this->asset($key, 'PHOTO-BYTES-v1')]);
		self::assertSame([$key], $first['stored']);
		self::assertSame([], $first['alreadyStored']);

		// Re-upload the SAME key with DIFFERENT bytes → alreadyStored, not replaced.
		$second = $store->storeAssets($id, [$this->asset($key, 'PHOTO-BYTES-v2-DIFFERENT')]);
		self::assertSame([], $second['stored']);
		self::assertSame([$key], $second['alreadyStored']);

		// Publish a revision referencing it and prove the ORIGINAL bytes serve.
		$this->publish($store, $id, 0, 1, [], ['content/photo.png' => $key], []);
		$file = $store->resolve($id, 'content/photo.png', $this->noFixed());
		self::assertNotNull($file);
		self::assertSame($key, $file['etag']);
		self::assertSame('PHOTO-BYTES-v1', file_get_contents($file['filePath']));
	}

	public function testStoreAssetsRejections(): void {
		$store = $this->store(new PreviewSessionLimits(maxAssetBytes: 8, maxBytesPerSession: 20));
		$id = $store->create('alice');
		$goodKey = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
		$bigKey = 'bbbbbbbb-cccc-4ddd-8eee-ffff00001111@0011223344';

		$result = $store->storeAssets($id, [
			['key' => 'not-a-valid-key', 'declaredSize' => 3, 'bytes' => 'abc'],
			['key' => $goodKey, 'declaredSize' => 99, 'bytes' => 'abc'],
			$this->asset($bigKey, 'TOO-LONG-BYTES'),
		]);

		$reasons = [];
		foreach ($result['rejected'] as $entry) {
			$reasons[$entry['key']] = $entry['reason'];
		}
		self::assertSame('invalid-key', $reasons['not-a-valid-key']);
		self::assertSame('size-mismatch', $reasons[$goodKey]);
		self::assertSame('asset-too-large', $reasons[$bigKey]);
		self::assertSame([], $result['stored']);
	}

	// -- Revisions -----------------------------------------------------------

	public function testPublishRevisionHappyPath(): void {
		$store = $this->store();
		$fixed = $this->fixedManifest();
		$id = $store->create('alice');
		$key = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
		$store->storeAssets($id, [$this->asset($key, 'PHOTO')]);

		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [
				['path' => 'index.html', 'bytes' => '<html><body>hi</body></html>'],
				['path' => 'img/inline.svg', 'bytes' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
			],
			'deletes' => [],
			'assetRefs' => ['content/photo.png' => $key],
			'fixedRefs' => ['libs/jquery/jquery.min.js' => 'libs/jquery/jquery.min.js'],
		], $fixed);

		self::assertSame(200, $result['status']);
		self::assertSame(1, $result['revision']);
		self::assertSame(1, $store->activeRevision($id));

		$doc = $store->resolve($id, 'index.html', $fixed);
		self::assertSame('document', $doc['kind']);
		self::assertSame('<html><body>hi</body></html>', $doc['bytes']);

		$fixedFile = $store->resolve($id, 'libs/jquery/jquery.min.js', $fixed);
		self::assertSame('fixed', $fixedFile['kind']);
		self::assertSame('window.jQuery=function(){};', $fixedFile['bytes']);
	}

	public function testStaleBaseRevisionConflicts(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$this->publish($store, $id, 0, 1, [['path' => 'index.html', 'bytes' => 'v1']], [], []);

		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [['path' => 'index.html', 'bytes' => 'stale']],
			'deletes' => [],
			'assetRefs' => [],
			'fixedRefs' => [],
		], $this->noFixed());

		self::assertSame(409, $result['status']);
		self::assertSame(1, $result['currentRevision']);
		// The active revision is untouched by the rejected apply (atomicity).
		self::assertSame('v1', $store->resolve($id, 'index.html', $this->noFixed())['bytes']);
	}

	public function testUnsafeWritePathRejected(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [['path' => '../escape.html', 'bytes' => 'x']],
			'deletes' => [],
			'assetRefs' => [],
			'fixedRefs' => [],
		], $this->noFixed());
		self::assertSame(400, $result['status']);
		self::assertSame(0, $store->activeRevision($id));
	}

	public function testMissingAssetRefRejected(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [],
			'deletes' => [],
			'assetRefs' => ['content/ghost.png' => $ghost],
			'fixedRefs' => [],
		], $this->noFixed());
		self::assertSame(422, $result['status']);
		self::assertSame('missing-assets', $result['reason']);
		self::assertSame([$ghost], $result['missing']);
	}

	public function testUnknownFixedRefRejected(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [],
			'deletes' => [],
			'assetRefs' => [],
			'fixedRefs' => ['theme/x.css' => 'theme:not-in-manifest'],
		], $this->noFixed());
		self::assertSame(422, $result['status']);
		self::assertSame('unknown-fixed-resources', $result['reason']);
		self::assertSame(['theme:not-in-manifest'], $result['resources']);
	}

	public function testFileCountBudgetRejected(): void {
		$store = $this->store(new PreviewSessionLimits(maxFilesPerSession: 1));
		$id = $store->create('alice');
		$result = $store->applyRevision($id, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [
				['path' => 'a.html', 'bytes' => 'a'],
				['path' => 'b.html', 'bytes' => 'b'],
			],
			'deletes' => [],
			'assetRefs' => [],
			'fixedRefs' => [],
		], $this->noFixed());
		self::assertSame(413, $result['status']);
	}

	public function testSupersededRevisionsArePrunedToBoundDisk(): void {
		$store = $this->store();
		$id = $store->create('alice');
		// An asset that must survive every revision (shared/immutable across them).
		$key = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
		$store->storeAssets($id, [$this->asset($key, str_repeat('A', 4096))]);

		// Publish several revisions, each replacing index.html with UNIQUE content
		// (unique hash => a new blob every time; content-addressing cannot mask the
		// leak). Without pruning, physical disk grows with revision count even though
		// the active-revision budget stays flat — the disk-fill DoS.
		$docSize = 50000;
		$revisions = 6;
		for ($rev = 1; $rev <= $revisions; $rev++) {
			$unique = str_repeat('x', $docSize) . '-rev-' . $rev;
			$this->publish($store, $id, $rev - 1, $rev, [
				['path' => 'index.html', 'bytes' => $unique],
			], ['content/photo.png' => $key], []);
		}

		$sessionDir = $this->root . '/' . $id;

		// (a) Only the active revision's manifest and its single document blob remain.
		$manifests = array_values(array_filter(
			scandir($sessionDir . '/revisions') ?: [],
			static fn (string $f): bool => preg_match('/^\d+\.json$/', $f) === 1,
		));
		self::assertSame([$revisions . '.json'], $manifests, 'superseded revision manifests must be pruned');

		$blobs = array_values(array_filter(
			scandir($sessionDir . '/documents') ?: [],
			static fn (string $f): bool => preg_match('/^[0-9a-f]{40}$/', $f) === 1,
		));
		self::assertCount(1, $blobs, 'superseded document blobs must be pruned');

		// The active revision still serves correctly after pruning.
		$doc = $store->resolve($id, 'index.html', $this->noFixed());
		self::assertNotNull($doc);
		self::assertSame($docSize + strlen('-rev-' . $revisions), strlen($doc['bytes']));

		// (b) The asset persists across every revision.
		$assetFile = $store->resolve($id, 'content/photo.png', $this->noFixed());
		self::assertNotNull($assetFile);
		self::assertSame(str_repeat('A', 4096), file_get_contents($assetFile['filePath']));

		// (c) On-disk bytes stay bounded (~one document + one asset), not revisions x docSize.
		$onDisk = $this->diskBytes($sessionDir);
		self::assertLessThan($store->limits()->maxBytesPerSession, $onDisk);
		self::assertLessThan($docSize * 3, $onDisk, 'retained disk must not grow with revision count');
	}

	public function testIncrementalDeltaAndDeletes(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$this->publish($store, $id, 0, 1, [
			['path' => 'index.html', 'bytes' => 'home'],
			['path' => 'gone.html', 'bytes' => 'temp'],
		], [], []);
		// Revision 2: rewrite index.html, delete gone.html.
		$this->publish($store, $id, 1, 2, [['path' => 'index.html', 'bytes' => 'home-v2']], [], [], ['gone.html']);

		self::assertSame(2, $store->activeRevision($id));
		self::assertSame('home-v2', $store->resolve($id, 'index.html', $this->noFixed())['bytes']);
		self::assertNull($store->resolve($id, 'gone.html', $this->noFixed()));
	}

	// -- Resolution edge cases ----------------------------------------------

	public function testResolveBeforeFirstRevisionIsNull(): void {
		$store = $this->store();
		$id = $store->create('alice');
		self::assertNull($store->resolve($id, 'index.html', $this->noFixed()));
	}

	public function testResolveUnknownPathIsNull(): void {
		$store = $this->store();
		$id = $store->create('alice');
		$this->publish($store, $id, 0, 1, [['path' => 'index.html', 'bytes' => 'x']], [], []);
		self::assertNull($store->resolve($id, 'nope.css', $this->noFixed()));
		self::assertNull($store->resolve($id, '../escape', $this->noFixed()));
	}

	public function testResolveOnMissingSessionIsNull(): void {
		$store = $this->store();
		self::assertNull($store->resolve('3f2a1b4c-5d6e-4f70-8a90-b1c2d3e4f506', 'index.html', $this->noFixed()));
	}

	// -- TTL -----------------------------------------------------------------

	public function testExpiredSessionIsDroppedOnAccessAndSwept(): void {
		$store = $this->store(new PreviewSessionLimits(ttlSeconds: 100));
		$this->now = 1000;
		$id = $store->create('alice');
		$this->publish($store, $id, 0, 1, [['path' => 'index.html', 'bytes' => 'x']], [], []);

		// Within TTL: still served.
		$this->now = 1050;
		self::assertNotNull($store->resolve($id, 'index.html', $this->noFixed()));

		// Past TTL: opportunistically dropped on access.
		$this->now = 2000;
		self::assertNull($store->resolve($id, 'index.html', $this->noFixed()));
		self::assertFalse($store->exists($id));
	}

	public function testSweepExpiredRemovesIdleSessions(): void {
		$store = $this->store(new PreviewSessionLimits(ttlSeconds: 100));
		$this->now = 1000;
		$stale = $store->create('alice');
		$this->now = 1900;
		$fresh = $store->create('bob');

		$this->now = 1950; // stale is 950s idle, fresh is 50s idle
		self::assertSame(1, $store->sweepExpired());
		self::assertFalse($store->exists($stale));
		self::assertTrue($store->exists($fresh));
	}

	// -- Helpers -------------------------------------------------------------

	/**
	 * @param list<array{path:string,bytes:string}> $writes
	 * @param array<string,string> $assetRefs
	 * @param array<string,string> $fixedRefs
	 * @param list<string> $deletes
	 */
	private function publish(
		PreviewSessionStore $store,
		string $id,
		int $base,
		int $next,
		array $writes,
		array $assetRefs,
		array $fixedRefs,
		array $deletes = [],
	): void {
		$result = $store->applyRevision($id, [
			'baseRevision' => $base,
			'nextRevision' => $next,
			'writes' => $writes,
			'deletes' => $deletes,
			'assetRefs' => $assetRefs,
			'fixedRefs' => $fixedRefs,
		], $this->noFixed());
		self::assertSame(200, $result['status'], 'publish helper expects success');
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			@unlink($dir);
			return;
		}
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$this->removeTree($dir . '/' . $item);
		}
		@rmdir($dir);
	}

	/** Total physical bytes stored under a directory tree. */
	private function diskBytes(string $dir): int {
		$total = 0;
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			$total += is_dir($path) ? $this->diskBytes($path) : (int)filesize($path);
		}
		return $total;
	}
}
