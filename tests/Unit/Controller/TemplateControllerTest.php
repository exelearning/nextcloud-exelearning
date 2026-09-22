<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\TemplateController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TemplateControllerTest extends TestCase {
	public function testRequiresAuthentication(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new TemplateController(
			'exelearning',
			$this->createMock(IRequest::class),
			$session,
		);

		$response = $controller->blank();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testStreamsBundledBlankPackage(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new TemplateController(
			'exelearning',
			$this->createMock(IRequest::class),
			$session,
		);

		$response = $controller->blank();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsResource($response->getStream());
		self::assertSame('application/vnd.exelearning.elpx', $response->getHeaders()['Content-Type']);
		self::assertGreaterThan(0, (int)$response->getHeaders()['Content-Length']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		self::assertSame('private, max-age=300', $response->getHeaders()['Cache-Control']);
	}
}
