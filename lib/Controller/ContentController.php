<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\ContentTokenService;
use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\EmbedShimInjector;
use OCA\ExeLearning\Service\IframeSandbox;
use OCA\ExeLearning\Service\PackageMimeService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Serves PUBLISHED .elpx content into an **opaque-origin** iframe over a real
 * HTTP capability URL.
 *
 * Unlike {@see AssetController} (authenticated, same-origin, Service-Worker
 * fallback), this route is a cookieless `#[PublicPage]`: the caller presents a
 * short-lived {@see ContentTokenService} token minted by {@see ViewController}
 * at view-open (where the user's read permission was checked). The response
 * carries a response-level sandbox CSP ({@see IframeSandbox}) that makes the
 * document opaque, and HTML documents get the eXe-core embed shim inlined so
 * external media (YouTube/Vimeo/PDF) can be relayed to the trusted parent.
 *
 * A Service Worker cannot back an opaque origin, so this path never uses one.
 */
class ContentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContentTokenService $tokens,
		private readonly ElpxPackageService $packageService,
		private readonly ZipEntryService $zipEntries,
		private readonly PackageMimeService $mime,
		private readonly IframeSandbox $sandbox,
		private readonly EmbedShimInjector $shimInjector,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /apps/exelearning/content/{token}/{path}
	 *
	 * The frontend always builds a full entry path (defaulting to index.html),
	 * so a single `{path}` route with a `.+` requirement covers the package
	 * index and every subresource/subpage.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function serve(string $token, string $path = 'index.html'): DataDisplayResponse {
		$capability = $this->tokens->verify($token);
		if ($capability === null) {
			return $this->notFound();
		}
		[$fileId, $userId] = $capability;
		$entry = $this->zipEntries->normalizeEntry($path);
		if ($entry === null) {
			return $this->notFound();
		}
		try {
			// Resolve via the token's user so the user's storage is mounted and
			// the read permission is re-checked (the token proves a past grant).
			$file = $this->packageService->getForUserById($userId, $fileId);
		} catch (NotFoundException|NotPermittedException) {
			return $this->notFound();
		}
		$bytes = $this->zipEntries->readEntry($file, $entry);
		if ($bytes === null) {
			return $this->notFound();
		}

		$mime = $this->mime->detect($entry);
		if ($this->mime->isHtmlDocument($mime)) {
			$shim = $this->shimSource();
			if ($shim !== null) {
				$bytes = $this->shimInjector->injectIntoHead($bytes, $shim);
			}
		}

		$response = new DataDisplayResponse($bytes, Http::STATUS_OK, ['Content-Type' => $mime]);
		$this->harden($response);
		if ($this->mime->isHtmlDocument($mime)) {
			$response->addHeader('Content-Security-Policy', $this->sandbox->contentSecurityPolicy());
		} elseif ($this->mime->isLockedXml($mime)) {
			$response->addHeader('Content-Security-Policy', $this->sandbox->svgCsp());
		}
		return $response;
	}

	/**
	 * Inline shim source, or null when the mirror asset is not present. Read
	 * from src/embed/ at runtime, the same way {@see SwController} reads
	 * src/sw/exelearning-sw.js — the app ships its src/ tree.
	 */
	private function shimSource(): ?string {
		$path = __DIR__ . '/../../src/embed/exe_embed_shim.js';
		if (!is_file($path)) {
			return null;
		}
		$source = file_get_contents($path);
		return $source === false ? null : $source;
	}

	/** 404 with the mandatory hardening headers (present on every response). */
	private function notFound(): DataDisplayResponse {
		$response = new DataDisplayResponse('Not Found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		$this->harden($response);
		return $response;
	}

	private function harden(DataDisplayResponse $response): void {
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Permissions-Policy', $this->sandbox->permissionsPolicy());
		// Cookieless capability origin — safe to allow any reader, never with credentials.
		$response->addHeader('Access-Control-Allow-Origin', '*');
	}
}
