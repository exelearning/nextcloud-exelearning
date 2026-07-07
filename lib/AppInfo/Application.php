<?php

declare(strict_types=1);

namespace OCA\ExeLearning\AppInfo;

use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCA\ExeLearning\Service\ContentTokenService;
use OCA\ExeLearning\Service\IframeSandbox;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\Util;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'exelearning';

	/** Modern eXeLearning package MIME, registered for both `.elp` and `.elpx`. */
	public const PRIMARY_MIME_TYPE = 'application/vnd.exelearning.elpx';

	/**
	 * MIMEs that unambiguously identify an eXeLearning archive on disk.
	 * Used by {@see \OCA\ExeLearning\Service\PermissionService::isElpxFile()}
	 * to decide whether to claim a file by MIME alone — extensions are
	 * still checked separately.
	 */
	public const VENDOR_MIME_TYPES = [
		self::PRIMARY_MIME_TYPE,
		'application/x-exelearning',
	];

	/**
	 * Broader list of MIMEs an `.elp(x)` file may carry on disk before the
	 * admin has registered the custom mapping (Nextcloud falls back to
	 * `application/zip` or `application/octet-stream` in that case). Kept
	 * for routing decisions where we already know the request is for an
	 * eXeLearning resource — do **not** use this for "is this an
	 * eXeLearning file" checks; that path needs `VENDOR_MIME_TYPES`
	 * combined with an extension check, otherwise every plain ZIP in
	 * the user's Files would be misclassified.
	 */
	public const ALLOWED_MIME_TYPES = [
		self::PRIMARY_MIME_TYPE,
		'application/x-exelearning',
		'application/zip',
		'application/octet-stream',
	];

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	public function register(IRegistrationContext $context): void {
		// The modern preview-provider registration path. Nextcloud collects
		// these during the bootstrap phase, instantiates the provider lazily
		// when a matching MIME is previewed, and ensures the provider is
		// active for both web requests and CLI/cron preview generation —
		// the legacy `IPreview::registerProviderV2()` call in boot() only
		// covered some of those code paths.
		$context->registerPreviewProvider(ElpxPreviewProvider::class, ElpxPreviewProvider::MIME_REGEX);

		// The capability-token service needs the instance secret as a plain
		// string (it is kept OCP-free for unit testing), so it cannot be
		// auto-wired — provide a factory that reads it from IConfig.
		$context->registerService(ContentTokenService::class, static function (ContainerInterface $c): ContentTokenService {
			return new ContentTokenService((string)$c->get(IConfig::class)->getSystemValue('secret', ''));
		});

		// IframeSandbox is OCP-free (so it stays unit-testable) and by default
		// reads its dev-only escape hatches straight from the process env. We
		// override the default wiring with a reader that ALSO consults a
		// Nextcloud app-config value — the ONLY way to flip the hatch inside the
		// php-wasm Playground, which cannot set process env vars. See
		// {@see self::iframeSandboxEnvReader()} for the (unit-tested) precedence
		// rules. IConfig lives here in the factory, never inside IframeSandbox.
		$context->registerService(IframeSandbox::class, static function (ContainerInterface $c): IframeSandbox {
			$config = $c->get(IConfig::class);
			return new IframeSandbox(self::iframeSandboxEnvReader(
				getenv(...),
				static fn (string $key): string => $config->getAppValue(self::APP_ID, $key, ''),
			));
		});
	}

	/**
	 * Build the environment reader {@see IframeSandbox} uses to resolve its
	 * dev-only escape hatches (`EXELEARNING_UNSAFE_LEGACY_IFRAME`,
	 * `EXELEARNING_EMBED_OPEN`).
	 *
	 * Precedence:
	 *   1. The process environment wins — the real-host mechanism (a systemd
	 *      `Environment=`, an Apache `SetEnv`, the wp-exelearning mu-plugin
	 *      `putenv`, or Moodle `$CFG` phpconstants). An explicitly-empty env
	 *      var still wins and therefore disables the hatch.
	 *   2. Otherwise fall back to a Nextcloud app-config value under this app
	 *      (`EXELEARNING_UNSAFE_LEGACY_IFRAME` -> `unsafe_legacy_iframe`,
	 *      `EXELEARNING_EMBED_OPEN` -> `embed_open`). This exists ONLY because
	 *      the php-wasm Nextcloud Playground cannot set process env vars, yet
	 *      the browser-only demo still needs to flip the hatch so the
	 *      same-origin viewer — the one a Service Worker can actually serve —
	 *      renders. The blueprint sets it with a `setConfig`/`config:app:set`
	 *      step.
	 *
	 * DEV-ONLY: enabling the legacy hatch re-introduces `allow-same-origin` and
	 * drops the opaque-origin isolation that protects against the published
	 * package's untrusted scripts. NEVER set it on a real deployment.
	 *
	 * The reader is a pure function of the two injected lookups so it stays
	 * unit-testable without a Nextcloud server; it is the only seam that knows
	 * app-config exists, keeping IframeSandbox itself OCP-free.
	 *
	 * @param callable(string):(string|false) $getenv Process-env lookup (getenv()).
	 * @param callable(string):string $getAppValue App-config lookup, already
	 *                                             bound to this app id; '' means unset.
	 * @return callable(string):?string
	 */
	public static function iframeSandboxEnvReader(callable $getenv, callable $getAppValue): callable {
		return static function (string $name) use ($getenv, $getAppValue): ?string {
			$env = $getenv($name);
			if ($env !== false) {
				return (string)$env;
			}
			$key = str_starts_with($name, 'EXELEARNING_') ? substr($name, 12) : $name;
			$value = $getAppValue(strtolower($key));
			return $value === '' ? null : $value;
		};
	}

	public function boot(IBootContext $context): void {
		// Register the Viewer handler as an init script. Init scripts are
		// emitted by the server in <head>, so the handler is available before
		// the Viewer app probes for MIME associations.
		unset($context);
		Util::addInitScript(self::APP_ID, 'exelearning-main');
	}
}
