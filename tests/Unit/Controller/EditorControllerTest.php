<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\Controller\EditorController;
use OCA\ExeLearning\Service\EditorAssetService;
use OCA\ExeLearning\Service\EditorHtmlService;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\LegacyFileMigrationService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class EditorControllerTest extends TestCase {
	private IRequest $request;
	private IUserSession $session;
	private ElpxPackageService $packages;
	private LegacyFileMigrationService $legacyMigration;
	private EditorHtmlService $editorHtml;
	private IURLGenerator $urlGenerator;
	private EditorController $controller;
	private string $editorIndexPath;
	private string $editorDir;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->legacyMigration = $this->createMock(LegacyFileMigrationService::class);
		$this->editorHtml = $this->createMock(EditorHtmlService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkTo')->willReturn('/custom_apps/exelearning/');
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/exelearning/editor/iframe');
		// A private temp directory, never the repository's own js/editor/: the
		// real bundle is present on any checkout where `make download-editor`
		// has run, and writing to it made these tests both order-dependent and
		// destructive if a case aborted before tearDown restored the file.
		$this->editorDir = sys_get_temp_dir() . '/exelearning-editor-' . bin2hex(random_bytes(8));
		$this->editorIndexPath = $this->editorDir . '/index.html';
		$this->controller = new EditorController(
			'exelearning',
			$this->request,
			$this->session,
			$this->packages,
			$this->legacyMigration,
			$this->editorHtml,
			$this->urlGenerator,
			new EditorAssetService($this->editorIndexPath),
		);
	}

	protected function tearDown(): void {
		@unlink($this->editorIndexPath);
		@rmdir($this->editorDir);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function legacyIndexLinks(): iterable {
		yield 'by id' => [['fileId' => 42], ['mode' => 'editor', 'fileId' => 42]];
		yield 'by path' => [['path' => 'lesson.elpx'], ['mode' => 'editor', 'path' => 'lesson.elpx']];
		yield 'bare' => [[], ['mode' => 'editor']];
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $expectedParams
	 * @dataProvider legacyIndexLinks
	 */
	public function testIndexRedirectsLegacyLinksToTheViewPage(array $args, array $expectedParams): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::once())
			->method('linkToRoute')
			->with('exelearning.view.index', $expectedParams)
			->willReturn('/apps/exelearning/view?mode=editor');
		$controller = new EditorController(
			'exelearning',
			$this->request,
			$this->session,
			$this->packages,
			$this->legacyMigration,
			$this->editorHtml,
			$urlGenerator,
			new EditorAssetService($this->editorIndexPath),
		);

		$response = $controller->index(...$args);

		self::assertSame('/apps/exelearning/view?mode=editor', $response->getRedirectURL());
	}

	public function testSaveRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->save(42)->getStatus());
	}

	public function testSaveMapsLookupErrors(): void {
		$user = $this->authenticate();
		$this->packages->expects(self::exactly(2))
			->method('getForUserById')
			->with($user->getUID(), 42)
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->save(42)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->save(42)->getStatus());
	}

	public function testSaveRejectsReadOnlyFile(): void {
		$this->authenticate();
		$this->packages->method('getForUserById')->willReturn($this->packageFile(updateable: false));

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->save(42)->getStatus());
	}

	public function testSaveRejectsMissingUpload(): void {
		$this->authenticate();
		$this->packages->method('getForUserById')->willReturn($this->packageFile());
		$this->request->method('getUploadedFile')->with('package')->willReturn(null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->save(42)->getStatus());
	}

	public function testIframeRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->iframe()->getStatus());
	}

	public function testIframeReportsMissingEditor(): void {
		$this->authenticate();

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->iframe()->getStatus());
	}

	public function testIframeDelegatesHtmlPreparationAndSetsSecurityHeaders(): void {
		$this->authenticate();
		$this->writeEditorIndex('<html><head></head><body>Editor</body></html>');
		$this->editorHtml->expects(self::once())
			->method('prepare')
			->with(
				'<html><head></head><body>Editor</body></html>',
				'/custom_apps/exelearning/js/editor/',
			)
			->willReturn('<html>prepared</html>');

		$response = $this->controller->iframe();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('<html>prepared</html>', $response->getData());
		self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
		self::assertStringContainsString("frame-ancestors 'self'", $response->getHeaders()['Content-Security-Policy']);
		self::assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
		self::assertSame('private, no-cache', $response->getHeaders()['Cache-Control']);
	}

	private function authenticate(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}

	private function packageFile(bool $updateable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('lesson.elpx');
		$file->method('getPath')->willReturn('/Lessons/lesson.elpx');
		$file->method('getMTime')->willReturn(123456);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('isUpdateable')->willReturn($updateable);
		return $file;
	}

	private function writeEditorIndex(string $html): void {
		if (!is_dir($this->editorDir)) {
			mkdir($this->editorDir, 0o777, true);
		}
		file_put_contents($this->editorIndexPath, $html);
	}
}
