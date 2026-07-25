<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

use RuntimeException;
use ZipArchive;

/**
 * The trust boundary for an uploaded preview archive: decides whether a ZIP may
 * be extracted at all, then extracts it under a real-byte budget.
 *
 * Kept apart from {@see PreviewSnapshotStore} on purpose — the store's job is to
 * publish a tree atomically, this one's is to refuse an archive before a single
 * byte reaches the disk. These rules are what stands between untrusted author
 * content and the server filesystem, so they are worth reading in one place.
 *
 * Two passes, and the order matters:
 *
 *  1. {@see inspect()} vets EVERY entry up front. A limit noticed halfway
 *     through would leave a partially written tree behind, so nothing is written
 *     until the whole archive has passed.
 *  2. {@see extract()} streams each entry out and counts the bytes it ACTUALLY
 *     writes. The uncompressed sizes in a ZIP's central directory are supplied
 *     by whoever built the archive, so pass 1's byte total is an early reject,
 *     never the enforcement — a zip bomb that under-declares its sizes is caught
 *     here instead.
 */
final class SnapshotArchive {
	/**
	 * Vet every entry of an open archive.
	 *
	 * @throws RuntimeException When the archive must not be extracted.
	 */
	public static function inspect(ZipArchive $zip, PreviewSnapshotLimits $limits): void {
		if ($zip->numFiles > $limits->maxFilesPerSnapshot) {
			throw new RuntimeException('Preview archive contains too many files.');
		}
		$declared = 0;
		$hasIndex = false;
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$entry = self::inspectEntry($zip, $index);
			if ($entry === null) {
				continue; // directory
			}
			$declared += $entry['size'];
			if ($declared > $limits->maxBytesPerSnapshot) {
				throw new RuntimeException('Preview archive is too large.');
			}
			$hasIndex = $hasIndex || $entry['name'] === 'index.html';
		}
		if (!$hasIndex) {
			throw new RuntimeException('Preview archive must contain index.html.');
		}
	}

	/**
	 * Extract a vetted archive into $targetDir, enforcing the byte budget on the
	 * bytes actually written.
	 *
	 * Entries are streamed rather than handed to `extractTo()` so the budget can
	 * be enforced mid-file: an entry that inflates past the cap is abandoned
	 * as soon as the cap is crossed, not after it has filled the disk.
	 *
	 * @return int Bytes written — the caller needs the total and this already
	 *             counted every one of them.
	 * @throws RuntimeException When extraction fails or exceeds the budget.
	 */
	public static function extract(ZipArchive $zip, string $targetDir, PreviewSnapshotLimits $limits): int {
		$written = 0;
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			if (str_ends_with($name, '/')) {
				continue;
			}
			$destination = $targetDir . '/' . $name;
			$parent = dirname($destination);
			if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
				throw new RuntimeException('Could not create a preview directory.');
			}
			$written += self::extractEntry($zip, $index, $destination, $limits->maxBytesPerSnapshot - $written);
		}
		return $written;
	}

	/**
	 * Stream one entry to disk, stopping if it would exceed $remaining bytes.
	 *
	 * @return int Bytes written for this entry.
	 * @throws RuntimeException
	 */
	private static function extractEntry(ZipArchive $zip, int $index, string $destination, int $remaining): int {
		$source = $zip->getStream((string)$zip->getNameIndex($index));
		if (!is_resource($source)) {
			throw new RuntimeException('Could not read the preview archive.');
		}
		$target = @fopen($destination, 'wb');
		if (!is_resource($target)) {
			fclose($source);
			throw new RuntimeException('Could not write the preview snapshot.');
		}
		$written = 0;
		try {
			while (!feof($source)) {
				$chunk = fread($source, 131072);
				if ($chunk === false) {
					throw new RuntimeException('Could not read the preview archive.');
				}
				$written += strlen($chunk);
				if ($written > $remaining) {
					throw new RuntimeException('Preview archive is too large.');
				}
				if ($chunk !== '' && fwrite($target, $chunk) === false) {
					throw new RuntimeException('Could not write the preview snapshot.');
				}
			}
		} finally {
			fclose($source);
			fclose($target);
		}
		return $written;
	}

	/**
	 * Vet a single entry.
	 *
	 * @return array{name:string,size:int}|null Null for a directory.
	 * @throws RuntimeException When the entry is unsafe.
	 */
	private static function inspectEntry(ZipArchive $zip, int $index): ?array {
		$name = $zip->getNameIndex($index);
		$stat = $zip->statIndex($index);
		$isDirectory = is_string($name) && str_ends_with($name, '/');
		$validated = $isDirectory ? rtrim((string)$name, '/') : (string)$name;
		if (!is_string($name) || !is_array($stat) || !self::isSafePath($validated)) {
			throw new RuntimeException('Unsafe path in the preview archive.');
		}
		if ($isDirectory) {
			return null;
		}
		if (self::isSymlink($zip, $index)) {
			throw new RuntimeException('The preview archive contains a symbolic link.');
		}
		return ['name' => $name, 'size' => (int)($stat['size'] ?? 0)];
	}

	/**
	 * Whether an entry is a Unix symbolic link.
	 *
	 * A link is stored as a tiny entry whose contents are the target path, so it
	 * passes every size and path check while pointing anywhere on the filesystem.
	 */
	private static function isSymlink(ZipArchive $zip, int $index): bool {
		$opsys = 0;
		$attributes = 0;
		return $zip->getExternalAttributesIndex($index, $opsys, $attributes)
			&& $opsys === ZipArchive::OPSYS_UNIX
			&& (($attributes >> 16) & 0xf000) === 0xa000;
	}

	/**
	 * Whether a relative path is canonical and safe.
	 *
	 * There is deliberately no reserved-name list: the store keeps its own
	 * metadata OUTSIDE the extracted tree, so no author path can collide with it.
	 */
	private static function isSafePath(string $path): bool {
		if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
			|| $path[0] === '/') {
			return false;
		}
		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return false;
			}
		}
		return true;
	}
}
