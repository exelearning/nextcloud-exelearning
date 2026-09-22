<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Service\PermissionService;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

final class PermissionServiceTest extends TestCase {
	private PermissionService $service;

	protected function setUp(): void {
		$this->service = new PermissionService();
	}

	public function testRejectsNonFileNodes(): void {
		$node = $this->createMock(Node::class);

		self::assertFalse($this->service->isElpxFile($node));
	}

	public function testAcceptsSupportedExtensionsCaseInsensitively(): void {
		self::assertTrue($this->service->isElpxFile($this->file('Lesson.ELPX', 'application/zip')));
		self::assertTrue($this->service->isElpxFile($this->file('legacy.ElP', 'application/octet-stream')));
	}

	public function testAcceptsVendorMimeWithoutKnownExtension(): void {
		$file = $this->file('lesson.bin', Application::PRIMARY_MIME_TYPE);

		self::assertTrue($this->service->isElpxFile($file));
	}

	public function testRejectsGenericArchiveMimesWithoutSupportedExtension(): void {
		self::assertFalse($this->service->isElpxFile($this->file('archive.zip', 'application/zip')));
		self::assertFalse($this->service->isElpxFile($this->file('archive.bin', 'application/octet-stream')));
	}

	public function testReadPermissionUsesTheReadBit(): void {
		$readable = $this->file('lesson.elpx', Application::PRIMARY_MIME_TYPE, Constants::PERMISSION_READ);
		$unreadable = $this->file('lesson.elpx', Application::PRIMARY_MIME_TYPE, 0);

		self::assertTrue($this->service->isReadable($readable));
		self::assertFalse($this->service->isReadable($unreadable));
	}

	public function testPackageSizeLimitIsInclusive(): void {
		$atLimit = $this->file(
			'lesson.elpx',
			Application::PRIMARY_MIME_TYPE,
			Constants::PERMISSION_READ,
			PermissionService::MAX_PACKAGE_SIZE_BYTES,
		);
		$overLimit = $this->file(
			'lesson.elpx',
			Application::PRIMARY_MIME_TYPE,
			Constants::PERMISSION_READ,
			PermissionService::MAX_PACKAGE_SIZE_BYTES + 1,
		);

		self::assertTrue($this->service->isWithinSizeLimit($atLimit));
		self::assertFalse($this->service->isWithinSizeLimit($overLimit));
	}

	private function file(
		string $name,
		string $mime,
		int $permissions = Constants::PERMISSION_READ,
		int $size = 1024,
	): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getPermissions')->willReturn($permissions);
		$file->method('getSize')->willReturn($size);
		return $file;
	}
}
