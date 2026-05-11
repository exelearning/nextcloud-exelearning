<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the raw `.elpx` bytes to the current user's browser. The frontend
 * uses this to download the package, then extracts it in memory with fflate.
 */
class PackageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function byFileId(int $fileId): DataResponse|StreamResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->error('Not authenticated', Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->packageService->getForUserById($userId, $fileId);
		} catch (NotFoundException) {
			return $this->error('File not found', Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return $this->error($e->getMessage(), Http::STATUS_FORBIDDEN);
		}

		$response = new StreamResponse($file->fopen('rb'));
		$response->addHeader('Content-Type', 'application/vnd.exelearning.elpx');
		$response->addHeader('Content-Length', (string)$file->getSize());
		$response->addHeader('Content-Disposition', 'inline; filename="' . rawurlencode($file->getName()) . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate');
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function byPath(string $path): DataResponse|StreamResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->error('Not authenticated', Http::STATUS_UNAUTHORIZED);
		}
		if ($path === '' || str_contains($path, "\0")) {
			return $this->error('Invalid path', Http::STATUS_BAD_REQUEST);
		}
		try {
			$file = $this->packageService->getForUserByPath($userId, $path);
		} catch (NotFoundException) {
			return $this->error('File not found', Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return $this->error($e->getMessage(), Http::STATUS_FORBIDDEN);
		}

		$response = new StreamResponse($file->fopen('rb'));
		$response->addHeader('Content-Type', 'application/vnd.exelearning.elpx');
		$response->addHeader('Content-Length', (string)$file->getSize());
		$response->addHeader('Content-Disposition', 'inline; filename="' . rawurlencode($file->getName()) . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate');
		return $response;
	}

	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user === null ? null : $user->getUID();
	}

	private function error(string $message, int $status): DataResponse {
		return new DataResponse(['error' => $message], $status);
	}
}
