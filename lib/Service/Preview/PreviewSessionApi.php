<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * Management-API policy for preview sessions (the authenticated, owner-scoped
 * surface: create / upload assets / publish revision / delete).
 *
 * Pure logic over {@see PreviewSessionStore} + {@see FixedResourceManifest}: it
 * performs the ownership gate (404 for a missing session, 403 for someone else's)
 * and maps the store's semantic results onto the contract's wire bodies (the
 * create-session `protocolVersion`/`revision`/`limits` block, `409
 * revision-conflict`, `422 missing-assets` / `unknown-fixed-resources`). It
 * returns {@see ApiResult} value objects so the full protocol is unit-testable
 * and vector-replayable without a Nextcloud server;
 * {@see \OCA\ExeLearning\Controller\PreviewSessionController} is the thin adapter
 * that reads the multipart request and the authenticated user id.
 */
final class PreviewSessionApi {
	/** Serving-contract protocol version negotiated at session creation. */
	public const PROTOCOL_VERSION = 2;

	public function __construct(
		private readonly PreviewSessionStore $store,
		private readonly FixedResourceManifest $fixed,
	) {
	}

	/** Create a session owned by $ownerUserId. */
	public function create(string $ownerUserId): ApiResult {
		$previewId = $this->store->create($ownerUserId);
		return new ApiResult(201, [
			'previewId' => $previewId,
			'protocolVersion' => self::PROTOCOL_VERSION,
			'revision' => 0,
			'limits' => $this->store->limits()->forWire(),
		]);
	}

	/**
	 * Upload session assets (immutable per key).
	 *
	 * @param list<array{key:string,declaredSize:int,bytes:string}> $entries
	 */
	public function uploadAssets(string $previewId, string $ownerUserId, array $entries): ApiResult {
		$gate = $this->ownershipGate($previewId, $ownerUserId);
		if ($gate !== null) {
			return $gate;
		}
		$result = $this->store->storeAssets($previewId, $entries);
		return new ApiResult(200, $result);
	}

	/**
	 * Publish a revision (atomic, optimistic-concurrency).
	 *
	 * @param array{baseRevision:int,nextRevision:int,writes:list<array{path:string,bytes:string}>,deletes:list<string>,assetRefs:array<string,string>,fixedRefs:array<string,string>} $meta
	 */
	public function publishRevision(string $previewId, string $ownerUserId, array $meta): ApiResult {
		$gate = $this->ownershipGate($previewId, $ownerUserId);
		if ($gate !== null) {
			return $gate;
		}
		$result = $this->store->applyRevision($previewId, $meta, $this->fixed);
		return match ($result['status']) {
			200 => new ApiResult(200, ['revision' => $result['revision'], 'active' => true]),
			409 => new ApiResult(409, ['reason' => 'revision-conflict', 'currentRevision' => $result['currentRevision']]),
			422 => new ApiResult(422, $this->unprocessableBody($result)),
			413 => new ApiResult(413, ['error' => $result['message'] ?? 'Preview storage budget exceeded']),
			500 => new ApiResult(500, ['error' => $result['message'] ?? 'Failed to persist revision']),
			default => new ApiResult(400, ['error' => $result['message'] ?? 'Bad request']),
		};
	}

	/** Delete a session. */
	public function delete(string $previewId, string $ownerUserId): ApiResult {
		$gate = $this->ownershipGate($previewId, $ownerUserId);
		if ($gate !== null) {
			return $gate;
		}
		$this->store->delete($previewId);
		return new ApiResult(200, ['success' => true]);
	}

	/**
	 * 404 for a missing session, 403 for someone else's, null when the caller
	 * owns it.
	 */
	private function ownershipGate(string $previewId, string $ownerUserId): ?ApiResult {
		$owner = $this->store->ownerOf($previewId);
		if ($owner === null) {
			return new ApiResult(404, ['error' => 'Preview session not found']);
		}
		if ($owner !== $ownerUserId) {
			return new ApiResult(403, ['error' => 'Access denied']);
		}
		return null;
	}

	/**
	 * @param array{reason?:string,missing?:list<string>,resources?:list<string>} $result
	 * @return array<string,mixed>
	 */
	private function unprocessableBody(array $result): array {
		if (($result['reason'] ?? '') === 'missing-assets') {
			return ['reason' => 'missing-assets', 'missing' => $result['missing'] ?? []];
		}
		return ['reason' => 'unknown-fixed-resources', 'resources' => $result['resources'] ?? []];
	}
}
