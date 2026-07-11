<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * DoS bounds for the preview session store. Reference defaults match eXe core
 * (`DEFAULT_PREVIEW_SESSION_LIMITS`) and the serving contract: 30-min idle TTL,
 * 4 sessions/user, 5000 files/session, 200 MiB/session, 128 MiB/asset,
 * 2 GiB global.
 */
final class PreviewSessionLimits {
	public function __construct(
		public readonly int $ttlSeconds = 30 * 60,
		public readonly int $maxSessionsPerUser = 4,
		public readonly int $maxFilesPerSession = 5000,
		public readonly int $maxBytesPerSession = 200 * 1024 * 1024,
		public readonly int $maxAssetBytes = 128 * 1024 * 1024,
		public readonly int $globalMaxBytes = 2048 * 1024 * 1024,
		public readonly int $recommendedBatchBytes = 64 * 1024 * 1024,
	) {
	}

	/**
	 * The subset advertised to the client in the create-session response so it
	 * can size its own upload batches without guessing.
	 *
	 * @return array{maxFilesPerSession:int,maxBytesPerSession:int,maxAssetBytes:int,recommendedBatchBytes:int}
	 */
	public function forWire(): array {
		return [
			'maxFilesPerSession' => $this->maxFilesPerSession,
			'maxBytesPerSession' => $this->maxBytesPerSession,
			'maxAssetBytes' => $this->maxAssetBytes,
			'recommendedBatchBytes' => $this->recommendedBatchBytes,
		];
	}
}
