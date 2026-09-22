<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\AppInfo;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use PHPUnit\Framework\TestCase;

final class ApplicationBootstrapTest extends TestCase {
	protected function setUp(): void {
		Util::reset();
	}

	public function testRegistersThePreviewProvider(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerPreviewProvider')
			->with(ElpxPreviewProvider::class, ElpxPreviewProvider::MIME_REGEX);

		(new Application())->register($context);
	}

	public function testBootRegistersTheMainInitScript(): void {
		$context = $this->createMock(IBootContext::class);

		(new Application())->boot($context);

		self::assertSame(
			[[Application::APP_ID, 'exelearning-main']],
			Util::$initScripts,
		);
	}
}
