<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\PreviewSnapshotLimits;
use OCA\ExeLearning\Service\Preview\SnapshotArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class SnapshotArchiveTest extends TestCase {
	/** @var list<string> */
	private array $paths = [];

	protected function tearDown(): void {
		foreach (array_reverse($this->paths) as $path) {
			$this->removeTree($path);
		}
	}

	public function testInspectAndExtractNestedArchiveWithDirectories(): void {
		$path = $this->zip([
			'index.html' => '<html>ok</html>',
			'assets/app.js' => 'console.log("ok")',
		], ['assets/']);
		$target = $this->tempDir();
		$zip = $this->open($path);

		SnapshotArchive::inspect($zip, new PreviewSnapshotLimits());
		$written = SnapshotArchive::extract($zip, $target, new PreviewSnapshotLimits());
		$zip->close();

		self::assertSame(
			strlen('<html>ok</html>') + strlen('console.log("ok")'),
			$written,
		);
		self::assertSame('<html>ok</html>', file_get_contents($target . '/index.html'));
		self::assertSame('console.log("ok")', file_get_contents($target . '/assets/app.js'));
	}

	public function testInspectRejectsUnixSymlinkEntry(): void {
		$path = $this->zip([
			'index.html' => '<html></html>',
			'escape-link' => '../../outside',
		]);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path) === true);
		self::assertTrue($zip->setExternalAttributesName(
			'escape-link',
			ZipArchive::OPSYS_UNIX,
			(0120000 | 0777) << 16,
		));
		$zip->close();
		$zip = $this->open($path);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('symbolic link');
		try {
			SnapshotArchive::inspect($zip, new PreviewSnapshotLimits());
		} finally {
			$zip->close();
		}
	}

	/**
	 * @dataProvider unsafeEntryProvider
	 */
	public function testInspectRejectsUnsafeEntryNames(string $entry): void {
		$path = $this->zip([
			'index.html' => '<html></html>',
			$entry => 'bad',
		]);
		$zip = $this->open($path);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unsafe path');
		try {
			SnapshotArchive::inspect($zip, new PreviewSnapshotLimits());
		} finally {
			$zip->close();
		}
	}

	/** @return iterable<string,array{string}> */
	public static function unsafeEntryProvider(): iterable {
		yield 'windows separator' => ['folder\\escape.txt'];
		yield 'dot segment' => ['folder/./escape.txt'];
		yield 'parent segment' => ['folder/../escape.txt'];
		yield 'double slash' => ['folder//escape.txt'];
	}

	public function testInspectRejectsDeclaredByteTotalBeforeExtraction(): void {
		$path = $this->zip([
			'index.html' => str_repeat('x', 32),
		]);
		$zip = $this->open($path);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too large');
		try {
			SnapshotArchive::inspect($zip, new PreviewSnapshotLimits(maxBytesPerSnapshot: 8));
		} finally {
			$zip->close();
		}
	}

	public function testExtractFailsWhenTargetDirectoryCannotBeCreated(): void {
		$path = $this->zip(['index.html' => 'ok']);
		$zip = $this->open($path);
		$target = $this->tempFile('not-a-directory');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not create a preview directory');
		try {
			SnapshotArchive::extract($zip, $target, new PreviewSnapshotLimits());
		} finally {
			$zip->close();
		}
	}

	public function testExtractFailsWhenDestinationCannotBeOpened(): void {
		$path = $this->zip(['index.html' => 'ok']);
		$zip = $this->open($path);
		$target = $this->tempDir();
		mkdir($target . '/index.html', 0700);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not write the preview snapshot');
		try {
			SnapshotArchive::extract($zip, $target, new PreviewSnapshotLimits());
		} finally {
			$zip->close();
		}
	}

	/**
	 * @param array<string,string> $entries
	 * @param list<string> $directories
	 */
	private function zip(array $entries, array $directories = []): string {
		$path = tempnam(sys_get_temp_dir(), 'exe-archive-');
		self::assertNotFalse($path);
		$this->paths[] = $path;
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
		foreach ($directories as $directory) {
			$zip->addEmptyDir($directory);
		}
		foreach ($entries as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();
		return $path;
	}

	private function open(string $path): ZipArchive {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path) === true);
		return $zip;
	}

	private function tempDir(): string {
		$path = sys_get_temp_dir() . '/exe-archive-dir-' . bin2hex(random_bytes(6));
		self::assertTrue(mkdir($path, 0700, true));
		$this->paths[] = $path;
		return $path;
	}

	private function tempFile(string $contents): string {
		$path = tempnam(sys_get_temp_dir(), 'exe-archive-file-');
		self::assertNotFalse($path);
		file_put_contents($path, $contents);
		$this->paths[] = $path;
		return $path;
	}

	private function removeTree(string $path): void {
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$this->removeTree($path . '/' . $entry);
		}
		@rmdir($path);
	}
}
