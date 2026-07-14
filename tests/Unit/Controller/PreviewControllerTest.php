<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\PreviewController;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\PreviewSnapshotStore;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PreviewControllerTest extends TestCase {
	private string $root;
	private string $zipPath;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-preview-controller-' . bin2hex(random_bytes(8));
		$this->zipPath = (string)tempnam(sys_get_temp_dir(), 'exe-preview-controller-');
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
		@unlink($this->zipPath);
	}

	public function testServesScriptableContentWithHardeningHeaders(): void {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		self::assertTrue($zip->addFromString('index.html', '<script>window.preview = true</script>'));
		$zip->close();

		$store = new PreviewSnapshotStore($this->root, new ZipEntryService());
		$id = $store->replace('user', '42', $this->zipPath);
		$response = $this->controller($store)->serveRoot($id);
		$headers = $response->getHeaders();

		self::assertSame(200, $response->getStatus());
		self::assertStringContainsString('<script>', $response->getData());
		self::assertSame('text/html; charset=utf-8', $headers['Content-Type']);
		self::assertSame('nosniff', $headers['X-Content-Type-Options']);
		self::assertSame('no-referrer', $headers['Referrer-Policy']);
		self::assertStringContainsString('sandbox allow-scripts', $headers['Content-Security-Policy']);
		self::assertStringContainsString('allow-downloads', $headers['Content-Security-Policy']);
		self::assertStringContainsString('allow-presentation', $headers['Content-Security-Policy']);
		self::assertStringNotContainsString('allow-same-origin', $headers['Content-Security-Policy']);
	}

	public function testInvalidCapabilityReturnsHardenedNotFound(): void {
		$response = $this->controller(new PreviewSnapshotStore($this->root, new ZipEntryService()))
			->serve('not-a-capability', 'index.html');

		self::assertSame(404, $response->getStatus());
		self::assertSame('no-store', $response->getHeaders()['Cache-Control']);
		self::assertArrayNotHasKey('Content-Security-Policy', $response->getHeaders());
	}

	private function controller(PreviewSnapshotStore $store): PreviewController {
		$request = new class implements IRequest {
		};
		$userSession = new class implements IUserSession {
		};
		$packageService = (new \ReflectionClass(ElpxPackageService::class))->newInstanceWithoutConstructor();
		return new PreviewController('exelearning', $request, $store, $userSession, $packageService);
	}

	private function removeTree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (new \FilesystemIterator($path) as $entry) {
			$entry->isDir() ? $this->removeTree($entry->getPathname()) : @unlink($entry->getPathname());
		}
		@rmdir($path);
	}
}
