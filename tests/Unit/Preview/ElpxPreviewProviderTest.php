<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Preview;

use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCA\ExeLearning\Service\PermissionService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Image;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ElpxPreviewProviderTest extends TestCase {
	private ZipEntryService $zipEntries;
	private PermissionService $permissions;
	private LoggerInterface $logger;
	private ElpxPreviewProvider $provider;

	protected function setUp(): void {
		Image::$lastInstance = null;
		$this->zipEntries = $this->createMock(ZipEntryService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = new ElpxPreviewProvider(
			$this->zipEntries,
			$this->permissions,
			$this->logger,
		);
	}

	public function testReturnsTheRegisteredMimeRegex(): void {
		self::assertSame(ElpxPreviewProvider::MIME_REGEX, $this->provider->getMimeType());
	}

	public function testDelegatesRealFilesToPermissionService(): void {
		$file = $this->createMock(File::class);
		$this->permissions->expects(self::once())
			->method('isElpxFile')
			->with($file)
			->willReturn(true);

		self::assertTrue($this->provider->isAvailable($file));
	}

	public function testFallbackFileInfoUsesSupportedExtensions(): void {
		$elpx = $this->createMock(FileInfo::class);
		$elpx->method('getName')->willReturn('Lesson.ELPX');
		$legacy = $this->createMock(FileInfo::class);
		$legacy->method('getName')->willReturn('Legacy.ElP');
		$zip = $this->createMock(FileInfo::class);
		$zip->method('getName')->willReturn('archive.zip');

		self::assertTrue($this->provider->isAvailable($elpx));
		self::assertTrue($this->provider->isAvailable($legacy));
		self::assertFalse($this->provider->isAvailable($zip));
	}

	public function testBuildsThumbnailFromPackageScreenshot(): void {
		$file = $this->createMock(File::class);
		$this->zipEntries->expects(self::once())
			->method('readEntry')
			->with($file, 'screenshot.png')
			->willReturn('valid-image-bytes');

		$image = $this->provider->getThumbnail($file, 320, 180);

		self::assertSame(Image::$lastInstance, $image);
		self::assertSame('valid-image-bytes', Image::$lastInstance?->data);
		self::assertSame([320, 180], Image::$lastInstance?->scaledTo);
	}

	public function testFallsBackWhenScreenshotIsMissingOrInvalid(): void {
		$file = $this->createMock(File::class);
		$this->zipEntries->expects(self::exactly(2))
			->method('readEntry')
			->willReturnOnConsecutiveCalls(null, 'invalid');

		$missing = $this->provider->getThumbnail($file, 200, 100);
		self::assertNotNull($missing);
		self::assertSame([200, 100], Image::$lastInstance?->scaledTo);

		$invalid = $this->provider->getThumbnail($file, 120, 80);
		self::assertNotNull($invalid);
		self::assertSame([120, 80], Image::$lastInstance?->scaledTo);
	}

	public function testLogsPackageReadErrorsAndUsesFallback(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('broken.elpx');
		$error = new RuntimeException('broken archive');
		$this->zipEntries->method('readEntry')->willThrowException($error);
		$this->logger->expects(self::once())
			->method('debug')
			->with(
				'eXeLearning preview failed',
				self::callback(static function (array $context) use ($error): bool {
					return ($context['app'] ?? null) === 'exelearning'
						&& ($context['exception'] ?? null) === $error
						&& ($context['file'] ?? null) === 'broken.elpx';
				}),
			);

		self::assertNotNull($this->provider->getThumbnail($file, 64, 64));
	}

	public function testLegacyCroppedThumbnailApiReturnsNull(): void {
		$file = $this->createMock(File::class);

		self::assertNull($this->provider->getCroppedThumbnail($file, 100, 100, true));
	}
}
