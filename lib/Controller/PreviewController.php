<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\Preview\PreviewResponse;
use OCA\ExeLearning\Service\Preview\PreviewServer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * AUTHLESS, cookieless serving endpoint for the eXeLearning **editor preview**
 * of untrusted, unsaved author content, served from an opaque origin
 * (serving contract v2 — docs/preview-serving-contract.md, canonical spec in
 * eXe core `doc/development/preview-serving-contract.md`).
 *
 * Unlike {@see AssetController} (authenticated, same-origin, published content),
 * this route is a `#[PublicPage]` capability URL: knowledge of the `previewId`
 * UUID is the only bearer of access. It never emits a session cookie and always
 * responds `Access-Control-Allow-Origin: *` (sound only because the origin is
 * cookieless). The editor preview must be served from here and never same-origin
 * or through the Service Worker (a Service Worker cannot back an opaque origin).
 *
 * All protocol and header policy lives in {@see PreviewServer} (OCP-free and
 * vector-replayable); this controller is a thin adapter that copies the
 * resulting {@see PreviewResponse} onto a `DataDisplayResponse`. Nextcloud
 * serves unknown extensions as `text/plain`, so Content-Type is always set
 * explicitly.
 */
class PreviewController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PreviewServer $server,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /apps/exelearning/preview/{previewId}/{path}
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serve(string $previewId, string $path = 'index.html'): DataDisplayResponse {
		$response = $this->server->serve(
			$previewId,
			$path,
			$this->headerOrNull('If-None-Match'),
			$this->headerOrNull('Range'),
		);
		return $this->toDataDisplay($response);
	}

	/**
	 * GET /apps/exelearning/preview/{previewId}
	 *
	 * The bare capability root resolves to `index.html` (the `.+` path route
	 * above cannot match an empty segment).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serveRoot(string $previewId): DataDisplayResponse {
		return $this->serve($previewId, 'index.html');
	}

	/** Map the framework-agnostic response onto a DataDisplayResponse. */
	private function toDataDisplay(PreviewResponse $response): DataDisplayResponse {
		$headers = $response->headers;
		$contentType = $headers['Content-Type'] ?? 'application/octet-stream';
		unset($headers['Content-Type']);
		$result = new DataDisplayResponse($response->body, $response->status, ['Content-Type' => $contentType]);
		foreach ($headers as $name => $value) {
			$result->addHeader($name, $value);
		}
		return $result;
	}

	/** A request header value, or null when it is absent/empty. */
	private function headerOrNull(string $name): ?string {
		$value = (string)$this->request->getHeader($name);
		return $value === '' ? null : $value;
	}
}
