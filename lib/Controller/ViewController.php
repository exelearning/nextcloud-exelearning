<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * Full-page preview of an `.elpx` package. Renders the package's internal
 * `index.html` inside a sandboxed iframe served by our Service Worker, with
 * a thin toolbar that exposes a pencil "Edit" button for switching to the
 * eXeLearning editor.
 *
 * The Viewer modal handler is intentionally not used: its built-in chrome
 * (play / three-dots / close) cannot be customised, and we want a clean
 * full-bleed preview with a single Edit affordance.
 */
class ViewController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(?int $fileId = null, ?string $path = null, ?string $mode = null): TemplateResponse|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$file = null;
		if ($fileId !== null && $fileId > 0) {
			try {
				$file = $this->packageService->getForUserById($user->getUID(), $fileId);
			} catch (NotFoundException|NotPermittedException) {
				$file = null;
			}
		} elseif ($path !== null && $path !== '') {
			try {
				$file = $this->packageService->getForUserByPath($user->getUID(), $path);
			} catch (NotFoundException|NotPermittedException) {
				$file = null;
			}
		}

		if ($file !== null) {
			$this->initialState->provideInitialState('file', [
				'id' => $file->getId(),
				'name' => $file->getName(),
				'path' => $file->getPath(),
				'mtime' => $file->getMTime(),
				'etag' => $file->getEtag(),
				'writable' => $file->isUpdateable(),
			]);
		}
		$this->initialState->provideInitialState(
			'editorAvailable',
			is_file(__DIR__ . '/../../js/editor/index.html'),
		);
		$this->initialState->provideInitialState(
			'editorIframeUrl',
			$this->urlGenerator->linkToRoute(Application::APP_ID . '.editor.iframe'),
		);
		$this->initialState->provideInitialState(
			'initialMode',
			$mode === 'editor' ? 'editor' : 'preview',
		);

		Util::addScript(Application::APP_ID, 'exelearning-view');

		$response = new TemplateResponse(Application::APP_ID, 'view', [], TemplateResponse::RENDER_AS_USER);

		// The Service Worker that serves package assets is loaded from
		// /apps/exelearning/sw.js (same origin). Nextcloud's strict-dynamic
		// script-src blocks it unless we explicitly allow it as a worker.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedWorkerSrcDomain("'self'");
		$csp->addAllowedScriptDomain("'self'");
		$csp->addAllowedConnectDomain("'self'");
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
