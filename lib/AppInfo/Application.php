<?php

declare(strict_types=1);

namespace OCA\ExeLearning\AppInfo;

use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCA\ExeLearning\Service\ContentTokenService;
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
	}

	public function boot(IBootContext $context): void {
		// Register the Viewer handler as an init script. Init scripts are
		// emitted by the server in <head>, so the handler is available before
		// the Viewer app probes for MIME associations.
		unset($context);
		Util::addInitScript(self::APP_ID, 'exelearning-main');
	}
}
