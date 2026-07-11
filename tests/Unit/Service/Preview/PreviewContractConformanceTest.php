<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\ApiResult;
use OCA\ExeLearning\Service\Preview\FixedResourceManifest;
use OCA\ExeLearning\Service\Preview\PreviewResponse;
use OCA\ExeLearning\Service\Preview\PreviewServer;
use OCA\ExeLearning\Service\Preview\PreviewSessionApi;
use OCA\ExeLearning\Service\Preview\PreviewSessionLimits;
use OCA\ExeLearning\Service\Preview\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Replays the shared eXeLearning preview-serving-contract v2 conformance vectors
 * (tests/fixtures/preview-contract/vectors.json, vendored verbatim from eXe
 * core) against this app's management API + serving route. Porting the harness
 * interpretation — not just the assertions — keeps the protocol semantics
 * aligned with every other host (Moodle / WordPress / Omeka S / Procomún /
 * Electron / core) beyond the CSP drift-check.
 *
 * The vectors drive the OCP-free seams ({@see PreviewSessionApi} +
 * {@see PreviewServer}) directly: the multipart encoding the harness describes
 * is exactly what {@see \OCA\ExeLearning\Controller\PreviewSessionController}
 * decodes into these calls.
 */
final class PreviewContractConformanceTest extends TestCase {
	private const OWNER = 'conformance-user';

	private string $root;
	private string $distRoot;
	private PreviewSessionApi $api;
	private PreviewServer $server;
	private string $previewId = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-vec-' . bin2hex(random_bytes(6));
		$this->distRoot = sys_get_temp_dir() . '/exe-vec-dist-' . bin2hex(random_bytes(6));
		mkdir($this->distRoot, 0770, true);
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
		$this->removeTree($this->distRoot);
	}

	public function testReplayVectors(): void {
		$vectors = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../fixtures/preview-contract/vectors.json'),
			true,
		);
		self::assertSame(2, $vectors['protocolVersion']);

		$fixed = $this->materializeFixedResources($vectors['fixedResources']);
		$store = new PreviewSessionStore($this->root, new PreviewSessionLimits());
		$this->api = new PreviewSessionApi($store, $fixed);
		$this->server = new PreviewServer($store, $fixed);

		foreach ($vectors['steps'] as $step) {
			$this->runStep($step);
		}
	}

	/**
	 * Write each fixed resource under a throwaway distribution root and register
	 * it in the host's manifest copy, then resolve fixed refs through that
	 * manifest only (harness semantics step 1).
	 *
	 * @param array<string,array{path:string,content:string}> $resources
	 */
	private function materializeFixedResources(array $resources): FixedResourceManifest {
		$manifest = [];
		foreach ($resources as $id => $resource) {
			$target = $this->distRoot . '/' . $resource['path'];
			@mkdir(dirname($target), 0770, true);
			file_put_contents($target, $resource['content']);
			$manifest[$id] = ['path' => $resource['path'], 'size' => strlen($resource['content'])];
		}
		@mkdir($this->distRoot . '/bundles', 0770, true);
		file_put_contents(
			$this->distRoot . '/bundles/preview-fixed-resources.json',
			json_encode(['schemaVersion' => 1, 'resources' => $manifest]),
		);
		return new FixedResourceManifest($this->distRoot);
	}

	private function runStep(array $step): void {
		$id = $step['id'];
		$method = $step['request']['method'];
		$path = str_replace('{previewId}', $this->previewId, $step['request']['path']);
		$expect = $step['expect'];

		if ($method === 'GET') {
			$response = $this->dispatchServe($path, $step['request']['headers'] ?? []);
			$this->assertServe($id, $response, $expect);
			return;
		}
		$result = $this->dispatchManagement($method, $path, $step['request']['body'] ?? null);
		$this->assertManagement($id, $result, $expect);
		if ($id === 'create-session') {
			$this->previewId = $result->body['previewId'];
		}
	}

	private function dispatchServe(string $path, array $headers): PreviewResponse {
		self::assertSame(1, preg_match('#^/preview/([^/]+)/(.*)$#', $path, $m), "unexpected serve path: $path");
		return $this->server->serve(
			$m[1],
			$m[2],
			$headers['If-None-Match'] ?? null,
			$headers['Range'] ?? null,
		);
	}

	private function dispatchManagement(string $method, string $path, ?array $body): ApiResult {
		if ($method === 'POST' && $path === '/api/preview-session') {
			return $this->api->create(self::OWNER);
		}
		if ($method === 'POST' && str_ends_with($path, '/assets')) {
			return $this->api->uploadAssets($this->previewId, self::OWNER, $this->assetEntries($body));
		}
		if ($method === 'POST' && str_ends_with($path, '/revisions')) {
			return $this->api->publishRevision($this->previewId, self::OWNER, $this->revisionMeta($body));
		}
		if ($method === 'DELETE') {
			return $this->api->delete($this->previewId, self::OWNER);
		}
		self::fail("unhandled management request: $method $path");
	}

	/**
	 * `body.kind === "assets"`: each entry becomes `{ key, size, bytes }` (the
	 * harness's `size` = byte length of `content`).
	 *
	 * @return list<array{key:string,declaredSize:int,bytes:string}>
	 */
	private function assetEntries(?array $body): array {
		$entries = [];
		foreach ($body['entries'] ?? [] as $entry) {
			$entries[] = [
				'key' => $entry['key'],
				'declaredSize' => strlen($entry['content']),
				'bytes' => $entry['content'],
			];
		}
		return $entries;
	}

	/**
	 * `body.kind === "revision"`: `meta` plus `writes` mapped to `{ path, bytes }`
	 * (the harness sends `writes` as paths + index-aligned file parts).
	 *
	 * @return array<string,mixed>
	 */
	private function revisionMeta(?array $body): array {
		$meta = $body['meta'];
		$writes = [];
		foreach ($body['writes'] ?? [] as $write) {
			$writes[] = ['path' => $write['path'], 'bytes' => $write['content']];
		}
		$meta['writes'] = $writes;
		return $meta;
	}

	private function assertManagement(string $id, ApiResult $result, array $expect): void {
		self::assertSame($expect['status'], $result->status, "status for step $id");
		if (isset($expect['body'])) {
			$this->assertBodySubset($expect['body'], $result->body, $id);
		}
	}

	private function assertServe(string $id, PreviewResponse $response, array $expect): void {
		self::assertSame($expect['status'], $response->status, "status for step $id");
		if (isset($expect['bodyText'])) {
			self::assertSame($expect['bodyText'], $response->body, "bodyText for step $id");
		}
		foreach ($expect['headers'] ?? [] as $name => $spec) {
			$this->assertHeader($id, $response->headers, $name, $spec);
		}
	}

	/**
	 * @param array<string,string> $headers
	 * @param string|array<string,mixed> $spec
	 */
	private function assertHeader(string $id, array $headers, string $name, string|array $spec): void {
		$value = null;
		$present = false;
		foreach ($headers as $key => $headerValue) {
			if (strcasecmp($key, $name) === 0) {
				$value = $headerValue;
				$present = true;
				break;
			}
		}
		if (is_string($spec)) {
			self::assertTrue($present, "header $name present for step $id");
			self::assertSame($spec, $value, "header $name for step $id");
			return;
		}
		if (isset($spec['absent'])) {
			self::assertFalse($present, "header $name must be absent for step $id");
			return;
		}
		self::assertTrue($present, "header $name present for step $id");
		if (isset($spec['startsWith'])) {
			self::assertStringStartsWith($spec['startsWith'], (string)$value, "header $name startsWith for step $id");
		}
		if (isset($spec['contains'])) {
			self::assertStringContainsString($spec['contains'], (string)$value, "header $name contains for step $id");
		}
	}

	/**
	 * Recursive subset match: JSON arrays (PHP lists) must match exactly, JSON
	 * objects (assoc) may carry extra keys.
	 *
	 * @param array<mixed> $expected
	 * @param array<mixed> $actual
	 */
	private function assertBodySubset(array $expected, array $actual, string $id): void {
		foreach ($expected as $key => $value) {
			self::assertArrayHasKey($key, $actual, "body.$key for step $id");
			if (is_array($value)) {
				if (array_is_list($value)) {
					self::assertSame($value, $actual[$key], "body.$key (array) for step $id");
				} else {
					self::assertIsArray($actual[$key], "body.$key (object) for step $id");
					$this->assertBodySubset($value, $actual[$key], $id);
				}
			} else {
				self::assertSame($value, $actual[$key], "body.$key for step $id");
			}
		}
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
