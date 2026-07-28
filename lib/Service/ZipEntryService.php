<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

use OCP\Files\File;
use RuntimeException;
use ZipArchive;

/**
 * Minimal PHP-side ZIP entry reader. Used to extract `screenshot.png` for the
 * preview provider and (optionally) by `AssetController` if the Service
 * Worker route is not viable for some deployments.
 *
 * It never parses `content.xml` or other editor-owned assets.
 */
class ZipEntryService {
	public const MAX_ENTRIES = 5000;
	public const MAX_UNCOMPRESSED_SIZE_BYTES = 500 * 1024 * 1024;

	/**
	 * The limits are injectable so tests can exercise them with small
	 * archives; production callers rely on the defaults.
	 */
	public function __construct(
		private readonly int $maxEntries = self::MAX_ENTRIES,
		private readonly int $maxUncompressedSizeBytes = self::MAX_UNCOMPRESSED_SIZE_BYTES,
	) {
	}

	/**
	 * Reads a single entry from the package. Returns the raw bytes or null if
	 * the entry is not present.
	 *
	 * Path traversal entries (`..`, absolute paths) are rejected.
	 */
	public function readEntry(File $file, string $entry): ?string {
		$normalized = $this->normalizeEntry($entry);
		if ($normalized === null) {
			return null;
		}

		$localPath = $file->getStorage()->getLocalFile($file->getInternalPath());
		if (!is_string($localPath) || $localPath === '') {
			return $this->readEntryFromStream($file, $normalized);
		}

		$zip = new ZipArchive();
		if ($zip->open($localPath) !== true) {
			// Some virtual storage implementations (notably the php-wasm
			// Playground filesystem) expose a nominal local path that native
			// ZipArchive still cannot open. Treat it like any other non-local
			// storage and retry through the portable File::fopen() path.
			return $this->readEntryFromStream($file, $normalized);
		}
		try {
			return $this->extractEntry($zip, $normalized);
		} finally {
			$zip->close();
		}
	}

	/**
	 * Reads one entry from an opened archive. Both the local-path branch and
	 * the stream fallback go through here so they enforce identical limits
	 * and agree on returning null for missing entries.
	 */
	private function extractEntry(ZipArchive $zip, string $entry): ?string {
		if ($zip->numFiles > $this->maxEntries) {
			return null;
		}
		$stat = $zip->statName($entry);
		if ($stat === false) {
			return null;
		}
		$declaredSize = $stat['size'];
		if ($declaredSize > $this->maxUncompressedSizeBytes) {
			throw new RuntimeException('Uncompressed entry exceeds limit');
		}
		// getFromName() allocates its whole $len buffer up front, so the read
		// must be bounded by the (already limit-checked) declared size rather
		// than the limit itself. The extra byte exposes archives whose
		// central directory understates the real entry size.
		$contents = $zip->getFromName($entry, $declaredSize + 1);
		if ($contents === false) {
			return null;
		}
		if (strlen($contents) > $declaredSize) {
			throw new RuntimeException('Entry is larger than its declared size');
		}
		return $contents;
	}

	/**
	 * Lists entry names (no contents). Useful for validating that a package
	 * is shaped like an eXeLearning project.
	 *
	 * @return string[]
	 */
	public function listEntries(File $file): array {
		$localPath = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($localPath === false || $localPath === null) {
			return [];
		}
		$zip = new ZipArchive();
		if ($zip->open($localPath) !== true) {
			return [];
		}
		$entries = [];
		try {
			$count = min($zip->numFiles, $this->maxEntries);
			for ($i = 0; $i < $count; $i++) {
				$name = $zip->getNameIndex($i);
				if (is_string($name)) {
					$entries[] = $name;
				}
			}
		} finally {
			$zip->close();
		}
		return $entries;
	}

	/**
	 * Returns the canonical form of an entry name or null if the entry is
	 * unsafe (path traversal, absolute path).
	 */
	public function normalizeEntry(string $entry): ?string {
		$entry = ltrim($entry, '/\\');
		$entry = str_replace('\\', '/', $entry);
		if ($entry === '' || str_contains($entry, "\0")) {
			return null;
		}
		$parts = explode('/', $entry);
		foreach ($parts as $part) {
			if ($part === '..' || $part === '.') {
				return null;
			}
		}
		return $entry;
	}

	/**
	 * Fallback for storages that cannot expose a local file (object storage,
	 * external mounts). Copies the package to a temp file first.
	 */
	private function readEntryFromStream(File $file, string $entry): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'elpx_');
		if ($tmp === false) {
			return null;
		}
		try {
			$source = $file->fopen('rb');
			$dest = fopen($tmp, 'wb');
			if (!is_resource($source) || !is_resource($dest)) {
				return null;
			}
			try {
				stream_copy_to_stream($source, $dest);
			} finally {
				is_resource($source) && fclose($source);
				is_resource($dest) && fclose($dest);
			}
			$zip = new ZipArchive();
			if ($zip->open($tmp) !== true) {
				return null;
			}
			try {
				return $this->extractEntry($zip, $entry);
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($tmp);
		}
	}
}
