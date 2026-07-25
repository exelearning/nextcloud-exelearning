<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\PreviewPolicy;
use OCA\ExeLearning\Service\Preview\PreviewServer;
use OCA\ExeLearning\Service\Preview\PreviewSnapshotLimits;
use OCA\ExeLearning\Service\Preview\PreviewSnapshotStore;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see PreviewServer} — the serving-route HTTP policy: the hardening
 * header set on every response (404 included), tiered Cache-Control, the sandbox
 * CSP on every scriptable type, ETag/304 and single-range 206/416.
 */
final class PreviewServerTest extends TestCase {
	private string $root;
	private PreviewSnapshotStore $store;
	private string $previewId;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-srv-' . bin2hex(random_bytes(6));
		$this->store = new PreviewSnapshotStore($this->root, new PreviewSnapshotLimits());
		$this->previewId = $this->store->replace('alice', $this->zip([
			'index.html' => '<html><body>hi</body></html>',
			'img/inline.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
			'content/resources/photo.png' => 'PHOTO-BYTES-v1',
			'media/clip.mp4' => '0123456789',
		]))['previewId'];
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
	}

	/**
	 * Build a snapshot ZIP on disk from a path => contents map.
	 *
	 * @param array<string,string> $entries
	 */
	private function zip(array $entries): string {
		$path = sys_get_temp_dir() . '/exe-srv-zip-' . bin2hex(random_bytes(6)) . '.zip';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($entries as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();
		return $path;
	}

	private function server(): PreviewServer {
		return new PreviewServer($this->store);
	}

	/** The ETag the store minted for a path (content-independent, so read it back). */
	private function etagFor(string $path): string {
		return $this->server()->serve($this->previewId, $path)->headers['ETag'];
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
		self::assertSame('bytes', $response->headers['Accept-Ranges']);
		self::assertArrayHasKey('ETag', $response->headers);
		self::assertArrayNotHasKey('Content-Security-Policy', $response->headers);
	}

	public function testConditionalRequestReturns304(): void {
		$etag = $this->etagFor('content/resources/photo.png');
		$response = $this->server()->serve($this->previewId, 'content/resources/photo.png', $etag);
		self::assertSame(304, $response->status);
		self::assertSame('', $response->body);
		self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
		self::assertSame($etag, $response->headers['ETag']);
	}

	public function testSingleRangeReturns206(): void {
		$response = $this->server()->serve($this->previewId, 'media/clip.mp4', null, 'bytes=2-4');
		self::assertSame(206, $response->status);
		self::assertSame('234', $response->body);
		self::assertSame('bytes 2-4/10', $response->headers['Content-Range']);
		self::assertSame('3', $response->headers['Content-Length']);
	}

	/**
	 * 416 is reserved for a valid single range that is unsatisfiable: a
	 * first-byte-pos at/after EOF (`bytes=99-`) or a zero-length suffix
	 * (`bytes=-0`).
	 *
	 * @dataProvider unsatisfiableRangeProvider
	 */
	public function testUnsatisfiableRangeReturns416(string $range): void {
		$response = $this->server()->serve($this->previewId, 'media/clip.mp4', null, $range);
		self::assertSame(416, $response->status, $range . ' must be 416');
		self::assertSame('bytes */10', $response->headers['Content-Range']);
	}

	/** @return array<string,array{0:string}> */
	public static function unsatisfiableRangeProvider(): array {
		return [
			'first-byte-pos past EOF' => ['bytes=99-'],
			'zero-length suffix' => ['bytes=-0'],
		];
	}

	/**
	 * A malformed, multi-range or non-bytes Range header is IGNORED — the server
	 * serves a normal 200 full body, never 416.
	 *
	 * @dataProvider ignoredRangeProvider
	 */
	public function testIgnoredRangeServesFull200(string $range): void {
		$response = $this->server()->serve($this->previewId, 'media/clip.mp4', null, $range);
		self::assertSame(200, $response->status, $range . ' must be ignored, not 416');
		self::assertSame('0123456789', $response->body);
		self::assertSame('bytes', $response->headers['Accept-Ranges']);
		self::assertArrayNotHasKey('Content-Range', $response->headers);
	}

	/** @return array<string,array{0:string}> */
	public static function ignoredRangeProvider(): array {
		return [
			'non-numeric' => ['bytes=abc'],
			'multi-range' => ['bytes=0-1,2-3'],
			'non-bytes unit' => ['items=0-1'],
			'empty range' => ['bytes=-'],
			'garbage' => ['not-a-range'],
			'double dash' => ['bytes=1-2-3'],
			// last-byte-pos < first-byte-pos is an invalid spec (RFC 9110
			// §14.1.2) → ignore the header, not 416.
			'last before first' => ['bytes=5-2'],
		];
	}

	public function testBareRootRedirectsToIndexHtml(): void {
		$response = $this->server()->serveRoot($this->previewId);
		self::assertSame(302, $response->status);
		// Relative Location so it stays correct under any webroot: it resolves
		// against the request URI `.../preview/{previewId}`.
		self::assertSame($this->previewId . '/index.html', $response->headers['Location']);
		self::assertSame('no-store', $response->headers['Cache-Control']);
		$this->assertBaseHardening($response->headers);
	}

	public function testBareRootInvalidPreviewIdIs404(): void {
		$response = $this->server()->serveRoot('not-a-uuid');
		self::assertSame(404, $response->status);
		self::assertArrayNotHasKey('Location', $response->headers);
		$this->assertBaseHardening($response->headers);
	}

	/**
	 * An author-supplied SVG runs its inline <script> top-level, and `nosniff`
	 * does not help — SVG is already a scriptable type — so it must carry the
	 * sandbox CSP just like an HTML document.
	 */
	public function testScriptableSvgCarriesCsp(): void {
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

	public function testDeletedSnapshotStopsServing(): void {
		$this->store->delete($this->previewId, 'alice');
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
