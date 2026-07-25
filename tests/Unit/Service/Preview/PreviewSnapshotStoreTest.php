<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\PreviewSnapshotLimits;
use OCA\ExeLearning\Service\Preview\PreviewSnapshotStore;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see PreviewSnapshotStore} — capability lifecycle, owner scoping,
 * the archive guards that stand between untrusted author content and the
 * filesystem, and the DoS bounds that keep one author from filling a shared
 * instance.
 */
final class PreviewSnapshotStoreTest extends TestCase {
	private string $root;
	private int $time = 1000000;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-snap-' . bin2hex(random_bytes(6));
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
	}

	private function store(?PreviewSnapshotLimits $limits = null): PreviewSnapshotStore {
		return new PreviewSnapshotStore(
			$this->root,
			$limits ?? new PreviewSnapshotLimits(),
			fn (): int => $this->time,
		);
	}

	/**
	 * Build a snapshot ZIP on disk from a path => contents map.
	 *
	 * @param array<string,string> $entries
	 */
	private function zip(array $entries): string {
		$path = sys_get_temp_dir() . '/exe-snap-zip-' . bin2hex(random_bytes(6)) . '.zip';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($entries as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();
		return $path;
	}

	// =========================================================================
	// Publication
	// =========================================================================

	public function testReplaceStoresTheSnapshot(): void {
		$store = $this->store();
		$result = $store->replace('alice', $this->zip([
			'index.html' => 'hello',
			'assets/app.js' => 'run()',
		]));

		self::assertArrayHasKey('previewId', $result);
		self::assertSame('hello', $store->resolve($result['previewId'], 'index.html')['bytes']);
		self::assertSame('alice', $store->ownerOf($result['previewId']));
	}

	public function testReplaceSwapsTheWholeTree(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip([
			'index.html' => 'first',
			'stale.html' => 'gone next time',
		]))['previewId'];

		$second = $store->replace('alice', $this->zip(['index.html' => 'second']), $id);

		self::assertSame($id, $second['previewId']);
		self::assertSame('second', $store->resolve($id, 'index.html')['bytes']);
		self::assertNull($store->resolve($id, 'stale.html'));
	}

	public function testReplaceRefusesACapabilityOwnedByAnotherUser(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip(['index.html' => 'hers']))['previewId'];

		$result = $store->replace('mallory', $this->zip(['index.html' => 'theirs']), $id);

		self::assertSame(403, $result['status']);
		self::assertSame('hers', $store->resolve($id, 'index.html')['bytes']);
	}

	public function testReplaceRefusesAnUnknownCapability(): void {
		$result = $this->store()->replace(
			'alice',
			$this->zip(['index.html' => 'ok']),
			'ffffffff-ffff-4fff-bfff-ffffffffffff',
		);

		self::assertSame(404, $result['status']);
	}

	public function testDeleteIsOwnerScoped(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip(['index.html' => 'ok']))['previewId'];

		self::assertFalse($store->delete($id, 'mallory'));
		self::assertNotNull($store->resolve($id, 'index.html'));

		self::assertTrue($store->delete($id, 'alice'));
		self::assertNull($store->resolve($id, 'index.html'));
	}

	// =========================================================================
	// Archive guards
	// =========================================================================

	public function testArchiveMustCarryAnIndex(): void {
		$result = $this->store()->replace('alice', $this->zip(['page.html' => 'orphan']));

		self::assertSame(400, $result['status']);
		self::assertStringContainsString('index.html', $result['error']);
	}

	public function testTraversalIsRefusedBeforeAnythingIsWritten(): void {
		$result = $this->store()->replace('alice', $this->zip([
			'index.html' => 'ok',
			'../escape.html' => 'nope',
		]));

		self::assertSame(400, $result['status']);
		self::assertFileDoesNotExist(dirname($this->root) . '/escape.html');
	}

	public function testAbsolutePathIsRefused(): void {
		$result = $this->store()->replace('alice', $this->zip([
			'index.html' => 'ok',
			'/etc/passwd' => 'nope',
		]));

		self::assertSame(400, $result['status']);
	}

	public function testTheEntryCountGuardFailsClosed(): void {
		$result = $this->store(new PreviewSnapshotLimits(maxFilesPerSnapshot: 1))->replace('alice', $this->zip([
			'index.html' => 'a',
			'b.html' => 'b',
		]));

		self::assertSame(400, $result['status']);
	}

	public function testTheByteGuardMeasuresRealDecompressedBytes(): void {
		$result = $this->store(new PreviewSnapshotLimits(maxBytesPerSnapshot: 8))->replace('alice', $this->zip([
			'index.html' => str_repeat('x', 64),
		]));

		self::assertSame(400, $result['status']);
	}

	public function testARejectedUploadLeavesNoStagingBehind(): void {
		$this->store()->replace('alice', $this->zip(['page.html' => 'no index']));

		$leftovers = array_filter(
			scandir($this->root) ?: [],
			static fn (string $entry): bool => str_starts_with($entry, '.staging-'),
		);
		self::assertSame([], array_values($leftovers));
	}

	// =========================================================================
	// TTL and budgets
	// =========================================================================

	public function testIdleSnapshotsExpireAndAreSwept(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip(['index.html' => 'ok']))['previewId'];

		$this->time += (new PreviewSnapshotLimits())->ttlSeconds + 60;

		self::assertNull($store->resolve($id, 'index.html'));
		self::assertSame(1, $store->sweepExpired());
	}

	public function testServingPushesTheIdleClockBack(): void {
		$store = $this->store();
		$ttl = (new PreviewSnapshotLimits())->ttlSeconds;
		$id = $store->replace('alice', $this->zip(['index.html' => 'ok']))['previewId'];

		// Just short of the TTL: resolving must keep it alive past the original
		// deadline, so a preview in use never expires under the author.
		$this->time += $ttl - 60;
		self::assertNotNull($store->resolve($id, 'index.html'));

		$this->time += 120;
		self::assertNotNull($store->resolve($id, 'index.html'));
	}

	/**
	 * A shared instance must not let one author accumulate capabilities: past
	 * the per-user cap the least-recently-used one is evicted.
	 */
	public function testThePerUserCapEvictsTheLeastRecentlyUsed(): void {
		$store = $this->store(new PreviewSnapshotLimits(maxSnapshotsPerUser: 2));
		$first = $store->replace('alice', $this->zip(['index.html' => '1']))['previewId'];
		$this->time += 10;
		$second = $store->replace('alice', $this->zip(['index.html' => '2']))['previewId'];
		$this->time += 10;

		$third = $store->replace('alice', $this->zip(['index.html' => '3']))['previewId'];

		self::assertNull($store->resolve($first, 'index.html'), 'the LRU snapshot must be evicted');
		self::assertNotNull($store->resolve($second, 'index.html'));
		self::assertNotNull($store->resolve($third, 'index.html'));
	}

	/** Another user's snapshots are never touched by this user's cap. */
	public function testThePerUserCapIsScopedToItsOwner(): void {
		$store = $this->store(new PreviewSnapshotLimits(maxSnapshotsPerUser: 1));
		$hers = $store->replace('alice', $this->zip(['index.html' => 'hers']))['previewId'];
		$this->time += 10;

		$store->replace('bob', $this->zip(['index.html' => 'his']));

		self::assertNotNull($store->resolve($hers, 'index.html'));
	}

	public function testTheGlobalBudgetRefusesWhatItCannotFit(): void {
		$store = $this->store(new PreviewSnapshotLimits(globalMaxBytes: 4));

		$result = $store->replace('alice', $this->zip(['index.html' => str_repeat('x', 64)]));

		self::assertSame(507, $result['status']);
	}

	// =========================================================================
	// Serving
	// =========================================================================

	public function testResolveRejectsTraversalOutOfTheSnapshot(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip(['index.html' => 'ok']))['previewId'];

		self::assertNull($store->resolve($id, '../meta.json'));
		self::assertNull($store->resolve($id, '%2e%2e%2fmeta.json'));
	}

	/**
	 * The store's own metadata lives outside the extracted tree, so an author
	 * path can never name it — there are no reserved names to police.
	 */
	public function testStoreMetadataIsNotReachableFromTheServingRoute(): void {
		$store = $this->store();
		$id = $store->replace('alice', $this->zip([
			'index.html' => 'ok',
			'meta.json' => '{"ownerUserId":"mallory"}',
		]))['previewId'];

		// The author's own meta.json is served as ordinary content...
		$served = $store->resolve($id, 'meta.json');
		self::assertSame('{"ownerUserId":"mallory"}', file_get_contents($served['filePath']));
		// ...while the store keeps reading the real owner from outside the tree.
		self::assertSame('alice', $store->ownerOf($id));
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
}
