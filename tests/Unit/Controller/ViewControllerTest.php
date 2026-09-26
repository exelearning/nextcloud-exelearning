<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Controller;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Controller\ViewController;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;
use PHPUnit\Framework\TestCase;

final class ViewControllerTest extends TestCase {
	private IUserSession $session;
	private ElpxPackageService $packages;
	private InitialStateRecorder $initialState;
	private ViewController $controller;

	protected function setUp(): void {
		Util::reset();
		$this->session = $this->createMock(IUserSession::class);
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->initialState = new InitialStateRecorder();
		$this->controller = new ViewController(
			'exelearning',
			$this->createMock(IRequest::class),
			$this->session,
			$this->packages,
			$this->initialState,
		);
	}

	public function testRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);

		$response = $this->controller->index(fileId: 42);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testProvidesFileStateWhenResolvedById(): void {
		$this->authenticate();
		$file = $this->packageFile();
		$this->packages->expects(self::once())
			->method('getForUserById')
			->with('alice', 42)
			->willReturn($file);

		$response = $this->controller->index(fileId: 42);

		self::assertSame([
			'id' => 42,
			'name' => 'lesson.elpx',
			'path' => '/Lessons/lesson.elpx',
			'mtime' => 123456,
			'etag' => 'etag-1',
			'writable' => true,
		], $this->initialState->states['file']);
		$this->assertPageStateAndPolicy($response, 'preview');
	}

	public function testProvidesFileStateWhenResolvedByPathAndEditorMode(): void {
		$this->authenticate();
		$file = $this->packageFile();
		$this->packages->expects(self::once())
			->method('getForUserByPath')
			->with('alice', 'Lessons/lesson.elpx')
			->willReturn($file);

		$response = $this->controller->index(path: 'Lessons/lesson.elpx', mode: 'editor');

		self::assertSame(42, $this->initialState->states['file']['id']);
		$this->assertPageStateAndPolicy($response, 'editor');
	}

	public function testLookupErrorsStillRenderPageWithoutFileState(): void {
		$this->authenticate();
		$this->packages->expects(self::exactly(2))
			->method('getForUserById')
			->willReturnOnConsecutiveCalls(
				self::throwException(new NotFoundException('missing')),
				self::throwException(new NotPermittedException('denied')),
			);

		$missing = $this->controller->index(fileId: 42);
		$denied = $this->controller->index(fileId: 43);

		self::assertSame(Http::STATUS_OK, $missing->getStatus());
		self::assertSame(Http::STATUS_OK, $denied->getStatus());
		self::assertArrayNotHasKey('file', $this->initialState->states);
	}

	public function testPathLookupErrorAndEmptySelectionRenderPreview(): void {
		$this->authenticate();
		$this->packages->method('getForUserByPath')->willThrowException(new NotFoundException('missing'));

		$failed = $this->controller->index(path: 'missing.elpx');
		self::assertSame(Http::STATUS_OK, $failed->getStatus());
		self::assertArrayNotHasKey('file', $this->initialState->states);

		$this->initialState->states = [];
		Util::reset();
		$empty = $this->controller->index(path: '');
		$this->assertPageStateAndPolicy($empty, 'preview');
	}

	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	private function packageFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('lesson.elpx');
		$file->method('getPath')->willReturn('/Lessons/lesson.elpx');
		$file->method('getMTime')->willReturn(123456);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('isUpdateable')->willReturn(true);
		return $file;
	}

	private function assertPageStateAndPolicy(object $response, string $mode): void {
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(false, $this->initialState->states['editorAvailable']);
		self::assertSame($mode, $this->initialState->states['initialMode']);
		self::assertSame([[Application::APP_ID, 'exelearning-view']], Util::$scripts);

		$policy = $response->getContentSecurityPolicy();
		self::assertNotNull($policy);
		self::assertSame(["'self'"], $policy->workerSrc);
		self::assertSame(["'self'"], $policy->scriptDomains);
		self::assertSame(["'self'"], $policy->connectDomains);
		self::assertSame(["'self'"], $policy->frameDomains);
	}
}

final class InitialStateRecorder implements IInitialState {
	/** @var array<string, mixed> */
	public array $states = [];

	public function provideInitialState(string $key, mixed $value): void {
		$this->states[$key] = $value;
	}
}
