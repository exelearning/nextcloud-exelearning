<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Best-effort migration from the legacy .elp extension to .elpx after save.
 */
class LegacyFileMigrationService {
	public function __construct(
		private readonly ElpxPackageService $packageService,
	) {
	}

	public function migrate(File $file, string $userId, int $fileId): File {
		$name = $file->getName();
		if (!str_ends_with(strtolower($name), '.elp') || str_ends_with(strtolower($name), '.elpx')) {
			return $file;
		}

		$base = substr($name, 0, -4);
		try {
			$parent = $file->getParent();
		} catch (NotFoundException|NotPermittedException) {
			return $file;
		}

		$candidate = $base . '.elpx';
		for ($i = 2; $i < 100 && $parent->nodeExists($candidate); $i++) {
			$candidate = sprintf('%s (%d).elpx', $base, $i);
		}
		if ($parent->nodeExists($candidate)) {
			return $file;
		}

		try {
			$file->move($parent->getPath() . '/' . $candidate);
		} catch (NotPermittedException|InvalidPathException) {
			return $file;
		}

		try {
			return $this->packageService->getForUserById($userId, $fileId);
		} catch (NotFoundException|NotPermittedException) {
			return $file;
		}
	}
}
