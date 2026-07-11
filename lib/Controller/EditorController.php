<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OC\Security\CSRF\CsrfTokenManager;
use OCA\ExeLearning\AppInfo\Application;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
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
 * Hosts the optional static eXeLearning editor inside a Nextcloud page. The
 * editor is only available when its bundle has been downloaded to
 * `js/editor/` (run `make download-editor`).
 *
 * The page itself is a thin shell — the editor is loaded into an iframe via
 * `editor-page.ts` using the same postMessage protocol documented by the
 * upstream eXeLearning project.
 */
class EditorController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
		private readonly CsrfTokenManager $csrfTokenManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(?int $fileId = null, ?string $path = null): TemplateResponse|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$editorAvailable = is_file(__DIR__ . '/../../js/editor/index.html');

		if ($fileId !== null && $fileId > 0) {
			try {
				$file = $this->packageService->getForUserById($user->getUID(), $fileId);
				$this->initialState->provideInitialState('file', [
					'id' => $file->getId(),
					'name' => $file->getName(),
					'path' => $file->getPath(),
					'mtime' => $file->getMTime(),
					'etag' => $file->getEtag(),
					'writable' => $file->isUpdateable(),
				]);
			} catch (NotFoundException|NotPermittedException) {
				// fall through: editor still opens, just without a preloaded file
			}
		} elseif ($path !== null && $path !== '') {
			try {
				$file = $this->packageService->getForUserByPath($user->getUID(), $path);
				$this->initialState->provideInitialState('file', [
					'id' => $file->getId(),
					'name' => $file->getName(),
					'path' => $file->getPath(),
					'mtime' => $file->getMTime(),
					'etag' => $file->getEtag(),
					'writable' => $file->isUpdateable(),
				]);
			} catch (NotFoundException|NotPermittedException) {
				// fall through
			}
		}

		// linkTo() returns the right URL whether the app lives at
		// /apps/<id>/ or /custom_apps/<id>/ (see apps_paths in config).
		$editorBasePath = rtrim($this->urlGenerator->linkTo(Application::APP_ID, ''), '/') . '/js/editor';
		// Route URLs always go through Nextcloud's PHP router and live under
		// /apps/<id>/ regardless of apps_paths.
		$editorIframeUrl = $this->urlGenerator->linkToRoute(Application::APP_ID . '.editor.iframe');

		$this->initialState->provideInitialState('editorAvailable', $editorAvailable);
		$this->initialState->provideInitialState('editorBasePath', $editorBasePath);
		$this->initialState->provideInitialState('editorIframeUrl', $editorIframeUrl);

		Util::addScript(Application::APP_ID, 'exelearning-editor');

		// RENDER_AS_USER keeps Nextcloud's script + initial-state injection
		// working. The template's CSS positions the editor root with
		// `position: fixed; inset: 0` so the iframe still uses the whole
		// viewport while Nextcloud's chrome stays loaded (but covered).
		$response = new TemplateResponse(Application::APP_ID, 'editor', [], TemplateResponse::RENDER_AS_USER);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedScriptDomain("'self'");
		$csp->addAllowedConnectDomain("'self'");
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
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
		$file = $this->migrateLegacyExtensionIfNeeded($file, $user->getUID(), $fileId);

		return new DataResponse([
			'id' => $file->getId(),
			'name' => $file->getName(),
			'mtime' => $file->getMTime(),
			'etag' => $file->getEtag(),
		]);
	}

	/**
	 * Renames a `.elp` file to `.elpx` after a successful save so the
	 * Files-app shows the modern extension going forward. Picks
	 * `<base>.elpx`, falling back to `<base> (2).elpx`, `<base> (3).elpx`,
	 * … if a sibling already exists. Returns the (possibly renamed)
	 * file, re-fetched by id since `Node::move` invalidates the cached
	 * handle.
	 */
	private function migrateLegacyExtensionIfNeeded(
		\OCP\Files\File $file,
		string $userId,
		int $fileId,
	): \OCP\Files\File {
		$name = $file->getName();
		if (!str_ends_with(strtolower($name), '.elp') || str_ends_with(strtolower($name), '.elpx')) {
			return $file;
		}
		$base = substr($name, 0, -4); // strip '.elp'
		try {
			$parent = $file->getParent();
		} catch (NotFoundException|NotPermittedException) {
			return $file;
		}
		$candidate = $base . '.elpx';
		for ($i = 2; $i < 100 && $parent->nodeExists($candidate); $i++) {
			$candidate = sprintf('%s (%d).elpx', $base, $i);
		}
		if ($parent->nodeExists($candidate)) {
			return $file;
		}
		try {
			$file->move($parent->getPath() . '/' . $candidate);
		} catch (NotPermittedException|\OCP\Files\InvalidPathException) {
			return $file;
		}
		try {
			return $this->packageService->getForUserById($userId, $fileId);
		} catch (NotFoundException|NotPermittedException) {
			return $file;
		}
	}

	/**
	 * Serves the static eXeLearning editor HTML inside an iframe, with:
	 *
	 *  - `<base href>` pointing at the editor's apps_paths URL so relative
	 *    asset paths (`./libs/...`, `./style/...`) resolve correctly even
	 *    when the app is mounted under `/custom_apps/`.
	 *  - `window.__EXE_EMBEDDING_CONFIG__` populated before any editor
	 *    script runs (the editor's RuntimeConfig reads it during bootstrap),
	 *    including the `previewHttp` block that wires the HTTP editor-preview
	 *    transport (serving contract v2) — see {@see previewHttpConfig()}.
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
		$staticConfig = json_encode([
			'hideUI' => (object)[
				'fileMenu' => true,
				'saveButton' => true,
				'shareButton' => false,
				'userMenu' => true,
				'downloadButton' => false,
				'helpMenu' => false,
			],
		], JSON_UNESCAPED_SLASHES);

		$previewHttp = json_encode($this->previewHttpConfig(), JSON_HEX_TAG);

		$configScript = '<script>(function(){'
			. 'var base=new URL(".",document.baseURI).href.replace(/\\/+$/,"");'
			. 'var origin=window.location.origin;'
			. 'window.__EXE_EMBEDDING_CONFIG__=Object.assign(' . $staticConfig . ','
			. '{basePath:base,parentOrigin:origin,trustedOrigins:[origin],previewHttp:' . $previewHttp . '});'
			. '})();</script>';

		// The resilience shim must be installed before any editor script
		// runs (it wraps fetch / jQuery.ajax / serviceWorker.register), so it
		// goes right after <base> and before the embedding config.
		$resilienceScript = '<script>' . $this->resilienceScript() . '</script>';
		$headInject = '<base href="' . htmlspecialchars($editorBaseHref, ENT_QUOTES) . '">' . $resilienceScript . $configScript;
		if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
			$pos = $m[0][1] + strlen($m[0][0]);
			$html = substr($html, 0, $pos) . $headInject . substr($html, $pos);
		}
		$bridge = '<script>' . $this->bridgeScript() . '</script>';
		$html = str_ireplace('</body>', $bridge . '</body>', $html);

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
	 * The `previewHttp` block handed to the embedded editor so it can drive the
	 * HTTP editor-preview transport (serving contract v2). Shape frozen by the
	 * normalized contract: `{ protocolVersion, managementBaseUrl, servingBaseUrl,
	 * managementHeaders }`.
	 *
	 *  - Both URLs are generated server-side through the router so they carry the
	 *    correct webroot and front-controller prefix under a sub-path install,
	 *    mirroring the client's `@nextcloud/router` `generateUrl`. The serving
	 *    routes are parameterised, so the base is derived by generating the bare
	 *    capability-root URL for a placeholder id and stripping that id segment.
	 *  - `managementHeaders.requesttoken` is the current Nextcloud CSRF token —
	 *    the same encrypted value the standard template layer exposes as
	 *    `data-requesttoken`. It is required because the management routes keep
	 *    CSRF ON (they are NOT `#[NoCSRFRequired]`); the editor replays it on every
	 *    management request.
	 *
	 * Token lifetime: Nextcloud rotates the CSRF token per session. The injected
	 * value stays valid for the whole editor session while the Nextcloud session
	 * is alive, but can go stale across a very long idle (session expiry). A future
	 * refresh path — the parent page re-issuing a CONFIGURE postMessage with a
	 * fresh token — is intentionally out of scope here.
	 *
	 * @return array{protocolVersion:int,managementBaseUrl:string,servingBaseUrl:string,managementHeaders:object}
	 */
	private function previewHttpConfig(): array {
		$managementBaseUrl = $this->urlGenerator->linkToRoute(Application::APP_ID . '.previewSession.create');

		// The serving routes take a `previewId` (and `path`); generate the bare
		// capability-root URL for a placeholder id, then strip the trailing
		// `/{id}` so the client can append `/{previewId}/index.html` itself.
		$sampleId = '00000000-0000-4000-8000-000000000000';
		$sampleUrl = $this->urlGenerator->linkToRoute(
			Application::APP_ID . '.preview.serveRoot',
			['previewId' => $sampleId],
		);
		$servingBaseUrl = str_ends_with($sampleUrl, '/' . $sampleId)
			? substr($sampleUrl, 0, -(strlen($sampleId) + 1))
			: $sampleUrl;

		return [
			'protocolVersion' => 2,
			'managementBaseUrl' => $managementBaseUrl,
			'servingBaseUrl' => $servingBaseUrl,
			'managementHeaders' => (object)[
				'requesttoken' => $this->csrfTokenManager->getToken()->getEncryptedValue(),
			],
		];
	}

	/**
	 * Resilience shim injected into the editor <head> before any editor
	 * script runs. The static eXeLearning editor's ResourceFetcher rejects
	 * on missing CSS / iDevice resources; under the php-wasm Playground
	 * those `files/perm/...` paths 404 (even though they ship in the bundle)
	 * and the unhandled rejection aborts the Yjs theme bind, leaving the
	 * page blank. mod_exelearning, wp-exelearning and omeka-s-exelearning
	 * all ship the same workaround:
	 *
	 *  - swallow 404s on .css / idevices URLs (fetch + jQuery ajax) and
	 *    return an empty stylesheet so the editor keeps booting;
	 *  - neutralize preview-sw.js service-worker registration with a full
	 *    ServiceWorkerRegistration-like stub (a bare `{scope:''}` makes the
	 *    v4 editor throw on `reg.addEventListener` and aborts the hidden
	 *    export iframe used for Web/SCORM/ePub export).
	 */
	private function resilienceScript(): string {
		return <<<'JS'
(function () {
    if ("serviceWorker" in navigator) {
        try {
            navigator.serviceWorker.register = function () {
                return Promise.resolve({
                    scope: "", installing: null, waiting: null, active: null,
                    addEventListener: function () {}, removeEventListener: function () {},
                    update: function () { return Promise.resolve(); },
                    unregister: function () { return Promise.resolve(true); }
                });
            };
        } catch (e) { void e; }
    }

    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (input, init) {
            var url = typeof input === "string" ? input : (input && input.url) || "";
            return originalFetch.apply(this, arguments).then(function (response) {
                if (!response.ok && (url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1)) {
                    console.warn("[Nextcloud] Fetch 404 fallback:", url);
                    return new Response("/* empty fallback */", { status: 200, headers: { "Content-Type": "text/css" } });
                }
                return response;
            }).catch(function (error) {
                if (url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1) {
                    console.warn("[Nextcloud] Fetch error fallback:", url);
                    return new Response("/* empty fallback */", { status: 200, headers: { "Content-Type": "text/css" } });
                }
                throw error;
            });
        };
    }

    var patchJQuery = function ($) {
        if (!$ || !$.ajaxTransport) return;
        $.ajaxTransport("+*", function (options) {
            var url = options.url || "";
            if (!(url.indexOf(".css") !== -1 || url.indexOf("idevices") !== -1)) return;
            return {
                send: function (headers, completeCallback) {
                    var xhr = new XMLHttpRequest();
                    xhr.open(options.type || "GET", url, true);
                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            completeCallback(xhr.status, xhr.statusText, { text: xhr.responseText });
                        } else {
                            console.warn("[Nextcloud] jQuery 404 fallback:", url);
                            completeCallback(200, "OK", { text: "/* empty fallback */" });
                        }
                    };
                    xhr.onerror = function () {
                        console.warn("[Nextcloud] jQuery error fallback:", url);
                        completeCallback(200, "OK", { text: "/* empty fallback */" });
                    };
                    xhr.send();
                },
                abort: function () {}
            };
        });
    };
    if (window.jQuery) {
        patchJQuery(window.jQuery);
    } else {
        try {
            Object.defineProperty(window, "jQuery", {
                configurable: true,
                set: function (val) {
                    Object.defineProperty(window, "jQuery", {
                        configurable: true, writable: true, enumerable: true, value: val
                    });
                    patchJQuery(val);
                },
                get: function () { return undefined; }
            });
        } catch (e) { void e; }
    }
})();
JS;
	}

	private function bridgeScript(): string {
		return <<<'JS'
(() => {
    const send = (msg) => { try { window.parent.postMessage(msg, '*'); } catch (e) { void e; } };
    window.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            send({ type: 'REQUEST_SAVE', requestId: 'nextcloud-exelearning-shortcut-' + Date.now() });
        }
    }, true);

    const waitForReady = () => new Promise((resolve) => {
        const tick = () => {
            const ready = window.eXeLearning && window.eXeLearning.ready;
            if (ready && typeof ready.then === 'function') ready.then(resolve);
            else setTimeout(tick, 50);
        };
        tick();
    });
    waitForReady().then(() => {
        const bridge = window.eXeLearning && window.eXeLearning.app && window.eXeLearning.app.embeddingBridge;
        if (!bridge) return;
        bridge.handleSaveRequest = async function (requestId) {
            const project = this.app.project;
            const yjsBridge = project && project._yjsBridge;
            const documentManager = yjsBridge && yjsBridge.documentManager;
            if (!window.SharedExporters || !documentManager) {
                throw new Error('Exporter unavailable');
            }
            if (typeof documentManager._updateVersionMetadata === 'function') {
                try { await documentManager._updateVersionMetadata(); } catch (_e) { void _e; }
            }
            const exporter = window.SharedExporters.createExporter(
                'elpx', documentManager,
                yjsBridge.assetCache, yjsBridge.resourceFetcher, yjsBridge.assetManager
            );
            const result = await exporter.export({});
            if (!result || !result.success || !result.data) {
                throw new Error((result && result.error) || 'Export failed');
            }
            const data = result.data;
            const bytes = data instanceof ArrayBuffer
                ? data
                : (ArrayBuffer.isView(data)
                    ? data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength)
                    : data);
            this.postToParent({
                type: 'SAVE_FILE', requestId,
                bytes, filename: result.filename || 'project.elpx',
                size: bytes.byteLength,
            });
        };
    });
})();
JS;
	}
}
