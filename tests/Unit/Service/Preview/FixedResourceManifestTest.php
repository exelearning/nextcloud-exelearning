<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\FixedResourceManifest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FixedResourceManifest} — the fixed-installation-resource
 * (layer 1) resolver: exact-key manifest lookup, distribution-root containment,
 * and graceful disable when the manifest is absent.
 */
final class FixedResourceManifestTest extends TestCase {
	private string $distRoot;

	protected function setUp(): void {
		$this->distRoot = sys_get_temp_dir() . '/exe-fixed-' . bin2hex(random_bytes(6));
		mkdir($this->distRoot . '/bundles', 0770, true);
		mkdir($this->distRoot . '/libs/jquery', 0770, true);
	}

	protected function tearDown(): void {
		$this->removeTree($this->distRoot);
		$this->removeTree(dirname($this->distRoot) . '/exe-outside-' . basename($this->distRoot));
	}

	private function writeManifest(array $resources): void {
		file_put_contents(
			$this->distRoot . '/bundles/preview-fixed-resources.json',
			json_encode(['schemaVersion' => 1, 'buildVersion' => 'test', 'resources' => $resources]),
		);
	}

	public function testResolvesEnumeratedResource(): void {
		$body = 'window.jQuery=function(){};';
		file_put_contents($this->distRoot . '/libs/jquery/jquery.min.js', $body);
		$this->writeManifest([
			'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => strlen($body)],
		]);
		$manifest = new FixedResourceManifest($this->distRoot);

		self::assertTrue($manifest->has('libs/jquery/jquery.min.js'));
		$resource = $manifest->get('libs/jquery/jquery.min.js');
		self::assertNotNull($resource);
		self::assertSame($body, $resource['bytes']);
		// get() reports the ACTUAL byte length read from disk, not the manifest's
		// declared size (which the server never trusts for serving).
		self::assertSame(strlen($body), $resource['size']);
	}

	public function testUnknownIdIsNeitherHadNorResolved(): void {
		$this->writeManifest(['libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js']]);
		$manifest = new FixedResourceManifest($this->distRoot);

		self::assertFalse($manifest->has('libs/does/not/exist.js'));
		self::assertNull($manifest->get('libs/does/not/exist.js'));
	}

	public function testAbsentManifestDisablesTheLayer(): void {
		// No bundles/preview-fixed-resources.json written.
		$manifest = new FixedResourceManifest($this->distRoot);
		self::assertFalse($manifest->has('anything'));
		self::assertNull($manifest->get('anything'));
	}

	public function testMalformedManifestDisablesTheLayer(): void {
		file_put_contents($this->distRoot . '/bundles/preview-fixed-resources.json', '{ not json');
		$manifest = new FixedResourceManifest($this->distRoot);
		self::assertFalse($manifest->has('anything'));
	}

	public function testManifestPathEscapingRootIsRejected(): void {
		$outside = dirname($this->distRoot) . '/exe-outside-' . basename($this->distRoot);
		file_put_contents($outside, 'SECRET');
		$this->writeManifest(['evil' => ['path' => '../exe-outside-' . basename($this->distRoot)]]);
		$manifest = new FixedResourceManifest($this->distRoot);

		// The id is enumerated, but the containment check refuses to read it.
		self::assertTrue($manifest->has('evil'));
		self::assertNull($manifest->get('evil'));
	}

	public function testMissingBackingFileYieldsNull(): void {
		$this->writeManifest(['ghost' => ['path' => 'libs/jquery/ghost.js']]);
		$manifest = new FixedResourceManifest($this->distRoot);
		self::assertTrue($manifest->has('ghost'));
		self::assertNull($manifest->get('ghost'));
	}

	private function removeTree(string $dir): void {
		if (is_file($dir)) {
			@unlink($dir);
			return;
		}
		if (!is_dir($dir)) {
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
