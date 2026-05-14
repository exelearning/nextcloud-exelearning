<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

use OCA\ExeLearning\AppInfo\Application;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * Centralises MIME / extension / permission checks for .elpx files so the
 * controllers never duplicate (or skip) them.
 */
class PermissionService {
	private const ELPX_EXTENSIONS = ['elpx', 'elp'];

	/**
	 * The package size hard limit (bytes). Files larger than this are rejected
	 * up front so we never stream gigabytes through PHP into the browser.
	 */
	public const MAX_PACKAGE_SIZE_BYTES = 250 * 1024 * 1024;

	public function isElpxFile(Node $node): bool {
		if (!($node instanceof File)) {
			return false;
		}
		$name = strtolower($node->getName());
		foreach (self::ELPX_EXTENSIONS as $ext) {
			if (str_ends_with($name, '.' . $ext)) {
				return true;
			}
		}
		// Only accept MIME-based matches for genuinely vendor-specific
		// types. `application/zip` and `application/octet-stream` would
		// drag every plain ZIP / unknown-binary in the user's Files into
		// our preview provider, which is the bug behind issue #21.
		$mime = strtolower((string)$node->getMimeType());
		return in_array($mime, Application::VENDOR_MIME_TYPES, true);
	}

	public function isReadable(Node $node): bool {
		return ($node->getPermissions() & Constants::PERMISSION_READ) !== 0;
	}

	public function isWithinSizeLimit(File $file): bool {
		return $file->getSize() <= self::MAX_PACKAGE_SIZE_BYTES;
	}
}
