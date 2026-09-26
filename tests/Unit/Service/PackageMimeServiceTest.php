<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\PackageMimeService;
use PHPUnit\Framework\TestCase;

final class PackageMimeServiceTest extends TestCase {
	private PackageMimeService $service;

	protected function setUp(): void {
		$this->service = new PackageMimeService();
	}

	public function testDetectsKnownExtensions(): void {
		self::assertSame('text/html; charset=utf-8', $this->service->detect('index.html'));
		self::assertSame('image/svg+xml', $this->service->detect('img/logo.svg'));
		self::assertSame('text/javascript; charset=utf-8', $this->service->detect('a.js'));
		self::assertSame('application/xml; charset=utf-8', $this->service->detect('content.xml'));
	}

	public function testUnknownExtensionFallsBack(): void {
		self::assertSame('application/octet-stream', $this->service->detect('weird.qux'));
		self::assertSame('application/octet-stream', $this->service->detect('README'));
	}

	public function testHtmlDocumentsGetTheEnginePolicy(): void {
		self::assertTrue($this->service->isHtmlDocument('text/html; charset=utf-8'));
		self::assertTrue($this->service->isHtmlDocument('application/xhtml+xml'));
		self::assertFalse($this->service->isHtmlDocument('image/svg+xml'));
		self::assertFalse($this->service->isHtmlDocument('text/css'));
	}

	public function testXmlDocumentsAreLocked(): void {
		self::assertTrue($this->service->isLockedXml('image/svg+xml'));
		self::assertTrue($this->service->isLockedXml('application/xml; charset=utf-8'));
		self::assertFalse($this->service->isLockedXml('text/html'));
		self::assertFalse($this->service->isLockedXml('image/png'));
	}
}
