<?php

declare(strict_types=1);

namespace OCA\ExeLearning\AppInfo;

use OCA\ExeLearning\Preview\ElpxPreviewProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IPreview;
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
		// Services and controllers are auto-resolved via PSR-4 + constructor
		// injection. Nothing else to register here yet.
	}

	public function boot(IBootContext $context): void {
		// Register the Viewer handler as an init script. Init scripts are
		// emitted by the server in <head>, so the handler is available before
		// the Viewer app probes for MIME associations.
		Util::addInitScript(self::APP_ID, 'exelearning-main');

		// Register the preview provider so screenshot.png can act as the
		// Nextcloud thumbnail. The regex must match each allowed MIME literal.
		try {
			/** @var IPreview $previewManager */
			$previewManager = $context->getServerContainer()->get(IPreview::class);
			$previewManager->registerProviderV2(
				ElpxPreviewProvider::MIME_REGEX,
				static function () use ($context): ElpxPreviewProvider {
					return $context->getAppContainer()->get(ElpxPreviewProvider::class);
				}
			);
		} catch (\Throwable $e) {
			// Preview API differences across Nextcloud versions: failing to
			// register the provider must never break the app boot path. The
			// viewer fallback (icon) still works.
		}
	}
}
