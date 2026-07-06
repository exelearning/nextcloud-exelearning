<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\IframeSandbox;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see IframeSandbox} — the single source of truth for the secure
 * (opaque-origin) iframe policy used to render published .elpx content.
 */
final class IframeSandboxTest extends TestCase {
	private function service(?string $legacyEnv = null): IframeSandbox {
		return new IframeSandbox(static fn (string $name): ?string => $legacyEnv);
	}

	public function testDefaultsToSecureMode(): void {
		self::assertSame(IframeSandbox::MODE_SECURE, $this->service()->resolveMode());
	}

	public function testLegacyModeOnlyViaEscapeHatch(): void {
		self::assertSame(IframeSandbox::MODE_LEGACY, $this->service('1')->resolveMode());
		self::assertSame(IframeSandbox::MODE_LEGACY, $this->service('true')->resolveMode());
		self::assertSame(IframeSandbox::MODE_SECURE, $this->service('0')->resolveMode());
		self::assertSame(IframeSandbox::MODE_SECURE, $this->service('')->resolveMode());
	}

	public function testSecureSandboxTokensNeverAllowSameOrigin(): void {
		$tokens = $this->service()->sandboxTokens(IframeSandbox::MODE_SECURE);
		self::assertSame('allow-scripts allow-popups allow-forms', $tokens);
		self::assertStringNotContainsString('allow-same-origin', $tokens);
		self::assertStringNotContainsString('allow-popups-to-escape-sandbox', $tokens);
		self::assertStringNotContainsString('allow-top-navigation', $tokens);
	}

	public function testLegacySandboxTokensReintroduceSameOrigin(): void {
		$tokens = $this->service()->sandboxTokens(IframeSandbox::MODE_LEGACY);
		self::assertStringContainsString('allow-same-origin', $tokens);
	}

	public function testStrictPublishedCspShape(): void {
		$csp = $this->service()->contentSecurityPolicy();
		// The opaque sandbox rides in the CSP too (keeps the doc opaque if opened top-level).
		self::assertStringContainsString('sandbox allow-scripts allow-popups allow-forms', $csp);
		self::assertStringContainsString("object-src 'none'", $csp);
		self::assertStringContainsString("frame-ancestors 'self'", $csp);
		// Only the maintained providers, never bare https: in frame-src.
		self::assertStringContainsString('https://www.youtube-nocookie.com', $csp);
		self::assertStringContainsString('https://mediateca.educa.madrid.org', $csp);
		self::assertStringNotContainsString('frame-src \'self\' https:;', $csp);
		// No token-exfiltrating bare https: in script/img/media in the strict profile.
		self::assertDoesNotMatchRegularExpression('~script-src[^;]*\bhttps:(?!//)~', $csp);
	}

	public function testCompatibleProfileReopensHttps(): void {
		$csp = $this->service()->contentSecurityPolicy('compatible');
		self::assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval' https:", $csp);
		self::assertStringContainsString('frame-src \'self\' https:', $csp);
		// Sandbox stays even in the weaker profile.
		self::assertStringContainsString('sandbox allow-scripts allow-popups allow-forms', $csp);
	}

	public function testSvgCspIsScriptFree(): void {
		$csp = $this->service()->svgCsp();
		self::assertStringContainsString("script-src 'none'", $csp);
		self::assertStringContainsString("default-src 'none'", $csp);
		self::assertStringContainsString('sandbox', $csp);
	}

	public function testPermissionsPolicyDeniesHardwareButNotFullscreen(): void {
		$pp = $this->service()->permissionsPolicy();
		self::assertStringContainsString('camera=()', $pp);
		self::assertStringContainsString('microphone=()', $pp);
		self::assertStringContainsString('geolocation=()', $pp);
		self::assertStringNotContainsString('fullscreen', $pp);
	}

	public function testProviderWhitelistIsLowercaseAndDeduped(): void {
		$hosts = $this->service()->providerWhitelist();
		self::assertContains('www.youtube-nocookie.com', $hosts);
		self::assertContains('player.vimeo.com', $hosts);
		self::assertContains('mediateca.educa.madrid.org', $hosts);
		self::assertSame(array_values(array_unique($hosts)), $hosts);
		self::assertSame(array_map('strtolower', $hosts), $hosts);
	}
}
