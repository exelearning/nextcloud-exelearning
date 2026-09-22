<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\AppInfo;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCA\ExeLearning\Service\ContentTokenService;
use OCA\ExeLearning\Service\IframeSandbox;
use OCA\ExeLearning\Service\Preview\PreviewSnapshotStore;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\Util;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

final class ApplicationBootstrapTest extends TestCase {
	protected function setUp(): void {
		Util::reset();
	}

	public function testRegistersThePreviewProvider(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerPreviewProvider')
			->with(ElpxPreviewProvider::class, ElpxPreviewProvider::MIME_REGEX);

		(new Application())->register($context);
	}

	public function testRegisteredFactoriesBuildConfiguredServices(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')
			->willReturnCallback(static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')
			->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
				'secret' => 'unit-test-secret',
				'datadirectory' => sys_get_temp_dir() . '/nextcloud-test-data',
				default => $default,
			});
		$config->method('getAppValue')
			->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'unsafe_legacy_iframe', 'embed_open' => '1',
				default => $default,
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with(IConfig::class)
			->willReturn($config);

		$oldLegacy = getenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
		$oldEmbed = getenv('EXELEARNING_EMBED_OPEN');
		putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
		putenv('EXELEARNING_EMBED_OPEN');
		try {
			(new Application())->register($context);

			self::assertArrayHasKey(ContentTokenService::class, $factories);
			self::assertArrayHasKey(IframeSandbox::class, $factories);
			self::assertArrayHasKey(PreviewSnapshotStore::class, $factories);

			$tokens = $factories[ContentTokenService::class]($container);
			self::assertSame([42, 'alice'], $tokens->verify($tokens->mint(42, 'alice')));

			$sandbox = $factories[IframeSandbox::class]($container);
			self::assertSame(IframeSandbox::MODE_LEGACY, $sandbox->resolveMode());
			self::assertSame(IframeSandbox::EMBED_OPEN, $sandbox->embedMode());

			$store = $factories[PreviewSnapshotStore::class]($container);
			$root = new ReflectionProperty($store, 'root');
			self::assertSame(
				sys_get_temp_dir() . '/nextcloud-test-data/exelearning/preview-snapshots',
				$root->getValue($store),
			);
		} finally {
			$oldLegacy === false
				? putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME')
				: putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME=' . $oldLegacy);
			$oldEmbed === false
				? putenv('EXELEARNING_EMBED_OPEN')
				: putenv('EXELEARNING_EMBED_OPEN=' . $oldEmbed);
		}
	}

	public function testPreviewStoreFactoryFallsBackToSystemTempDirectory(): void {
		$factory = null;
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')
			->willReturnCallback(static function (string $name, callable $serviceFactory) use (&$factory): void {
				if ($name === PreviewSnapshotStore::class) {
					$factory = $serviceFactory;
				}
			});
		(new Application())->register($context);
		self::assertIsCallable($factory);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')
			->with('datadirectory', '')
			->willReturn('');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IConfig::class)->willReturn($config);

		$store = $factory($container);
		$root = new ReflectionProperty($store, 'root');
		self::assertSame(
			rtrim(sys_get_temp_dir(), '/') . '/exelearning/preview-snapshots',
			$root->getValue($store),
		);
	}

	public function testBootRegistersTheMainInitScript(): void {
		$context = $this->createMock(IBootContext::class);

		(new Application())->boot($context);

		self::assertSame(
			[[Application::APP_ID, 'exelearning-main']],
			Util::$initScripts,
		);
	}
}
