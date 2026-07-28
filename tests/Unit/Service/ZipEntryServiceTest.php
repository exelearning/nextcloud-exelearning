<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ZipEntryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see ZipEntryService}. `readEntry()` takes a Nextcloud `File`,
 * which is mocked here (against the interface stub from
 * `bootstrap-standalone.php`) so the local-path and stream-fallback branches
 * can be exercised without a real Nextcloud server or storage backend.
 */
final class ZipEntryServiceTest extends TestCase {
	private ZipEntryService $service;

	protected function setUp(): void {
		$this->service = new ZipEntryService();
	}

	public function testNormalizesCanonicalEntries(): void {
		self::assertSame('index.html', $this->service->normalizeEntry('index.html'));
		self::assertSame('html/page.html', $this->service->normalizeEntry('html/page.html'));
	}

	public function testStripsLeadingSlashesAndBackslashes(): void {
		self::assertSame('html/page.html', $this->service->normalizeEntry('/html/page.html'));
		self::assertSame('html/page.html', $this->service->normalizeEntry('html\\page.html'));
	}

	public function testRejectsParentTraversal(): void {
		self::assertNull($this->service->normalizeEntry('../etc/passwd'));
		self::assertNull($this->service->normalizeEntry('html/../../etc'));
	}

	public function testRejectsCurrentDirSegmentToBeStrict(): void {
		// `.` segments are intentionally rejected — eXeLearning packages
		// never include them and they often indicate a sloppy ZIP writer.
		self::assertNull($this->service->normalizeEntry('html/./page.html'));
	}

	public function testRejectsEmptyAndNulTaintedPaths(): void {
		self::assertNull($this->service->normalizeEntry(''));
		self::assertNull($this->service->normalizeEntry("a\0b"));
	}

	public function testReadEntryFallsBackToStreamWhenLocalPathCannotBeOpened(): void {
		// Some virtual storage implementations (notably the php-wasm
		// Playground filesystem) expose a nominal local path that native
		// ZipArchive still cannot open.
		$archive = $this->createTestArchive('index.html', '<h1>Playground</h1>');
		$file = $this->createFakeFile($archive, '/virtual/php-wasm/package.elpx');

		self::assertSame('<h1>Playground</h1>', $this->service->readEntry($file, 'index.html'));
	}

	public function testReadEntryFallsBackToStreamWhenLocalFileIsEmptyString(): void {
		// S3/object storage and other non-local primary storages commonly
		// return an empty string — not false/null — from getLocalFile() when
		// there is no local path at all. That empty string must not be
		// handed to ZipArchive::open(), which would emit a PHP warning and
		// leave the entry unreadable.
		$archive = $this->createTestArchive('content.xml', '<content/>');
		$file = $this->createFakeFile($archive, '');

		self::assertSame('<content/>', $this->service->readEntry($file, 'content.xml'));
	}

	public function testReadsZeroByteEntriesAsEmptyString(): void {
		// A declared size of 0 means the bounded read asks for a single byte;
		// getFromName() must still report the empty entry as '' — not false.
		$archive = $this->createTestArchive('empty.txt', '');
		$file = $this->createFakeFile($archive, '');

		self::assertSame('', $this->service->readEntry($file, 'empty.txt'));
	}

	public function testStreamFallbackRejectsEntriesOverTheUncompressedSizeLimit(): void {
		// Both branches must enforce the same limits: an oversized entry has
		// to be rejected whether the package is opened from a local path or
		// through the File::fopen() fallback.
		$service = new ZipEntryService(maxUncompressedSizeBytes: 8);
		$archive = $this->createTestArchive('index.html', '<h1>Playground</h1>');
		$file = $this->createFakeFile($archive, '');

		$this->expectException(RuntimeException::class);
		$service->readEntry($file, 'index.html');
	}

	public function testLocalPathRejectsEntriesOverTheUncompressedSizeLimit(): void {
		$service = new ZipEntryService(maxUncompressedSizeBytes: 8);
		$archive = $this->createTestArchive('index.html', '<h1>Playground</h1>');
		$archivePath = tempnam(sys_get_temp_dir(), 'elpx_test_');
		self::assertNotFalse($archivePath);
		file_put_contents($archivePath, $archive);
		$file = $this->createFakeFile($archive, $archivePath);

		try {
			$this->expectException(RuntimeException::class);
			$service->readEntry($file, 'index.html');
		} finally {
			@unlink($archivePath);
		}
	}

	public function testStreamFallbackReturnsNullWhenArchiveHasTooManyEntries(): void {
		$service = new ZipEntryService(maxEntries: 1);
		$archivePath = tempnam(sys_get_temp_dir(), 'elpx_test_');
		self::assertNotFalse($archivePath);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($archivePath, \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('index.html', '<h1>Playground</h1>');
		$zip->addFromString('content.xml', '<content/>');
		$zip->close();
		$archive = file_get_contents($archivePath);
		@unlink($archivePath);
		self::assertIsString($archive);
		$file = $this->createFakeFile($archive, '');

		self::assertNull($service->readEntry($file, 'index.html'));
	}

	/**
	 * Builds a minimal in-memory ZIP archive and returns its raw bytes.
	 */
	private function createTestArchive(string $entryName, string $entryContents): string {
		$archivePath = tempnam(sys_get_temp_dir(), 'elpx_test_');
		self::assertNotFalse($archivePath);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($archivePath, \ZipArchive::OVERWRITE) === true);
		$zip->addFromString($entryName, $entryContents);
		$zip->close();
		$archive = file_get_contents($archivePath);
		@unlink($archivePath);
		self::assertIsString($archive);
		return $archive;
	}

	/**
	 * Mocks a Nextcloud `File` whose storage reports `$localPath` as the
	 * local path (any value `ZipEntryService` should NOT be able to open
	 * directly — e.g. a virtual path or an empty string) and whose
	 * `fopen()` streams the given archive bytes.
	 */
	private function createFakeFile(string $archive, string $localPath): \OCP\Files\File {
		$storage = new class($localPath) {
			public function __construct(
				private readonly string $localPath,
			) {
			}

			public function getLocalFile(string $path): string {
				return $this->localPath;
			}
		};

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/package.elpx');
		$file->method('fopen')->with('rb')->willReturnCallback(
			static function () use ($archive) {
				$stream = fopen('php://temp', 'w+b');
				fwrite($stream, $archive);
				rewind($stream);
				return $stream;
			},
		);

		return $file;
	}
}
