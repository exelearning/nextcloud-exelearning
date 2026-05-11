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
}
