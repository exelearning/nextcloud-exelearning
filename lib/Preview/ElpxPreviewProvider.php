<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Preview;

use OCA\ExeLearning\Service\PermissionService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nextcloud preview provider that extracts `screenshot.png` from an `.elpx`
 * package and returns it as the preview image. If the entry is missing or
 * unreadable, returns null so Nextcloud falls back to the generic MIME icon.
 *
 * `content.xml` is never parsed here — see AGENTS.md.
 */
class ElpxPreviewProvider implements IProviderV2 {
	/**
	 * Regex that matches every MIME alias an .elpx file may carry on disk.
	 * The double backslash is needed because Nextcloud wraps this in `#…#`.
	 */
	public const MIME_REGEX = '/^application\/(vnd\.exelearning\.elpx|x-exelearning|zip|octet-stream)$/';

	public function __construct(
		private readonly ZipEntryService $zipEntries,
		private readonly PermissionService $permissions,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getMimeType(): string {
		return self::MIME_REGEX;
	}

	public function isAvailable(\OCP\Files\FileInfo $file): bool {
		if ($file instanceof File) {
			return $this->permissions->isElpxFile($file);
		}
		$name = strtolower($file->getName());
		return str_ends_with($name, '.elpx') || str_ends_with($name, '.elp');
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		try {
			$bytes = $this->zipEntries->readEntry($file, 'screenshot.png');
			if ($bytes === null) {
				return null;
			}
			$image = new Image();
			$image->loadFromData($bytes);
			if (!$image->valid()) {
				return null;
			}
			$image->scaleDownToFit($maxX, $maxY);
			return $image;
		} catch (Throwable $e) {
			$this->logger->debug('eXeLearning preview failed', [
				'app' => 'exelearning',
				'exception' => $e,
				'file' => $file->getName(),
			]);
			return null;
		}
	}

	/**
	 * Older Nextcloud versions check the file via {@see ISimpleFile}. We do
	 * not implement that path; the new API uses {@see getThumbnail}.
	 *
	 * @internal kept for source-compatibility with deployments that still
	 *           call into legacy preview hooks.
	 */
	public function getCroppedThumbnail(File $file, int $maxX, int $maxY, bool $crop): ?ISimpleFile {
		// Cropping is not implemented — the image returned by getThumbnail is
		// already constrained to the requested size.
		unset($file, $maxX, $maxY, $crop);
		return null;
	}
}
