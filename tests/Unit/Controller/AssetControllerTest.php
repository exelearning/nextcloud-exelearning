<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\AssetController;
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

final class AssetControllerTest extends TestCase {
	private IUserSession $userSession;
	private ElpxPackageService $packages;
	private ZipEntryService $zipEntries;
	private AssetController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->zipEntries = $this->createMock(ZipEntryService::class);
		$this->controller = new AssetController(
			'exelearning',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->packages,
			$this->zipEntries,
		);
	}

	public function testRejectsAnonymousRequests(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->fetch('42', 'index.html');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testRejectsInvalidSessionId(): void {
		$this->authenticate();

		$response = $this->controller->fetch('0', 'index.html');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Invalid session'], $response->getData());
	}

	public function testRejectsUnsafeEntryPath(): void {
		$this->authenticate();
		$this->zipEntries->method('normalizeEntry')->with('../secret')->willReturn(null);

		$response = $this->controller->fetch('42', '../secret');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Unsafe path'], $response->getData());
	}

	public function testMapsMissingAndForbiddenPackagesToHttpErrors(): void {
		$user = $this->authenticate();
		$this->zipEntries->method('normalizeEntry')->willReturnArgument(0);
		$this->packages->expects(self::exactly(2))
			->method('getForUserById')
			->with($user->getUID(), 42)
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('No read permission')),
			);

		$missing = $this->controller->fetch('42', 'index.html');
		$forbidden = $this->controller->fetch('42', 'index.html');

		self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		self::assertSame(['error' => 'File not found'], $missing->getData());
		self::assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		self::assertSame(['error' => 'No read permission'], $forbidden->getData());
	}

	public function testReturnsNotFoundWhenArchiveEntryIsMissing(): void {
		$this->authenticate();
		$file = $this->createMock(File::class);
		$this->zipEntries->method('normalizeEntry')->willReturn('missing.css');
		$this->packages->method('getForUserById')->willReturn($file);
		$this->zipEntries->method('readEntry')->with($file, 'missing.css')->willReturn(null);

		$response = $this->controller->fetch('42', 'missing.css');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Entry not found'], $response->getData());
	}

	public function testServesKnownMimeWithSecurityHeaders(): void {
		$this->authenticate();
		$file = $this->createMock(File::class);
		$this->zipEntries->method('normalizeEntry')->willReturn('index.html');
		$this->packages->method('getForUserById')->willReturn($file);
		$this->zipEntries->method('readEntry')->willReturn('<html></html>');

		$response = $this->controller->fetch('42', 'index.html');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('<html></html>', $response->getData());
		self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		self::assertStringContainsString("frame-ancestors 'self'", $response->getHeaders()['Content-Security-Policy']);
		self::assertSame('private, max-age=300', $response->getHeaders()['Cache-Control']);
	}

	public function testUsesOctetStreamForUnknownExtension(): void {
		$this->authenticate();
		$file = $this->createMock(File::class);
		$this->zipEntries->method('normalizeEntry')->willReturn('data/custom.bin');
		$this->packages->method('getForUserById')->willReturn($file);
		$this->zipEntries->method('readEntry')->willReturn('bytes');

		$response = $this->controller->fetch('42', 'data/custom.bin');

		self::assertSame('application/octet-stream', $response->getHeaders()['Content-Type']);
	}

	private function authenticate(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}
}
