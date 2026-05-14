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
 * package and returns it as the preview image. When the entry is missing
 * or unreadable, falls back to the bundled `img/elpx-preview-fallback.png`
 * (a document outline with the eXeLearning logo) so the Files grid still
 * shows a recognisable thumbnail instead of the generic ZIP icon.
 *
 * `content.xml` is never parsed here — see AGENTS.md.
 */
class ElpxPreviewProvider implements IProviderV2 {
	/**
	 * Regex that matches every MIME alias an `.elp(x)` file may carry on
	 * disk. We intentionally cast a wide net here (`zip`, `octet-stream`)
	 * so Nextcloud asks us about every candidate file; the actual
	 * "is this really an eXeLearning archive" gate runs in
	 * {@see isAvailable()} via {@see PermissionService::isElpxFile()},
	 * which now requires either a `.elp(x)` extension or a vendor-
	 * specific MIME (issue #21).
	 */
	public const MIME_REGEX = '/^application\/(vnd\.exelearning\.elpx|x-exelearning(-legacy)?|zip|octet-stream)$/';

	/** Bundled bitmap returned when the package has no screenshot.png. */
	private const FALLBACK_IMAGE = __DIR__ . '/../../img/elpx-preview-fallback.png';

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
			if ($bytes !== null) {
				$image = new Image();
				$image->loadFromData($bytes);
				if ($image->valid()) {
					$image->scaleDownToFit($maxX, $maxY);
					return $image;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug('eXeLearning preview failed', [
				'app' => 'exelearning',
				'exception' => $e,
				'file' => $file->getName(),
			]);
		}
		return $this->loadFallbackThumbnail($maxX, $maxY);
	}

	/**
	 * Loads the bundled preview fallback. Failures are swallowed and `null`
	 * is returned so Nextcloud falls back to the MIME icon as it did before
	 * the fallback existed.
	 * @param int $maxX Maximum width allowed for the thumbnail.
	 * @param int $maxY Maximum height allowed for the thumbnail.
	 */
	private function loadFallbackThumbnail(int $maxX, int $maxY): ?IImage {
		try {
			$bytes = @file_get_contents(self::FALLBACK_IMAGE);
			if ($bytes === false) {
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
			$this->logger->debug('eXeLearning preview fallback unavailable', [
				'app' => 'exelearning',
				'exception' => $e,
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
