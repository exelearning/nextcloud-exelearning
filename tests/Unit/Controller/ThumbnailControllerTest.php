<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\ThumbnailController;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ThumbnailControllerTest extends TestCase {
	private IUserSession $userSession;
	private ElpxPackageService $packages;
	private ZipEntryService $zipEntries;
	private ThumbnailController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->zipEntries = $this->createMock(ZipEntryService::class);
		$this->controller = new ThumbnailController(
			'exelearning',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->packages,
			$this->zipEntries,
		);
	}

	public function testRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->byFileId(42)->getStatus());
	}

	public function testMapsPackageLookupErrors(): void {
		$this->authenticate();
		$this->packages->expects(self::exactly(2))
			->method('getForUserById')
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->byFileId(42)->getStatus());
		$forbidden = $this->controller->byFileId(42);
		self::assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		self::assertSame(['error' => 'denied'], $forbidden->getData());
	}

	public function testReturnsNotFoundWithoutScreenshot(): void {
		$this->authenticate();
		$file = $this->createMock(File::class);
		$this->packages->method('getForUserById')->willReturn($file);
		$this->zipEntries->method('readEntry')->with($file, 'screenshot.png')->willReturn(null);

		$response = $this->controller->byFileId(42);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'No screenshot'], $response->getData());
	}

	public function testReturnsPngWithPrivateCacheHeaders(): void {
		$this->authenticate();
		$file = $this->createMock(File::class);
		$this->packages->method('getForUserById')->willReturn($file);
		$this->zipEntries->method('readEntry')->willReturn('png-bytes');

		$response = $this->controller->byFileId(42);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('png-bytes', $response->getData());
		self::assertSame('image/png', $response->getHeaders()['Content-Type']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		self::assertSame('private, max-age=3600', $response->getHeaders()['Cache-Control']);
	}

	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}
}
