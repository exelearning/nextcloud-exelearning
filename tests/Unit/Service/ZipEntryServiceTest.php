<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ZipEntryService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZipEntryService}. `readEntry()` takes a Nextcloud `File`,
 * which is faked here via a minimal anonymous subclass so the local-path and
 * stream-fallback branches can be exercised without a real Nextcloud server
 * or storage backend.
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
	 * Fakes a Nextcloud `File` whose storage reports `$localPath` as the
	 * local path (any value `ZipEntryService` should NOT be able to open
	 * directly — e.g. a virtual path or an empty string) and whose
	 * `fopen()` streams the given archive bytes.
	 */
	private function createFakeFile(string $archive, string $localPath): \OCP\Files\File {
		if (!class_exists('OCP\\Files\\File')) {
			eval('namespace OCP\\Files; class File {}');
		}

		return new class($archive, $localPath) extends \OCP\Files\File {
			public function __construct(
				private readonly string $archive,
				private readonly string $localPath,
			) {
			}

			public function getStorage(): object {
				return new class($this->localPath) {
					public function __construct(
						private readonly string $localPath,
					) {
					}

					public function getLocalFile(string $path): string {
						return $this->localPath;
					}
				};
			}

			public function getInternalPath(): string {
				return 'files/package.elpx';
			}

			public function fopen(string $mode) {
				$stream = fopen('php://temp', 'w+b');
				fwrite($stream, $this->archive);
				rewind($stream);
				return $stream;
			}
		};
	}
}
