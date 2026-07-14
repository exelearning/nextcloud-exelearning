<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\PreviewSnapshotStore;
use OCA\ExeLearning\Service\ZipEntryService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PreviewSnapshotStoreTest extends TestCase {
	private string $root;
	/** @var list<string> */
	private array $zipFiles = [];

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-preview-test-' . bin2hex(random_bytes(8));
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
		foreach ($this->zipFiles as $file) {
			@unlink($file);
		}
	}

	public function testCreatesReplacesServesAndDeletesCompleteSnapshot(): void {
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		$first = $this->zip(['index.html' => 'first']);
		$id = $store->replace('user', '42', $first);
		self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
		self::assertSame('first', $store->get($id, 'index.html')['bytes'] ?? null);

		$second = $this->zip(['index.html' => 'second', 'app.js' => 'run()']);
		self::assertSame($id, $store->replace('user', '42', $second, $id));
		self::assertSame('second', $store->get($id, 'index.html')['bytes'] ?? null);
		self::assertSame('application/javascript; charset=utf-8', $store->get($id, 'app.js')['mime'] ?? null);
		self::assertTrue($store->delete('user', '42', $id));
		self::assertNull($store->get($id, 'index.html'));
	}

	public function testRejectsUnsafeArchivePaths(): void {
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		$this->expectException(\InvalidArgumentException::class);
		$store->replace('user', '42', $this->zip(['../index.html' => 'escape']));
	}

	public function testRejectsMissingIndexAndReservedPaths(): void {
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		try {
			$store->replace('user', '42', $this->zip(['other.html' => 'missing']));
			self::fail('A snapshot without index.html was accepted');
		} catch (\InvalidArgumentException) {
			self::assertDirectoryDoesNotExist($this->root . '/.staging');
		}

		$this->expectException(\InvalidArgumentException::class);
		$store->replace('user', '42', $this->zip(['index.html' => 'ok', '.metadata.json' => 'override']));
	}

	public function testEnforcesOwnerAndProjectScope(): void {
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		$id = $store->replace('user', '42', $this->zip(['index.html' => 'ok']));
		try {
			$store->replace('other-user', '42', $this->zip(['index.html' => 'no']), $id);
			self::fail('A cross-owner replacement was accepted');
		} catch (\UnexpectedValueException) {
			self::assertSame('ok', $store->get($id, 'index.html')['bytes'] ?? null);
		}

		$this->expectException(\UnexpectedValueException::class);
		$store->delete('user', 'different-project', $id);
	}

	public function testRejectsTraversalAndDoesNotExposeMetadata(): void {
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		$id = $store->replace('user', '42', $this->zip(['index.html' => 'ok']));
		self::assertNull($store->get($id, '%2e%2e/index.html'));
		self::assertNull($store->get($id, '.metadata.json'));
	}

	public function testExpiresIdleCapabilities(): void {
		$now = 1000;
		$store = new PreviewSnapshotStore($this->root, new ZipEntryService(), 10, static function () use (&$now): int {
			return $now;
		});
		$id = $store->replace('user', '42', $this->zip(['index.html' => 'ok']));
		$now = 1011;
		self::assertNull($store->get($id, 'index.html'));
		self::assertDirectoryDoesNotExist($this->root . '/' . $id);
	}

	/** @param array<string,string> $files */
	private function zip(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'exe-preview-');
		self::assertIsString($path);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($files as $name => $contents) {
			self::assertTrue($zip->addFromString($name, $contents));
		}
		$zip->close();
		$this->zipFiles[] = $path;
		return $path;
	}

	private function removeTree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (new \FilesystemIterator($path) as $entry) {
			$entry->isDir() ? $this->removeTree($entry->getPathname()) : @unlink($entry->getPathname());
		}
		@rmdir($path);
	}
}
