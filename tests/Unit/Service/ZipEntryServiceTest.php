<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ZipEntryService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZipEntryService::normalizeEntry}. The reading methods need a
 * Nextcloud File handle which is too heavy to fake here — they are exercised
 * by integration tests that run against a real server.
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
		if (!class_exists('OCP\\Files\\File')) {
			eval('namespace OCP\\Files; class File {}');
		}

		$archivePath = tempnam(sys_get_temp_dir(), 'elpx_test_');
		self::assertNotFalse($archivePath);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($archivePath, \ZipArchive::OVERWRITE) === true);
		$zip->addFromString('index.html', '<h1>Playground</h1>');
		$zip->close();
		$archive = file_get_contents($archivePath);
		@unlink($archivePath);
		self::assertIsString($archive);

		$file = new class($archive) extends \OCP\Files\File {
			public function __construct(
				private readonly string $archive,
			) {
			}

			public function getStorage(): object {
				return new class {
					public function getLocalFile(string $path): string {
						return '/virtual/php-wasm/package.elpx';
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

		self::assertSame('<h1>Playground</h1>', $this->service->readEntry($file, 'index.html'));
	}
}
