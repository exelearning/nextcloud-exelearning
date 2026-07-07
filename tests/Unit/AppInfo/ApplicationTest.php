<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\AppInfo;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Service\IframeSandbox;
use PHPUnit\Framework\TestCase;

/**
 * Covers the app-config fallback wired into {@see Application::iframeSandboxEnvReader()}.
 *
 * IframeSandbox stays OCP-free and secure-by-default (see {@see \OCA\ExeLearning\Tests\Unit\Service\IframeSandboxTest});
 * this test pins the extra seam that lets the php-wasm Playground flip the
 * dev-only escape hatch through a Nextcloud app-config value (`config:app:set`)
 * instead of a process env var it cannot set — without ever weakening the
 * secure default.
 */
final class ApplicationTest extends TestCase {
	private const HATCH = 'EXELEARNING_UNSAFE_LEGACY_IFRAME';

	/**
	 * @param array<string,string|false> $env Process-env values (false = unset).
	 * @param array<string,string> $config App-config values keyed by app-config key.
	 */
	private function reader(array $env = [], array $config = []): callable {
		return Application::iframeSandboxEnvReader(
			static fn (string $name): string|false => $env[$name] ?? false,
			static fn (string $key): string => $config[$key] ?? '',
		);
	}

	public function testDefaultsToSecureWhenNeitherEnvNorConfigSet(): void {
		$sandbox = new IframeSandbox($this->reader());
		self::assertNull($this->reader()(self::HATCH));
		self::assertSame(IframeSandbox::MODE_SECURE, $sandbox->resolveMode());
	}

	public function testAppConfigFlipsToLegacyWhenEnvUnset(): void {
		// This is the Playground path: no process env var, only config:app:set.
		$reader = $this->reader(config: ['unsafe_legacy_iframe' => '1']);
		self::assertSame('1', $reader(self::HATCH));
		self::assertSame(IframeSandbox::MODE_LEGACY, (new IframeSandbox($reader))->resolveMode());
	}

	public function testEmptyAppConfigStaysSecure(): void {
		$reader = $this->reader(config: ['unsafe_legacy_iframe' => '']);
		self::assertNull($reader(self::HATCH));
		self::assertSame(IframeSandbox::MODE_SECURE, (new IframeSandbox($reader))->resolveMode());
	}

	public function testProcessEnvWinsOverAppConfig(): void {
		// A real host's env var must override any lingering app-config value.
		$reader = $this->reader(env: [self::HATCH => '0'], config: ['unsafe_legacy_iframe' => '1']);
		self::assertSame('0', $reader(self::HATCH));
		self::assertSame(IframeSandbox::MODE_SECURE, (new IframeSandbox($reader))->resolveMode());
	}

	public function testExplicitlyEmptyEnvDisablesTheConfigHatch(): void {
		// getenv() returning '' (set-but-empty, not false) still shadows config.
		$reader = $this->reader(env: [self::HATCH => ''], config: ['unsafe_legacy_iframe' => '1']);
		self::assertSame('', $reader(self::HATCH));
		self::assertSame(IframeSandbox::MODE_SECURE, (new IframeSandbox($reader))->resolveMode());
	}

	public function testEnvNameIsMappedToLowercaseAppConfigKey(): void {
		$seen = [];
		$reader = Application::iframeSandboxEnvReader(
			static fn (string $name): string|false => false,
			static function (string $key) use (&$seen): string {
				$seen[] = $key;
				return '';
			},
		);
		$reader('EXELEARNING_UNSAFE_LEGACY_IFRAME');
		$reader('EXELEARNING_EMBED_OPEN');
		self::assertSame(['unsafe_legacy_iframe', 'embed_open'], $seen);
	}

	public function testEmbedOpenHatchAlsoHonoursAppConfig(): void {
		$reader = $this->reader(config: ['embed_open' => '1']);
		self::assertSame(IframeSandbox::EMBED_OPEN, (new IframeSandbox($reader))->embedMode());
	}
}
