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
}
