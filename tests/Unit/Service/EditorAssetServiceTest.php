<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\EditorAssetService;
use PHPUnit\Framework\TestCase;

final class EditorAssetServiceTest extends TestCase {
	private string $dir;
	private string $indexPath;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/exelearning-assets-' . bin2hex(random_bytes(8));
		$this->indexPath = $this->dir . '/index.html';
	}

	protected function tearDown(): void {
		@unlink($this->indexPath);
		@rmdir($this->dir);
	}

	public function testReportsMissingEditor(): void {
		$service = new EditorAssetService($this->indexPath);

		self::assertFalse($service->isAvailable());
		self::assertNull($service->readIndexHtml());
	}

	public function testReadsThePresentEditorIndex(): void {
		mkdir($this->dir, 0o777, true);
		file_put_contents($this->indexPath, '<html>editor</html>');

		$service = new EditorAssetService($this->indexPath);

		self::assertTrue($service->isAvailable());
		self::assertSame('<html>editor</html>', $service->readIndexHtml());
	}

	public function testTreatsADirectoryAsMissingRatherThanReadingIt(): void {
		// is_file() is deliberate: a stray js/editor/index.html/ directory must
		// read as "not installed", not blow up in file_get_contents().
		mkdir($this->indexPath, 0o777, true);

		$service = new EditorAssetService($this->indexPath);

		self::assertFalse($service->isAvailable());
		self::assertNull($service->readIndexHtml());

		rmdir($this->indexPath);
	}

	public function testDefaultsToTheBundledEditorPath(): void {
		// No argument: the production path, whose presence depends on whether
		// `make download-editor` has run. Only assert that it resolves into the
		// app's own js/editor/, never that the bundle exists.
		$service = new EditorAssetService();
		$reflected = new \ReflectionProperty($service, 'editorIndexPath');

		self::assertStringEndsWith('js/editor/index.html', $reflected->getValue($service));
	}
}
