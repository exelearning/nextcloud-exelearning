<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\EditorHtmlService;
use PHPUnit\Framework\TestCase;

final class EditorHtmlServiceTest extends TestCase {
	private EditorHtmlService $service;

	protected function setUp(): void {
		$this->service = new EditorHtmlService();
	}

	public function testInjectsBaseConfigResilienceAndSaveBridge(): void {
		$html = '<html><head><title>Editor</title></head><body><main>Editor</main></body></html>';

		$result = $this->service->prepare(
			$html,
			'https://cloud.example/custom_apps/exelearning/js/editor/',
		);

		self::assertStringContainsString(
			'<head><base href="https://cloud.example/custom_apps/exelearning/js/editor/">',
			$result,
		);
		self::assertStringContainsString('window.__EXE_EMBEDDING_CONFIG__', $result);
		self::assertStringContainsString('navigator.serviceWorker.register', $result);
		self::assertStringContainsString("type: 'REQUEST_SAVE'", $result);
		self::assertStringContainsString('SharedExporters.createExporter', $result);
		self::assertStringContainsString('</script></body>', $result);
	}

	public function testEscapesBaseHrefAndStillAddsBridgeWithoutHead(): void {
		$html = '<html><body>Editor</body></html>';

		$result = $this->service->prepare(
			$html,
			'https://cloud.example/apps/exelearning/js/editor/?a=1&b="2"',
		);

		self::assertStringNotContainsString('<base href=', $result);
		self::assertStringNotContainsString('window.__EXE_EMBEDDING_CONFIG__', $result);
		self::assertStringContainsString("type: 'REQUEST_SAVE'", $result);
	}
}
