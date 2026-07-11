<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\Preview\PreviewSessionApi;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * AUTHENTICATED, owner-scoped management API for editor preview sessions
 * (serving contract v2). This is the ONLY authenticated surface of the preview
 * feature; the bytes are served separately by the authless {@see PreviewController}.
 *
 * Called by the embedded editor same-origin, so — unlike the public serving
 * route — normal Nextcloud CSRF protection stays ON (these actions are NOT
 * `#[NoCSRFRequired]`), mirroring `editor#save`. Ownership is scoped to the
 * authenticated {@see IUserSession} user id.
 *
 * All protocol semantics live in {@see PreviewSessionApi} (OCP-free); this
 * controller only reads the multipart request and the authenticated user, then
 * maps the {@see \OCA\ExeLearning\Service\Preview\ApiResult} onto a DataResponse.
 */
class PreviewSessionController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PreviewSessionApi $api,
	) {
		parent::__construct($appName, $request);
	}

	/** POST /apps/exelearning/api/preview-session */
	#[NoAdminRequired]
	public function create(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$result = $this->api->create($userId);
		return new DataResponse($result->body, $result->status);
	}

	/**
	 * POST /apps/exelearning/api/preview-session/{previewId}/assets
	 *
	 * multipart: `assets` = JSON `[{ key, size }]`, `files[]` index-aligned.
	 */
	#[NoAdminRequired]
	public function uploadAssets(string $previewId): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$declared = $this->decodeJsonParam('assets');
		$files = $this->uploadedFileBytes('files');
		$entries = [];
		foreach ($declared as $index => $meta) {
			if (!is_array($meta)) {
				continue;
			}
			$entries[] = [
				'key' => (string)($meta['key'] ?? ''),
				'declaredSize' => (int)($meta['size'] ?? -1),
				'bytes' => $files[$index] ?? '',
			];
		}
		$result = $this->api->uploadAssets($previewId, $userId, $entries);
		return new DataResponse($result->body, $result->status);
	}

	/**
	 * POST /apps/exelearning/api/preview-session/{previewId}/revisions
	 *
	 * multipart: `revision` = JSON `{ baseRevision, nextRevision, writes:[paths],
	 * deletes, assetRefs, fixedRefs }`, `files[]` index-aligned with `writes`.
	 */
	#[NoAdminRequired]
	public function publishRevision(string $previewId): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$revision = $this->decodeJsonParam('revision');
		$files = $this->uploadedFileBytes('files');
		$writePaths = is_array($revision['writes'] ?? null) ? $revision['writes'] : [];
		$writes = [];
		foreach (array_values($writePaths) as $index => $path) {
			$writes[] = ['path' => (string)$path, 'bytes' => $files[$index] ?? ''];
		}
		$meta = [
			'baseRevision' => (int)($revision['baseRevision'] ?? -1),
			'nextRevision' => (int)($revision['nextRevision'] ?? -1),
			'writes' => $writes,
			'deletes' => is_array($revision['deletes'] ?? null) ? array_values($revision['deletes']) : [],
			'assetRefs' => is_array($revision['assetRefs'] ?? null) ? $revision['assetRefs'] : [],
			'fixedRefs' => is_array($revision['fixedRefs'] ?? null) ? $revision['fixedRefs'] : [],
		];
		$result = $this->api->publishRevision($previewId, $userId, $meta);
		return new DataResponse($result->body, $result->status);
	}

	/** DELETE /apps/exelearning/api/preview-session/{previewId} */
	#[NoAdminRequired]
	public function delete(string $previewId): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->unauthenticated();
		}
		$result = $this->api->delete($previewId, $userId);
		return new DataResponse($result->body, $result->status);
	}

	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user === null ? null : $user->getUID();
	}

	private function unauthenticated(): DataResponse {
		return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}

	/**
	 * Decode a JSON-string form field into an array (empty array on absence or
	 * malformed JSON).
	 *
	 * @return array<mixed>
	 */
	private function decodeJsonParam(string $name): array {
		$raw = $this->request->getParam($name);
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Read the `files[]` multipart parts (index-aligned) into a list of byte
	 * strings. Handles both the array shape (`files[]`, multiple parts) and the
	 * scalar shape (a single part).
	 *
	 * @return list<string>
	 */
	private function uploadedFileBytes(string $field): array {
		$uploaded = $this->request->getUploadedFile($field);
		if (!is_array($uploaded) || !isset($uploaded['tmp_name'])) {
			return [];
		}
		$tmpNames = $uploaded['tmp_name'];
		if (!is_array($tmpNames)) {
			$tmpNames = [$tmpNames];
		}
		$bytes = [];
		foreach ($tmpNames as $tmpName) {
			if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
				$bytes[] = '';
				continue;
			}
			$content = file_get_contents($tmpName);
			$bytes[] = $content === false ? '' : $content;
		}
		return $bytes;
	}
}
