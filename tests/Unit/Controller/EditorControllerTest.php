<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Controller\EditorController;
use OCA\ExeLearning\Service\EditorHtmlService;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\LegacyFileMigrationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;
use PHPUnit\Framework\TestCase;

final class EditorControllerTest extends TestCase {
	private IRequest $request;
	private IUserSession $session;
	private ElpxPackageService $packages;
	private LegacyFileMigrationService $legacyMigration;
	private EditorHtmlService $editorHtml;
	private EditorInitialStateRecorder $initialState;
	private IURLGenerator $urlGenerator;
	private EditorController $controller;
	private string $editorIndexPath;
	private ?string $originalEditorIndex = null;
	private bool $editorIndexExisted = false;

	protected function setUp(): void {
		Util::reset();
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->legacyMigration = $this->createMock(LegacyFileMigrationService::class);
		$this->editorHtml = $this->createMock(EditorHtmlService::class);
		$this->initialState = new EditorInitialStateRecorder();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkTo')->willReturn('/custom_apps/exelearning/');
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/exelearning/editor/iframe');
		$this->controller = new EditorController(
			'exelearning',
			$this->request,
			$this->session,
			$this->packages,
			$this->legacyMigration,
			$this->editorHtml,
			$this->initialState,
			$this->urlGenerator,
		);
		$this->editorIndexPath = dirname(__DIR__, 3) . '/js/editor/index.html';
		$this->editorIndexExisted = is_file($this->editorIndexPath);
		if ($this->editorIndexExisted) {
			$contents = file_get_contents($this->editorIndexPath);
			$this->originalEditorIndex = is_string($contents) ? $contents : null;
		}
	}

	protected function tearDown(): void {
		if ($this->editorIndexExisted) {
			if ($this->originalEditorIndex !== null) {
				file_put_contents($this->editorIndexPath, $this->originalEditorIndex);
			}
			return;
		}

		@unlink($this->editorIndexPath);
		@rmdir(dirname($this->editorIndexPath));
	}

	public function testIndexRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);

		$response = $this->controller->index(fileId: 42);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testIndexProvidesFileAndEditorStateById(): void {
		$this->authenticate();
		$file = $this->packageFile();
		$this->packages->expects(self::once())
			->method('getForUserById')
			->with('alice', 42)
			->willReturn($file);

		$response = $this->controller->index(fileId: 42);

		self::assertSame(42, $this->initialState->states['file']['id']);
		self::assertSame('lesson.elpx', $this->initialState->states['file']['name']);
		$this->assertEditorPageState($response);
	}

	public function testIndexCanResolveByPathAndIgnoreLookupErrors(): void {
		$this->authenticate();
		$file = $this->packageFile();
		$this->packages->expects(self::exactly(3))
			->method('getForUserByPath')
			->willReturnOnConsecutiveCalls(
				$file,
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		self::assertSame(Http::STATUS_OK, $this->controller->index(path: 'lesson.elpx')->getStatus());
		$this->initialState->states = [];
		Util::reset();
		self::assertSame(Http::STATUS_OK, $this->controller->index(path: 'missing.elpx')->getStatus());
		$this->initialState->states = [];
		Util::reset();
		self::assertSame(Http::STATUS_OK, $this->controller->index(path: 'denied.elpx')->getStatus());
	}

	public function testIndexIgnoresIdLookupErrors(): void {
		$this->authenticate();
		$this->packages->expects(self::exactly(2))
			->method('getForUserById')
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		self::assertSame(Http::STATUS_OK, $this->controller->index(fileId: 42)->getStatus());
		self::assertSame(Http::STATUS_OK, $this->controller->index(fileId: 43)->getStatus());
	}

	public function testSaveRejectsAuthenticationLookupReadOnlyAndMissingUpload(): void {
		$this->session->method('getUser')->willReturn(null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->save(42)->getStatus());

		$user = $this->authenticate();
		$this->packages->expects(self::exactly(3))
			->method('getForUserById')
			->with($user->getUID(), 42)
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
				$this->packageFile(updateable: false),
			);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->save(42)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->save(42)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->save(42)->getStatus());

		$file = $this->packageFile();
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->packages->method('getForUserById')->willReturn($file);
		$this->request->method('getUploadedFile')->with('package')->willReturn(null);
		$this->controller = new EditorController(
			'exelearning',
			$this->request,
			$this->session,
			$this->packages,
			$this->legacyMigration,
			$this->editorHtml,
			$this->initialState,
			$this->urlGenerator,
		);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->save(42)->getStatus());
	}

	public function testIframeRequiresAuthenticationAndReportsMissingEditor(): void {
		$this->session->method('getUser')->willReturn(null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->iframe()->getStatus());

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

	private function assertEditorPageState(object $response): void {
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(false, $this->initialState->states['editorAvailable']);
		self::assertSame('/custom_apps/exelearning/js/editor', $this->initialState->states['editorBasePath']);
		self::assertSame('/apps/exelearning/editor/iframe', $this->initialState->states['editorIframeUrl']);
		self::assertSame([[Application::APP_ID, 'exelearning-editor']], Util::$scripts);
		$policy = $response->getContentSecurityPolicy();
		self::assertNotNull($policy);
		self::assertSame(["'self'"], $policy->scriptDomains);
		self::assertSame(["'self'"], $policy->connectDomains);
		self::assertSame(["'self'"], $policy->frameDomains);
	}

	private function writeEditorIndex(string $html): void {
		$dir = dirname($this->editorIndexPath);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($this->editorIndexPath, $html);
	}
}

final class EditorInitialStateRecorder implements IInitialState {
	/** @var array<string, mixed> */
	public array $states = [];

	public function provideInitialState(string $key, mixed $value): void {
		$this->states[$key] = $value;
	}
}
