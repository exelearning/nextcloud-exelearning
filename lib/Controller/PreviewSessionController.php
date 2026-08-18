<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\Preview\PreviewSnapshotStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * AUTHENTICATED, owner-scoped management API for the opaque editor preview.
 *
 * This is the ONLY authenticated surface of the preview feature; the bytes are
 * served separately by the authless {@see PreviewController}. Called by the
 * embedded editor same-origin, so — unlike the public serving route — normal
 * Nextcloud CSRF protection stays ON (these actions are NOT `#[NoCSRFRequired]`),
 * mirroring `editor#save`. Ownership is scoped to the authenticated
 * {@see IUserSession} user id.
 *
 * Two endpoints, because the editor sends the whole project each time rather
 * than patching it:
 *
 *   POST   {basePath}/api/preview-session              multipart: snapshot=<zip>,
 *                                                      previewId? -> { previewId }
 *   DELETE {basePath}/api/preview-session/{previewId}
 *
 * This replaces the four-operation protocol v2 (create / assets / revisions /
 * delete) that the current editor build no longer speaks.
 */
class PreviewSessionController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PreviewSnapshotStore $store,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * POST {basePath}/api/preview-session — publish a whole-project snapshot.
	 *
	 * `previewId` is absent on the first refresh (mint a capability) and present
	 * afterwards (replace in place). The store refuses an id that is unknown or
	 * owned by somebody else, so it cannot be used to claim another author's
	 * capability.
	 */
	#[NoAdminRequired]
	public function create(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$upload = $this->request->getUploadedFile('snapshot');
		if (!is_array($upload)
			|| (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($upload['tmp_name'] ?? null)
			|| $upload['tmp_name'] === ''
			|| !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['error' => 'Missing snapshot upload'], Http::STATUS_BAD_REQUEST);
		}

		$previewId = $this->request->getParam('previewId');
		$previewId = is_string($previewId) && $previewId !== '' ? $previewId : null;

		$result = $this->store->replace($userId, $upload['tmp_name'], $previewId);
		if (isset($result['error'])) {
			return new DataResponse(['error' => $result['error']], (int)$result['status']);
		}
		// No previewUrl: the client derives it from servingBaseUrl +
		// /{previewId}/index.html, which keeps one source of truth for how a
		// capability URL is shaped.
		return new DataResponse(['previewId' => $result['previewId']], Http::STATUS_OK);
	}

	/**
	 * DELETE {basePath}/api/preview-session/{previewId}
	 *
	 * Owner scoping comes from the same store verdict the publish path uses, so
	 * the two verbs cannot drift: a malformed id is a 400, an unknown capability
	 * a 404 and somebody else's a 403.
	 */
	#[NoAdminRequired]
	public function delete(string $previewId): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$refused = $this->store->deleteOwned($previewId, $userId);
		if ($refused !== null) {
			return new DataResponse(['error' => $refused['error']], (int)$refused['status']);
		}
		return new DataResponse([], Http::STATUS_OK);
	}

	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user === null ? null : $user->getUID();
	}

	private function unauthenticated(): DataResponse {
		return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}
}
