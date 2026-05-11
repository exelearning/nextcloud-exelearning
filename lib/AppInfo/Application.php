<?php

declare(strict_types=1);

namespace OCA\ExeLearning\AppInfo;

use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'exelearning';

	public const ALLOWED_MIME_TYPES = [
		'application/vnd.exelearning.elpx',
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
	}

	public function boot(IBootContext $context): void {
		// Register the Viewer handler as an init script. Init scripts are
		// emitted by the server in <head>, so the handler is available before
		// the Viewer app probes for MIME associations.
		unset($context);
		Util::addInitScript(self::APP_ID, 'exelearning-main');
	}
}
