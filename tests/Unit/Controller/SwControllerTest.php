<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\SwController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class SwControllerTest extends TestCase {
	public function testServesServiceWorkerWithRequiredHeaders(): void {
		$controller = new SwController('exelearning', $this->createMock(IRequest::class));

		$response = $controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsString($response->getData());
		self::assertStringContainsString('addEventListener', $response->getData());
		self::assertSame('text/javascript; charset=utf-8', $response->getHeaders()['Content-Type']);
		self::assertSame('/apps/exelearning/', $response->getHeaders()['Service-Worker-Allowed']);
		self::assertSame('public, max-age=300', $response->getHeaders()['Cache-Control']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
	}
}
