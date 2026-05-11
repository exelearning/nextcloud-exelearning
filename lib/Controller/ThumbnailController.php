<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Returns the `screenshot.png` from inside an `.elpx` package, suitable for
 * use as a thumbnail in Files pre-rendering. The Preview provider gets the
 * same data via {@see \OCA\ExeLearning\Preview\ElpxPreviewProvider} — this
 * endpoint exists for the Files action bar.
 */
class ThumbnailController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
		private readonly ZipEntryService $zipEntries,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function byFileId(int $fileId): DataDisplayResponse|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->packageService->getForUserById($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$bytes = $this->zipEntries->readEntry($file, 'screenshot.png');
		if ($bytes === null) {
			return new DataResponse(['error' => 'No screenshot'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($bytes, Http::STATUS_OK, ['Content-Type' => 'image/png']);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, max-age=3600');
		return $response;
	}
}
