<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\FixedResourceManifest;
use OCA\ExeLearning\Service\Preview\PreviewPolicy;
use OCA\ExeLearning\Service\Preview\PreviewServer;
use OCA\ExeLearning\Service\Preview\PreviewSessionLimits;
use OCA\ExeLearning\Service\Preview\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PreviewServer} — the serving-route HTTP policy: the hardening
 * header set on every response (404 included), tiered Cache-Control, the sandbox
 * CSP on scriptable types from every layer, ETag/304 and single-range 206/416.
 */
final class PreviewServerTest extends TestCase {
	private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
	private const CLIP_KEY = '12345678-90ab-4cde-8f01-234567890abc@00112233';

	private string $root;
	private string $distRoot;
	private PreviewSessionStore $store;
	private FixedResourceManifest $fixed;
	private string $previewId;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-srv-' . bin2hex(random_bytes(6));
		$this->distRoot = sys_get_temp_dir() . '/exe-srv-dist-' . bin2hex(random_bytes(6));
		mkdir($this->distRoot . '/bundles', 0770, true);
		mkdir($this->distRoot . '/base', 0770, true);
		file_put_contents($this->distRoot . '/base/jquery.min.js', 'window.jQuery=function(){};');
		file_put_contents(
			$this->distRoot . '/base/icon.svg',
			'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
		);
		file_put_contents($this->distRoot . '/bundles/preview-fixed-resources.json', json_encode([
			'resources' => [
				'libs/jquery/jquery.min.js' => ['path' => 'base/jquery.min.js'],
				'theme:base/icon.svg' => ['path' => 'base/icon.svg'],
			],
		]));
		$this->fixed = new FixedResourceManifest($this->distRoot);
		$this->store = new PreviewSessionStore($this->root, new PreviewSessionLimits());

		$this->previewId = $this->store->create('alice');
		$this->store->storeAssets($this->previewId, [
			['key' => self::PHOTO_KEY, 'declaredSize' => 14, 'bytes' => 'PHOTO-BYTES-v1'],
			['key' => self::CLIP_KEY, 'declaredSize' => 10, 'bytes' => '0123456789'],
		]);
		$this->store->applyRevision($this->previewId, [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [
				['path' => 'index.html', 'bytes' => '<html><body>hi</body></html>'],
				['path' => 'img/inline.svg', 'bytes' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
			],
			'deletes' => [],
			'assetRefs' => [
				'content/resources/photo.png' => self::PHOTO_KEY,
				'media/clip.mp4' => self::CLIP_KEY,
			],
			'fixedRefs' => [
				'libs/jquery/jquery.min.js' => 'libs/jquery/jquery.min.js',
				'theme/icon.svg' => 'theme:base/icon.svg',
			],
		], $this->fixed);
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
		$this->removeTree($this->distRoot);
	}

	private function server(): PreviewServer {
		return new PreviewServer($this->store, $this->fixed);
	}

	private function assertBaseHardening(array $headers): void {
		self::assertSame('nosniff', $headers['X-Content-Type-Options']);
		self::assertSame('no-referrer', $headers['Referrer-Policy']);
		self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $headers['Permissions-Policy']);
		self::assertSame('*', $headers['Access-Control-Allow-Origin']);
	}

	public function testServeDocument(): void {
		$response = $this->server()->serve($this->previewId, 'index.html');
		self::assertSame(200, $response->status);
		self::assertSame('<html><body>hi</body></html>', $response->body);
		self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
		self::assertSame('no-store', $response->headers['Cache-Control']);
		self::assertSame(PreviewPolicy::CSP, $response->headers['Content-Security-Policy']);
		$this->assertBaseHardening($response->headers);
	}

	public function testServeAssetHasEtagNoCacheAndNoCsp(): void {
		$response = $this->server()->serve($this->previewId, 'content/resources/photo.png');
		self::assertSame(200, $response->status);
		self::assertSame('PHOTO-BYTES-v1', $response->body);
		self::assertSame('image/png', $response->headers['Content-Type']);
		self::assertSame('no-cache', $response->headers['Cache-Control']);
		self::assertSame('"' . self::PHOTO_KEY . '"', $response->headers['ETag']);
		self::assertSame('bytes', $response->headers['Accept-Ranges']);
		self::assertArrayNotHasKey('Content-Security-Policy', $response->headers);
	}

	public function testConditionalRequestReturns304(): void {
		$response = $this->server()->serve(
			$this->previewId,
			'content/resources/photo.png',
			'"' . self::PHOTO_KEY . '"',
		);
		self::assertSame(304, $response->status);
		self::assertSame('', $response->body);
		self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
		self::assertSame('"' . self::PHOTO_KEY . '"', $response->headers['ETag']);
	}

	public function testSingleRangeReturns206(): void {
		$response = $this->server()->serve($this->previewId, 'media/clip.mp4', null, 'bytes=2-4');
		self::assertSame(206, $response->status);
		self::assertSame('234', $response->body);
		self::assertSame('bytes 2-4/10', $response->headers['Content-Range']);
		self::assertSame('3', $response->headers['Content-Length']);
	}

	public function testUnsatisfiableRangeReturns416(): void {
		$response = $this->server()->serve($this->previewId, 'media/clip.mp4', null, 'bytes=99-');
		self::assertSame(416, $response->status);
		self::assertSame('bytes */10', $response->headers['Content-Range']);
	}

	public function testServeFixedResourceIsAggressivelyCacheable(): void {
		$response = $this->server()->serve($this->previewId, 'libs/jquery/jquery.min.js');
		self::assertSame(200, $response->status);
		self::assertSame('window.jQuery=function(){};', $response->body);
		self::assertSame('private, max-age=31536000', $response->headers['Cache-Control']);
		self::assertSame('*', $response->headers['Access-Control-Allow-Origin']);
	}

	public function testScriptableSvgFromFixedLayerCarriesCsp(): void {
		$response = $this->server()->serve($this->previewId, 'theme/icon.svg');
		self::assertSame(200, $response->status);
		self::assertSame('image/svg+xml; charset=utf-8', $response->headers['Content-Type']);
		self::assertSame('private, max-age=31536000', $response->headers['Cache-Control']);
		self::assertSame(PreviewPolicy::CSP, $response->headers['Content-Security-Policy']);
	}

	public function testScriptableSvgFromSessionLayerCarriesCsp(): void {
		$response = $this->server()->serve($this->previewId, 'img/inline.svg');
		self::assertSame(200, $response->status);
		self::assertSame('image/svg+xml; charset=utf-8', $response->headers['Content-Type']);
		self::assertSame(PreviewPolicy::CSP, $response->headers['Content-Security-Policy']);
	}

	public function testUnknownPathIs404WithHardening(): void {
		$response = $this->server()->serve($this->previewId, 'nope.css');
		self::assertSame(404, $response->status);
		self::assertSame('no-store', $response->headers['Cache-Control']);
		$this->assertBaseHardening($response->headers);
	}

	public function testEncodedTraversalIs404(): void {
		$response = $this->server()->serve($this->previewId, '%2e%2e%2fsecret');
		self::assertSame(404, $response->status);
		self::assertSame('no-store', $response->headers['Cache-Control']);
	}

	public function testInvalidPreviewIdIs404(): void {
		$response = $this->server()->serve('not-a-uuid', 'index.html');
		self::assertSame(404, $response->status);
		$this->assertBaseHardening($response->headers);
	}

	public function testDeletedSessionStopsServing(): void {
		$this->store->delete($this->previewId);
		$response = $this->server()->serve($this->previewId, 'index.html');
		self::assertSame(404, $response->status);
		self::assertSame('no-store', $response->headers['Cache-Control']);
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			@unlink($dir);
			return;
		}
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$this->removeTree($dir . '/' . $item);
		}
		@rmdir($dir);
	}
}
