<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\AppInfo;

use OCA\ExeLearning\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Sanity checks for the MIME-type constant. If we ever add or rename a MIME
 * here, the Viewer handler (`src/main.ts`) and the file actions must also be
 * updated; the JS-side test in `tests/js/files-mime.test.ts` covers that
 * side, this test pins the PHP-side.
 */
final class ApplicationConstantsTest extends TestCase {
	public function testAllowedMimeTypesIncludesPrimaryVendorMime(): void {
		self::assertContains('application/vnd.exelearning.elpx', Application::ALLOWED_MIME_TYPES);
	}

	public function testAllowedMimeTypesIncludesLegacyZipFallback(): void {
		self::assertContains('application/zip', Application::ALLOWED_MIME_TYPES);
		self::assertContains('application/octet-stream', Application::ALLOWED_MIME_TYPES);
	}

	public function testAllowedMimeTypesIncludesLegacyVendorMime(): void {
		self::assertContains(Application::LEGACY_MIME_TYPE, Application::ALLOWED_MIME_TYPES);
		self::assertSame('application/x-exelearning-legacy', Application::LEGACY_MIME_TYPE);
	}

	/**
	 * VENDOR_MIME_TYPES is the narrow list used by
	 * {@see \OCA\ExeLearning\Service\PermissionService::isElpxFile()} to
	 * decide whether to claim a file by MIME alone. Issue #21 hinged on
	 * `application/zip` leaking into that path; pin it out here so a
	 * future edit cannot regress.
	 */
	public function testVendorMimeTypesDoesNotIncludeGenericArchiveMimes(): void {
		self::assertNotContains('application/zip', Application::VENDOR_MIME_TYPES);
		self::assertNotContains('application/octet-stream', Application::VENDOR_MIME_TYPES);
	}

	public function testVendorMimeTypesCoversBothPackageGenerations(): void {
		self::assertContains(Application::PRIMARY_MIME_TYPE, Application::VENDOR_MIME_TYPES);
		self::assertContains(Application::LEGACY_MIME_TYPE, Application::VENDOR_MIME_TYPES);
	}
}
