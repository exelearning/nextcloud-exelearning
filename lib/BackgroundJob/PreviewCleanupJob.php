<?php

declare(strict_types=1);

namespace OCA\ExeLearning\BackgroundJob;

use OCA\ExeLearning\Service\Preview\PreviewSessionStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Periodically sweeps idle-expired preview sessions from disk (serving contract
 * v2, TTL bound). Registered in appinfo/info.xml `<background-jobs>`. A
 * belt-and-braces measure: the serving route also drops an expired session
 * opportunistically on access, so preview URLs stop resolving at the TTL even if
 * cron is starved.
 */
class PreviewCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly PreviewSessionStore $store,
	) {
		parent::__construct($time);
		// Sessions have a 30-min idle TTL; sweeping every 10 min bounds the
		// window in which an expired session's files linger on disk.
		$this->setInterval(10 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->store->sweepExpired();
	}
}
