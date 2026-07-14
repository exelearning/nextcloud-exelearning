<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\PreviewSnapshotStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Manages complete editor-preview snapshots and serves them through expiring
 * capability URLs. The core editor loads these URLs only in an iframe sandbox
 * without `allow-same-origin`, giving the preview document an opaque origin.
 */
class PreviewController extends Controller {
	/** v4 UUID; anything else is a hard 404. */
	private const PREVIEW_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Document types that can execute script and therefore MUST carry the
	 * sandbox CSP. Match against the MIME with any `; charset=…` stripped.
	 */
	private const SCRIPTABLE_TYPES = [
		'text/html',
		'image/svg+xml',
		'application/xml',
		'application/xhtml+xml',
	];

	/** Defence-in-depth sandbox for directly opened scriptable resources. */
	private const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PreviewSnapshotStore $store,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
	) {
		parent::__construct($appName, $request);
	}

	/** Serves one file from an authless capability snapshot. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serve(string $previewId, string $path = 'index.html'): DataDisplayResponse {
		if (preg_match(self::PREVIEW_ID_RE, $previewId) !== 1) {
			return $this->notFound();
		}

		$blob = $this->store->get($previewId, $path);
		if ($blob === null) {
			return $this->notFound();
		}
		$bytes = $blob['bytes'];
		$mime = $blob['mime'];

		$response = new DataDisplayResponse($bytes, Http::STATUS_OK, ['Content-Type' => $mime]);
		$this->harden($response);
		if ($this->isScriptable($mime)) {
			$response->addHeader('Content-Security-Policy', self::SANDBOX_CSP);
		}
		return $response;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function serveRoot(string $previewId): DataDisplayResponse {
		return $this->serve($previewId, 'index.html');
	}

	#[NoAdminRequired]
	public function replace(int $fileId, ?string $previewId = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->packageService->getForUserById($user->getUID(), $fileId);
		} catch (NotFoundException|NotPermittedException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$file->isUpdateable()) {
			return new DataResponse(['error' => 'Read-only'], Http::STATUS_FORBIDDEN);
		}
		$upload = $this->request->getUploadedFile('snapshot');
		if (!is_array($upload) || !isset($upload['tmp_name']) || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['error' => 'Missing snapshot'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$id = $this->store->replace($user->getUID(), (string)$fileId, $upload['tmp_name'], $previewId);
		} catch (\InvalidArgumentException|\LengthException $error) {
			return new DataResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\UnexpectedValueException) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		} catch (\RuntimeException $error) {
			return new DataResponse(['error' => $error->getMessage()], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['previewId' => $id]);
	}

	#[NoAdminRequired]
	public function delete(int $fileId, string $previewId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->packageService->getForUserById($user->getUID(), $fileId);
		} catch (NotFoundException|NotPermittedException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
		try {
			$deleted = $this->store->delete($user->getUID(), (string)$fileId, $previewId);
		} catch (\UnexpectedValueException) {
			return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse([], $deleted ? Http::STATUS_NO_CONTENT : Http::STATUS_NOT_FOUND);
	}

	/** 404 body with the full hardening header set (contract: headers on 404 too). */
	private function notFound(): DataDisplayResponse {
		$response = new DataDisplayResponse('Not Found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		$this->harden($response);
		return $response;
	}

	/** Applies the mandatory hardening headers required on every response. */
	private function harden(DataDisplayResponse $response): void {
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
		// The public capability route never depends on credentials.
		$response->addHeader('Access-Control-Allow-Origin', '*');
	}

	private function isScriptable(string $mime): bool {
		$type = strtolower(trim(explode(';', $mime, 2)[0]));
		return in_array($type, self::SCRIPTABLE_TYPES, true);
	}
}
