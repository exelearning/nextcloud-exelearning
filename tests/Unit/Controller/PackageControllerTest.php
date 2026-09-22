<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\PackageController;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PackageControllerTest extends TestCase {
	private IUserSession $userSession;
	private ElpxPackageService $packages;
	private PackageController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->controller = new PackageController(
			'exelearning',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->packages,
		);
	}

	public function testByFileIdRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->byFileId(42);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testByFileIdMapsLookupErrors(): void {
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

	public function testByFileIdStreamsPackageWithHeaders(): void {
		$this->authenticate();
		$file = $this->packageFile('Lesson ü.elpx', 'package-bytes');
		$this->packages->method('getForUserById')->with('alice', 42)->willReturn($file);

		$response = $this->controller->byFileId(42);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsResource($response->getStream());
		self::assertSame('application/vnd.exelearning.elpx', $response->getHeaders()['Content-Type']);
		self::assertSame('13', $response->getHeaders()['Content-Length']);
		self::assertSame('inline; filename="Lesson%20%C3%BC.elpx"', $response->getHeaders()['Content-Disposition']);
		self::assertSame('private, no-cache, no-store, must-revalidate', $response->getHeaders()['Cache-Control']);
	}

	public function testByPathRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->byPath('lesson.elpx')->getStatus());
	}

	public function testByPathRejectsEmptyAndNulPaths(): void {
		$this->authenticate();

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->byPath('')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->byPath("folder/\0file.elpx")->getStatus());
	}

	public function testByPathMapsLookupErrors(): void {
		$this->authenticate();
		$this->packages->expects(self::exactly(2))
			->method('getForUserByPath')
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->byPath('missing.elpx')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->byPath('denied.elpx')->getStatus());
	}

	public function testByPathStreamsResolvedPackage(): void {
		$this->authenticate();
		$file = $this->packageFile('lesson.elpx', 'abc');
		$this->packages->method('getForUserByPath')->with('alice', 'folder/lesson.elpx')->willReturn($file);

		$response = $this->controller->byPath('folder/lesson.elpx');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('3', $response->getHeaders()['Content-Length']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
	}

	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function packageFile(string $name, string $contents): File {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $contents);
		rewind($stream);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(strlen($contents));
		$file->method('fopen')->with('rb')->willReturn($stream);
		return $file;
	}
}
