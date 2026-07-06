<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * AUTHLESS, cookieless serving endpoint for the eXeLearning **editor preview**
 * of untrusted, unsaved author content, served from an opaque origin.
 *
 * This mirrors the canonical contract in eXe core:
 *   `doc/development/preview-serving-contract.md` (repo exelearning/exelearning).
 * Core is authoritative. In particular the sandbox CSP in {@see self::SANDBOX_CSP}
 * MUST stay **byte-identical** to the string in that document — do not re-order,
 * re-quote or reformat it.
 *
 * Unlike {@see AssetController} (authenticated, same-origin, published content),
 * this route is a `#[PublicPage]` capability URL: knowledge of the `previewId`
 * UUID is the only bearer of access. It never emits a session cookie and always
 * responds with `Access-Control-Allow-Origin: *` (sound only because the origin
 * is cookieless). The editor preview must be served from here and **never**
 * same-origin or through the Service Worker.
 *
 * NOTE: the content-addressed per-session store and the authenticated
 * management API (create session / manifest / blobs / delete) are follow-up
 * work — see the class TODOs. Store lookup is stubbed below.
 */
class PreviewController extends Controller {
	/** v4 UUID; anything else is a hard 404. */
	private const PREVIEW_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Document types that can execute script and therefore MUST carry the
	 * sandbox CSP. Match against the MIME with any `; charset=…` stripped.
	 */
	private const SCRIPTABLE_TYPES = [
		'text/html',
		'image/svg+xml',
		'application/xml',
		'application/xhtml+xml',
	];

	/**
	 * VERBATIM sandbox-first CSP from eXe core preview-serving-contract.md.
	 * Keep byte-identical. Ideally hoist into one shared constant reused by the
	 * published-content path — kept inline here to keep the skeleton readable.
	 */
	private const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';";

	public function __construct(
		string $appName,
		IRequest $request,
		// TODO(follow-up): inject PreviewSessionStore here once it exists.
		//   private readonly PreviewSessionStore $store,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET {previewBasePath}/preview/{previewId}/{path}
	 *
	 * Route (appinfo/routes.php), following the `asset#fetch` pattern:
	 *   [
	 *     'name' => 'preview#serve',
	 *     'url'  => '/preview/{previewId}/{path}',
	 *     'verb' => 'GET',
	 *     'requirements' => [
	 *       'previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
	 *       'path'      => '.+',
	 *     ],
	 *     'defaults' => ['path' => 'index.html'],
	 *   ]
	 * plus a sibling route for the bare `/preview/{previewId}/` root that also
	 * defaults `path` to `index.html`.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serve(string $previewId, string $path = 'index.html'): DataDisplayResponse {
		// 1. Validate the capability UUID. Invalid → 404 (still hardened).
		if (preg_match(self::PREVIEW_ID_RE, $previewId) !== 1) {
			return $this->notFound();
		}

		// 2. Resolve the blob from the per-session content-addressed store.
		//    The store owns path-safety (reuse ZipEntryService::normalizeEntry
		//    semantics), idle-TTL expiry, caps, and returns the *real* MIME it
		//    recorded when the blob was re-hashed on upload.
		$blob = $this->lookup($previewId, $path);
		if ($blob === null) {
			return $this->notFound();
		}
		[$bytes, $mime] = $blob;

		// 3. Serve with the hardening header set; add the sandbox CSP only on
		//    scriptable document types.
		$response = new DataDisplayResponse($bytes, Http::STATUS_OK, ['Content-Type' => $mime]);
		$this->harden($response);
		if ($this->isScriptable($mime)) {
			$response->addHeader('Content-Security-Policy', self::SANDBOX_CSP);
		}
		return $response;
	}

	/**
	 * Store lookup. Returns `[bytes, mime]` or null when the session/blob is
	 * missing, expired, or was quarantined for a hash mismatch.
	 *
	 * TODO(follow-up): replace with PreviewSessionStore. The real store:
	 *   - refreshes the session idle TTL (default 30 min) on each hit,
	 *   - normalises `$path` (reject `..`/`.`/absolute/NUL — see
	 *     ZipEntryService::normalizeEntry),
	 *   - maps the manifest path to a SHA-256-addressed blob,
	 *   - returns the MIME captured at upload time (never sniffed here).
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function lookup(string $previewId, string $path): ?array {
		// Stub: no store wired yet.
		unset($previewId, $path);
		return null;
	}

	/** 404 body with the full hardening header set (contract: headers on 404 too). */
	private function notFound(): DataDisplayResponse {
		$response = new DataDisplayResponse('Not Found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		$this->harden($response);
		return $response;
	}

	/** Applies the mandatory hardening headers required on every response. */
	private function harden(DataDisplayResponse $response): void {
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
		// Opaque, cookieless origin — safe to allow any reader, never with credentials.
		$response->addHeader('Access-Control-Allow-Origin', '*');
	}

	private function isScriptable(string $mime): bool {
		$type = strtolower(trim(explode(';', $mime, 2)[0]));
		return in_array($type, self::SCRIPTABLE_TYPES, true);
	}
}