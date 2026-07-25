<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service\Preview;

use OCA\ExeLearning\Service\Preview\PreviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PreviewPolicy} — the single source of truth for the editor
 * preview isolation policy (CSP byte-identity, scriptable classification, MIME
 * resolution and traversal-safe path normalization).
 */
final class PreviewPolicyTest extends TestCase {
	/**
	 * Byte-identity with eXe core `previewCspHeader()`. If this ever fails the
	 * CSP has drifted from core and must be re-synced verbatim (no re-order,
	 * re-quote, reformat, or trailing `;`).
	 */
	public function testCspIsByteIdenticalToCore(): void {
		$expected = 'sandbox allow-scripts allow-popups allow-forms; '
			. "default-src 'self'; "
			. "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
			. "style-src 'self' 'unsafe-inline'; "
			. "img-src 'self' data: blob: https:; "
			. "media-src 'self' data: blob: https:; "
			. "font-src 'self' data:; "
			. "connect-src 'self'; "
			. "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
			. "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
			. "object-src 'none'; "
			. "base-uri 'none'; "
			. "form-action 'self'; "
			. "frame-ancestors 'self'";
		self::assertSame($expected, PreviewPolicy::CSP);
		// The leading sandbox directive is what makes the document opaque.
		self::assertStringStartsWith('sandbox allow-scripts allow-popups allow-forms', PreviewPolicy::CSP);
		self::assertStringNotContainsString('allow-same-origin', PreviewPolicy::CSP);
	}

	public function testPermissionsPolicyMatchesContract(): void {
		self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', PreviewPolicy::PERMISSIONS_POLICY);
	}

	/**
	 * @dataProvider scriptableTypes
	 */
	public function testScriptableTypesAreScriptable(string $mime): void {
		self::assertTrue(PreviewPolicy::isScriptable($mime));
	}

	/**
	 * @return iterable<array{0:string}>
	 */
	public static function scriptableTypes(): iterable {
		yield ['text/html'];
		yield ['text/html; charset=utf-8'];
		yield ['image/svg+xml'];
		yield ['image/svg+xml; charset=utf-8'];
		yield ['application/xml'];
		yield ['application/xhtml+xml; charset=utf-8'];
	}

	/**
	 * @dataProvider nonScriptableTypes
	 */
	public function testNonScriptableTypesAreNotScriptable(string $mime): void {
		self::assertFalse(PreviewPolicy::isScriptable($mime));
	}

	/**
	 * @return iterable<array{0:string}>
	 */
	public static function nonScriptableTypes(): iterable {
		yield ['image/png'];
		yield ['text/css; charset=utf-8'];
		yield ['application/json; charset=utf-8'];
		yield ['video/mp4'];
		yield ['application/octet-stream'];
	}

	/**
	 * @dataProvider mimeCases
	 */
	public function testMimeForPath(string $path, string $expected): void {
		self::assertSame($expected, PreviewPolicy::mimeForPath($path));
	}

	/**
	 * @return iterable<array{0:string,1:string}>
	 */
	public static function mimeCases(): iterable {
		yield 'html carries charset' => ['index.html', 'text/html; charset=utf-8'];
		yield 'png is bare' => ['content/resources/photo.png', 'image/png'];
		yield 'svg is charset-tagged (scriptable-tab hole)' => ['theme/icon.svg', 'image/svg+xml; charset=utf-8'];
		yield 'js carries charset' => ['libs/jquery/jquery.min.js', 'text/javascript; charset=utf-8'];
		yield 'json carries charset' => ['data/index.json', 'application/json; charset=utf-8'];
		yield 'mp4 is bare' => ['media/clip.mp4', 'video/mp4'];
		yield 'unknown falls back to octet-stream' => ['blob.bin', 'application/octet-stream'];
	}

	/**
	 * @dataProvider safePaths
	 */
	public function testNormalizePathAcceptsSafePaths(string $raw, string $expected): void {
		self::assertSame($expected, PreviewPolicy::normalizePath($raw));
	}

	/**
	 * @return iterable<array{0:string,1:string}>
	 */
	public static function safePaths(): iterable {
		yield 'plain file' => ['index.html', 'index.html'];
		yield 'empty defaults to index' => ['', 'index.html'];
		yield 'leading slash stripped' => ['/html/page-2.html', 'html/page-2.html'];
		yield 'dot segments collapsed' => ['a/./b.css', 'a/b.css'];
		yield 'double slash collapsed' => ['a//b.css', 'a/b.css'];
		yield 'backslash folded to slash' => ['a\\b.css', 'a/b.css'];
		yield 'query stripped' => ['page.html?v=3', 'page.html'];
	}

	/**
	 * @dataProvider unsafePaths
	 */
	public function testNormalizePathRejectsUnsafePaths(string $raw): void {
		self::assertNull(PreviewPolicy::normalizePath($raw));
	}

	/**
	 * @return iterable<array{0:string}>
	 */
	public static function unsafePaths(): iterable {
		yield 'literal parent' => ['../secret'];
		yield 'percent-encoded parent' => ['%2e%2e%2fsecret'];
		yield 'internal parent escape' => ['a/../../secret'];
		yield 'bare dotdot' => ['..'];
		yield 'trailing parent collapses to empty root' => ['foo/..'];
		yield 'nul byte' => ["a\0b"];
	}

	public function testPreviewIdValidation(): void {
		self::assertTrue(PreviewPolicy::isValidPreviewId('3f2a1b4c-5d6e-4f70-8a90-b1c2d3e4f506'));
		self::assertFalse(PreviewPolicy::isValidPreviewId('not-a-uuid'));
		self::assertFalse(PreviewPolicy::isValidPreviewId('3F2A1B4C-5D6E-4F70-8A90-B1C2D3E4F506'));
		self::assertFalse(PreviewPolicy::isValidPreviewId('../../etc/passwd'));
	}

}
