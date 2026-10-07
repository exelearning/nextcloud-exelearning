<?php

declare(strict_types=1);

namespace OCA\ExeLearning\BackgroundJob;

use OCA\ExeLearning\Service\Preview\PreviewSnapshotStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Periodically sweeps idle-expired preview snapshots from disk. Registered in
 * appinfo/info.xml `<background-jobs>`. A belt-and-braces measure: the serving
 * route also drops an expired snapshot opportunistically on access, so preview
 * URLs stop resolving at the TTL even if cron is starved.
 */
class PreviewCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly PreviewSnapshotStore $store,
	) {
		parent::__construct($time);
		// Snapshots have a 30-min idle TTL; sweeping every 10 min bounds the
		// window in which an expired session's files linger on disk.
		$this->setInterval(10 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->store->sweepExpired();
	}
}
