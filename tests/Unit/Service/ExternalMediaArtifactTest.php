<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\EmbedShimSource;
use PHPUnit\Framework\TestCase;

/**
 * The script inlined into every served package must be the CANONICAL child bundle
 * published by eXeLearning core, not a copy maintained here.
 *
 * A bespoke copy is how this app ended up shipping a pre-handshake runtime while the
 * parent side had already moved on: the host waits for a `hello` that never comes, so no
 * embed is ever promoted and the learner sees permanent black boxes. Nothing errors, so
 * the only way to catch it is to assert which bytes are served.
 */
final class ExternalMediaArtifactTest extends TestCase {
	private const VENDORED = __DIR__ . '/../../../src/embed/exe_external_media';

	public function testServedShimIsTheVendoredChildBundle(): void {
		$source = (new EmbedShimSource(self::VENDORED))->read();

		self::assertNotNull($source, 'no shim source was produced');
		self::assertSame(
			file_get_contents(self::VENDORED . '/exe-external-media-child.min.js'),
			$source,
		);
	}

	public function testServedShimPublishesTheSharedBridgeContract(): void {
		$source = (new EmbedShimSource(self::VENDORED))->read() ?? '';

		// The three symbols the host and the interactive-video iDevice rely on.
		self::assertStringContainsString('exeExternalMediaChild', $source);
		self::assertStringContainsString('exeEmbedShim', $source);
		self::assertStringContainsString('exeMediaBridge', $source);
		// And the licence notice ADR-0018 requires to survive minification.
		self::assertStringContainsString('Dual-licensed', $source);
	}

	public function testMissingArtifactYieldsNullRatherThanAFatal(): void {
		self::assertNull((new EmbedShimSource(__DIR__ . '/does-not-exist'))->read());
	}

	/**
	 * Byte identity against the manifest core published. Integrity, not provenance —
	 * the CI step pins the buildHash out of band — but it catches a local edit, which is
	 * the failure this repo can actually cause on its own.
	 */
	public function testVendoredBytesMatchTheManifest(): void {
		$manifest = json_decode((string)file_get_contents(self::VENDORED . '/exe-external-media.manifest.json'), true);
		self::assertIsArray($manifest['files'] ?? null);

		foreach ($manifest['files'] as $key => $record) {
			$path = self::VENDORED . '/' . $record['path'];
			self::assertFileExists($path, "vendored $key is missing");
			self::assertSame($record['sha256'], hash_file('sha256', $path), "vendored $key was edited locally");
		}
	}
}
