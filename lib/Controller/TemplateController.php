<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the bundled "blank" eXeLearning package used by the
 * Files-app "New > eXeLearning resource" entry.
 *
 * The bytes live at `lib/Resources/blank.elpx` so they are reachable from
 * PHP but NOT directly URL-accessible — Nextcloud's `.htaccess` rewrites
 * unknown paths through `index.php`, and `lib/` is not on the app's
 * static-asset allowlist. Routing through a controller also gives us the
 * usual logged-in-user check and a stable `Content-Type` regardless of
 * how Apache/Nginx are configured.
 */
class TemplateController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function blank(): DataResponse|StreamResponse {
		if ($this->userSession->getUser() === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$path = __DIR__ . '/../Resources/blank.elpx';
		if (!is_file($path)) {
			return new DataResponse(['error' => 'Blank template missing'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$handle = fopen($path, 'rb');
		if ($handle === false) {
			return new DataResponse(['error' => 'Cannot read blank template'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new StreamResponse($handle);
		$response->addHeader('Content-Type', 'application/vnd.exelearning.elpx');
		$response->addHeader('Content-Length', (string)filesize($path));
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, max-age=300');
		return $response;
	}
}
