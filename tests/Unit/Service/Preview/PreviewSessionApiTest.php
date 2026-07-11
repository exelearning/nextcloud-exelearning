<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\FixedResourceManifest;
use OCA\ExeLearning\Service\Preview\PreviewSessionApi;
use OCA\ExeLearning\Service\Preview\PreviewSessionLimits;
use OCA\ExeLearning\Service\Preview\PreviewSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PreviewSessionApi} — the management-API policy: protocol
 * negotiation on create, the ownership gate (404 vs 403), and the mapping of
 * store results onto the contract wire bodies (409/422/413/200).
 */
final class PreviewSessionApiTest extends TestCase {
	private const KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

	private string $root;
	private PreviewSessionApi $api;
	private PreviewSessionStore $store;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/exe-api-' . bin2hex(random_bytes(6));
		$this->store = new PreviewSessionStore($this->root, new PreviewSessionLimits());
		$this->api = new PreviewSessionApi($this->store, new FixedResourceManifest($this->root . '/absent'));
	}

	protected function tearDown(): void {
		$this->removeTree($this->root);
	}

	public function testCreateNegotiatesProtocolV2WithLimits(): void {
		$result = $this->api->create('alice');
		self::assertSame(201, $result->status);
		self::assertSame(2, $result->body['protocolVersion']);
		self::assertSame(0, $result->body['revision']);
		self::assertTrue(PreviewSessionApi::PROTOCOL_VERSION === 2);
		self::assertArrayHasKey('previewId', $result->body);
		self::assertArrayHasKey('recommendedBatchBytes', $result->body['limits']);
		self::assertSame(5000, $result->body['limits']['maxFilesPerSession']);
	}

	public function testMissingSessionIs404(): void {
		$absent = '3f2a1b4c-5d6e-4f70-8a90-b1c2d3e4f506';
		self::assertSame(404, $this->api->uploadAssets($absent, 'alice', [])->status);
		self::assertSame(404, $this->api->delete($absent, 'alice')->status);
		self::assertSame(404, $this->api->publishRevision($absent, 'alice', $this->emptyMeta())->status);
	}

	public function testForeignOwnerIs403(): void {
		$id = $this->api->create('alice')->body['previewId'];
		self::assertSame(403, $this->api->uploadAssets($id, 'bob', [])->status);
		self::assertSame(403, $this->api->delete($id, 'bob')->status);
		self::assertSame(403, $this->api->publishRevision($id, 'bob', $this->emptyMeta())->status);
	}

	public function testUploadAssetsReturnsStoreResult(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$result = $this->api->uploadAssets($id, 'alice', [
			['key' => self::KEY, 'declaredSize' => 5, 'bytes' => 'PHOTO'],
		]);
		self::assertSame(200, $result->status);
		self::assertSame([self::KEY], $result->body['stored']);
		self::assertSame([], $result->body['alreadyStored']);
		self::assertSame([], $result->body['rejected']);
	}

	public function testPublishRevisionSuccessBody(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$result = $this->api->publishRevision($id, 'alice', [
			'baseRevision' => 0,
			'nextRevision' => 1,
			'writes' => [['path' => 'index.html', 'bytes' => 'x']],
			'deletes' => [],
			'assetRefs' => [],
			'fixedRefs' => [],
		]);
		self::assertSame(200, $result->status);
		self::assertSame(['revision' => 1, 'active' => true], $result->body);
	}

	public function testRevisionConflictBody(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$this->api->publishRevision($id, 'alice', [
			'baseRevision' => 0, 'nextRevision' => 1,
			'writes' => [['path' => 'index.html', 'bytes' => 'v1']],
			'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
		]);
		$stale = $this->api->publishRevision($id, 'alice', [
			'baseRevision' => 0, 'nextRevision' => 1,
			'writes' => [['path' => 'index.html', 'bytes' => 'stale']],
			'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
		]);
		self::assertSame(409, $stale->status);
		self::assertSame(['reason' => 'revision-conflict', 'currentRevision' => 1], $stale->body);
	}

	public function testMissingAssetBody(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
		$result = $this->api->publishRevision($id, 'alice', [
			'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [], 'deletes' => [],
			'assetRefs' => ['content/ghost.png' => $ghost], 'fixedRefs' => [],
		]);
		self::assertSame(422, $result->status);
		self::assertSame(['reason' => 'missing-assets', 'missing' => [$ghost]], $result->body);
	}

	public function testUnknownFixedBody(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$result = $this->api->publishRevision($id, 'alice', [
			'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [], 'deletes' => [],
			'assetRefs' => [], 'fixedRefs' => ['theme/x.css' => 'theme:unknown'],
		]);
		self::assertSame(422, $result->status);
		self::assertSame(['reason' => 'unknown-fixed-resources', 'resources' => ['theme:unknown']], $result->body);
	}

	public function testDeleteBody(): void {
		$id = $this->api->create('alice')->body['previewId'];
		$result = $this->api->delete($id, 'alice');
		self::assertSame(200, $result->status);
		self::assertSame(['success' => true], $result->body);
		self::assertFalse($this->store->exists($id));
	}

	/** @return array<string,mixed> */
	private function emptyMeta(): array {
		return [
			'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
			'deletes' => [], 'assetRefs' => [], 'fixedRefs' => [],
		];
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
