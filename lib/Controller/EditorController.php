<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OC\Security\CSRF\CsrfTokenManager;
use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Service\EditorHtmlService;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\LegacyFileMigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Backend for the optional static eXeLearning editor, which is only
 * available when its bundle has been downloaded to `js/editor/` (run
 * `make download-editor`). The view page embeds it through `iframe()` and
 * writes the exported package back through `save()`.
 */
class EditorController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
		private readonly LegacyFileMigrationService $legacyFileMigration,
		private readonly EditorHtmlService $editorHtml,
		private readonly IURLGenerator $urlGenerator,
		private readonly CsrfTokenManager $csrfTokenManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Legacy entry point: the editor now lives inside the view page. Kept as
	 * a redirect so old bookmarks and links still open the file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(?int $fileId = null, ?string $path = null): RedirectResponse {
		$params = ['mode' => 'editor'];
		if ($fileId !== null && $fileId > 0) {
			$params['fileId'] = $fileId;
		} elseif ($path !== null && $path !== '') {
			$params['path'] = $path;
		}
		return new RedirectResponse($this->urlGenerator->linkToRoute(Application::APP_ID . '.view.index', $params));
	}

	#[NoAdminRequired]
	public function save(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$file = $this->packageService->getForUserById($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
		if (!$file->isUpdateable()) {
			return new DataResponse(['error' => 'Read-only'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->request->getUploadedFile('package');
		if (!is_array($body) || !isset($body['tmp_name']) || !is_uploaded_file($body['tmp_name'])) {
			return new DataResponse(['error' => 'Missing upload'], Http::STATUS_BAD_REQUEST);
		}

		$expectedEtag = (string)$this->request->getHeader('If-Match');
		if ($expectedEtag !== '' && trim($expectedEtag, '"') !== (string)$file->getEtag()) {
			return new DataResponse(['error' => 'Conflict: file changed since open'], Http::STATUS_PRECONDITION_FAILED);
		}

		$content = file_get_contents($body['tmp_name']);
		if ($content === false) {
			return new DataResponse(['error' => 'Cannot read upload'], Http::STATUS_BAD_REQUEST);
		}
		$file->putContent($content);

		// Migrate legacy `.elp` filenames to `.elpx` on first save (issue
		// #20). Best-effort: if the rename fails (no permission, name
		// collision we can't resolve, …) we keep the original extension
		// rather than failing the save.
		$file = $this->legacyFileMigration->migrate($file, $user->getUID(), $fileId);

		return new DataResponse([
			'id' => $file->getId(),
			'name' => $file->getName(),
			'mtime' => $file->getMTime(),
			'etag' => $file->getEtag(),
		]);
	}

	/**
	 * Serves the static eXeLearning editor HTML inside an iframe, with:
	 *
	 *  - `<base href>` pointing at the editor's apps_paths URL so relative
	 *    asset paths (`./libs/...`, `./style/...`) resolve correctly even
	 *    when the app is mounted under `/custom_apps/`.
	 *  - `window.__EXE_EMBEDDING_CONFIG__` populated before any editor
	 *    script runs (the editor's RuntimeConfig reads it during bootstrap),
	 *    including the `previewSnapshot` transport used by the opaque preview.
	 *  - A small bridge that forwards Ctrl/Cmd+S to the parent and patches
	 *    `EmbeddingBridge.handleSaveRequest` for the v4.0.0 export quirk.
	 *  - A permissive CSP — eXeLearning has many inline scripts and styles;
	 *    `frame-ancestors 'self'` keeps the editor embeddable only by us.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function iframe(): DataDisplayResponse|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$editorIndexPath = __DIR__ . '/../../js/editor/index.html';
		if (!is_file($editorIndexPath)) {
			return new DataResponse(['error' => 'Editor not installed'], Http::STATUS_NOT_FOUND);
		}
		$html = file_get_contents($editorIndexPath);
		if ($html === false) {
			return new DataResponse(['error' => 'Cannot read editor index'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$editorBaseHref = rtrim($this->urlGenerator->linkTo(Application::APP_ID, ''), '/') . '/js/editor/';

		// `basePath`, `parentOrigin` and `trustedOrigins` are derived client-side
		// from the live document base + window origin rather than baked in here.
		// When Nextcloud is served under a scoped sub-path (e.g. the browser
		// Playground rewrites `<base href>` to the scoped URL), a server-computed
		// basePath would carry an empty webroot and the editor's ResourceFetcher
		// would load its theme/content bundles from an unscoped path that 404s on
		// save. Deriving from `document.baseURI` (which equals the scoped
		// `<base href>` at runtime) keeps it correct in both a normal install and
		// under a scoped path.
		// The editor preview management API keeps CSRF protection enabled. Add
		// its transport config before EditorHtmlService prepends the common
		// embedding config, so both scripts execute before the editor bundle.
		$previewSnapshot = json_encode($this->previewSnapshotConfig(), JSON_HEX_TAG);
		$previewScript = '<script>window.__EXE_EMBEDDING_CONFIG__=Object.assign('
			. 'window.__EXE_EMBEDDING_CONFIG__||{},'
			. '{previewSnapshot:' . $previewSnapshot . '});</script>';
		if (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
			$position = $match[0][1] + strlen($match[0][0]);
			$html = substr($html, 0, $position) . $previewScript . substr($html, $position);
		}

		$html = $this->editorHtml->prepare($html, $editorBaseHref);

		$response = new DataDisplayResponse($html, Http::STATUS_OK, [
			'Content-Type' => 'text/html; charset=utf-8',
		]);
		// Permissive CSP — eXeLearning ships dozens of inline scripts, event
		// handlers and styles. We restrict frame-ancestors to 'self' so only
		// our own page can embed this iframe.
		$response->addHeader('Content-Security-Policy',
			"default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; "
			. "script-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; "
			. "script-src-elem 'self' 'unsafe-inline' data: blob:; "
			. "style-src 'self' 'unsafe-inline' data:; "
			. "style-src-elem 'self' 'unsafe-inline' data:; "
			. "img-src 'self' data: blob:; "
			. "media-src 'self' data: blob:; "
			. "font-src 'self' data:; "
			. "connect-src 'self' data: blob:; "
			. "frame-ancestors 'self'; "
			. "base-uri 'self'"
		);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, no-cache');
		return $response;
	}

	/**
	 * Builds the editor's opaque-preview transport configuration.
	 *
	 * The management routes remain authenticated and CSRF-protected, while the
	 * serving URL is an authless capability path consumed from the opaque iframe.
	 *
	 * @return array{managementUrl:string,servingBaseUrl:string,deleteUrlTemplate:string,managementHeaders:object}
	 */
	private function previewSnapshotConfig(): array {
		$sampleId = '00000000-0000-4000-8000-000000000000';

		$sampleUrl = $this->urlGenerator->linkToRoute(
			Application::APP_ID . '.preview.serveRoot',
			['previewId' => $sampleId],
		);
		$servingBaseUrl = str_ends_with($sampleUrl, '/' . $sampleId)
			? substr($sampleUrl, 0, -(strlen($sampleId) + 1))
			: $sampleUrl;

		$deleteUrlTemplate = str_replace(
			$sampleId,
			'{previewId}',
			$this->urlGenerator->linkToRoute(
				Application::APP_ID . '.previewSession.delete',
				['previewId' => $sampleId],
			),
		);

		return [
			'managementUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.previewSession.create'),
			'servingBaseUrl' => $servingBaseUrl,
			'deleteUrlTemplate' => $deleteUrlTemplate,
			'managementHeaders' => (object)[
				'requesttoken' => $this->csrfTokenManager->getToken()->getEncryptedValue(),
			],
		];
	}

}
