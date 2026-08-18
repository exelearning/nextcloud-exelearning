<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * DoS bounds for the preview snapshot store.
 *
 * Nextcloud is the one adapter where these matter beyond a single author: a
 * shared instance hosts many users against one temp filesystem, so the store
 * caps what a single user can hold (`maxSnapshotsPerUser`) as well as what the
 * whole feature can occupy (`globalMaxBytes`).
 *
 * The per-asset and batch-size bounds of the retired layered protocol are gone:
 * a snapshot arrives as ONE archive, so a single entry can no longer be sized
 * independently of the archive that carries it.
 */
final class PreviewSnapshotLimits {
	public function __construct(
		public readonly int $ttlSeconds = 30 * 60,
		public readonly int $maxSnapshotsPerUser = 4,
		public readonly int $maxFilesPerSnapshot = 5000,
		public readonly int $maxBytesPerSnapshot = 200 * 1024 * 1024,
		public readonly int $globalMaxBytes = 2048 * 1024 * 1024,
	) {
	}
}
