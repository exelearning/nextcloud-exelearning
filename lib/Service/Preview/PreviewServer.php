<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * HTTP serving policy for the authless preview capability URL
 * (`GET {basePath}/preview/{previewId}/{path}`).
 *
 * Pure logic over {@see PreviewSessionStore} + {@see FixedResourceManifest}:
 * it validates the capability id, resolves the three layers, and applies the
 * contract's response policy — the hardening header set on EVERY response
 * (404s included), the tiered `Cache-Control`, `ETag`/`If-None-Match` → 304 and
 * single-range 206/416 on assets, and the sandbox-first CSP on every scriptable
 * document type from any layer. It returns a {@see PreviewResponse} value
 * object so the whole policy is unit-testable and vector-replayable without a
 * Nextcloud server; {@see \OCA\ExeLearning\Controller\PreviewController} is the
 * thin adapter onto `DataDisplayResponse`.
 */
final class PreviewServer {
	public function __construct(
		private readonly PreviewSessionStore $store,
		private readonly FixedResourceManifest $fixed,
	) {
	}

	/**
	 * Serve a preview file.
	 *
	 * @param string|null $ifNoneMatch The request `If-None-Match` header, if any.
	 * @param string|null $range The request `Range` header, if any.
	 */
	public function serve(string $previewId, string $rawPath, ?string $ifNoneMatch = null, ?string $range = null): PreviewResponse {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return $this->notFound();
		}
		$file = $this->store->resolve($previewId, $rawPath, $this->fixed);
		if ($file === null) {
			return $this->notFound();
		}

		return match ($file['kind']) {
			'asset' => $this->serveAsset($file, $ifNoneMatch, $range),
			'fixed' => $this->serveBytes($file, 'private, max-age=31536000'),
			default => $this->serveBytes($file, 'no-store'),
		};
	}

	/**
	 * Bare capability root (`GET {servingBase}/{previewId}`) → 302 redirect to
	 * `{previewId}/index.html`.
	 *
	 * The bare root must NEVER emit index.html bytes: a document served from the
	 * bare URL resolves its relative subresource references against
	 * `{servingBase}/` — dropping the `{previewId}` segment — so every asset,
	 * script and stylesheet 404s. Redirecting to `{previewId}/index.html` rebases
	 * them onto the correct directory. The `Location` is relative so it stays
	 * correct under any Nextcloud webroot (it resolves against the request URI,
	 * `{servingBase}/{previewId}`) without the store needing a URL generator, and
	 * the whole decision stays OCP-free and vector-replayable. Contract v2.1 §4.
	 */
	public function serveRoot(string $previewId): PreviewResponse {
		if (!PreviewPolicy::isValidPreviewId($previewId)) {
			return $this->notFound();
		}
		$headers = $this->baseHeaders();
		$headers['Content-Type'] = 'text/plain; charset=utf-8';
		$headers['Cache-Control'] = 'no-store';
		$headers['Location'] = $previewId . '/index.html';
		return new PreviewResponse(302, $headers, '');
	}

	/**
	 * Document (layer 3, `no-store`) or fixed resource (layer 1, immutable and
	 * cacheable). Both may be scriptable and then carry the sandbox CSP.
	 *
	 * @param array{contentType:string,isScriptable:bool,bytes?:string} $file
	 */
	private function serveBytes(array $file, string $cacheControl): PreviewResponse {
		$headers = $this->baseHeaders();
		$headers['Content-Type'] = $file['contentType'];
		$headers['Cache-Control'] = $cacheControl;
		if ($file['isScriptable']) {
			$headers['Content-Security-Policy'] = PreviewPolicy::CSP;
		}
		return new PreviewResponse(200, $headers, $file['bytes'] ?? '');
	}

	/**
	 * Session project asset (layer 2): revalidated (`no-cache`) with an ETag,
	 * `Accept-Ranges: bytes`, `If-None-Match` → 304 and single-range 206/416.
	 *
	 * @param array{contentType:string,isScriptable:bool,filePath:string,size:int,etag:string} $file
	 */
	private function serveAsset(array $file, ?string $ifNoneMatch, ?string $range): PreviewResponse {
		$etag = '"' . $file['etag'] . '"';

		if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
			$headers = $this->baseHeaders();
			$headers['Cache-Control'] = 'no-cache';
			$headers['ETag'] = $etag;
			return new PreviewResponse(304, $headers, '');
		}

		$size = $file['size'];
		$parsed = ($range !== null && trim($range) !== '') ? $this->parseRange($range, $size) : null;
		if ($parsed !== null && $parsed['satisfiable'] === false) {
			// A syntactically valid single range wholly outside the entity (e.g.
			// `bytes=99-` on a 10-byte body) is unsatisfiable → 416. A malformed,
			// multi-range or non-bytes header is IGNORED by parseRange (null) and
			// falls through to the normal 200 full body below.
			$headers = $this->baseHeaders();
			$headers['Content-Type'] = $file['contentType'];
			$headers['Cache-Control'] = 'no-cache';
			$headers['ETag'] = $etag;
			$headers['Accept-Ranges'] = 'bytes';
			$headers['Content-Range'] = 'bytes */' . $size;
			return new PreviewResponse(416, $headers, '');
		}
		if ($parsed !== null) {
			[$start, $end] = [$parsed['start'], $parsed['end']];
			$length = $end - $start + 1;
			$headers = $this->baseHeaders();
			$headers['Content-Type'] = $file['contentType'];
			$headers['Cache-Control'] = 'no-cache';
			$headers['ETag'] = $etag;
			$headers['Accept-Ranges'] = 'bytes';
			$headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
			$headers['Content-Length'] = (string)$length;
			if ($file['isScriptable']) {
				$headers['Content-Security-Policy'] = PreviewPolicy::CSP;
			}
			return new PreviewResponse(206, $headers, $this->readSlice($file['filePath'], $start, $length));
		}

		$headers = $this->baseHeaders();
		$headers['Content-Type'] = $file['contentType'];
		$headers['Cache-Control'] = 'no-cache';
		$headers['ETag'] = $etag;
		$headers['Accept-Ranges'] = 'bytes';
		if ($file['isScriptable']) {
			$headers['Content-Security-Policy'] = PreviewPolicy::CSP;
		}
		return new PreviewResponse(200, $headers, (string)@file_get_contents($file['filePath']));
	}

	/** 404 body carrying the full hardening header set (contract: headers on 404 too). */
	private function notFound(): PreviewResponse {
		$headers = $this->baseHeaders();
		$headers['Content-Type'] = 'text/plain; charset=utf-8';
		$headers['Cache-Control'] = 'no-store';
		return new PreviewResponse(404, $headers, 'Not Found');
	}

	/**
	 * The hardening headers emitted on every serving response. ACAO `*` is sound
	 * only because the route is authless and cookieless; never pair it with
	 * credentials.
	 *
	 * @return array<string,string>
	 */
	private function baseHeaders(): array {
		return [
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'no-referrer',
			'Permissions-Policy' => PreviewPolicy::PERMISSIONS_POLICY,
			'Access-Control-Allow-Origin' => '*',
		];
	}

	/**
	 * Parse a single HTTP byte range against a known size, distinguishing the two
	 * outcomes the serving contract treats differently:
	 *
	 *  - `null` — the header is malformed, a multi-range set, or a non-`bytes`
	 *    unit. Per the contract it is IGNORED: the caller serves a normal 200
	 *    full body (never 416). This is the corrected behaviour — treating a
	 *    header we don't understand as unsatisfiable was wrong.
	 *  - `['satisfiable' => false]` — a syntactically valid single range that is
	 *    unsatisfiable (first-byte-pos at/after EOF, an empty suffix, or an empty
	 *    span). The caller emits 416.
	 *  - `['satisfiable' => true, 'start' => int, 'end' => int]` — a satisfiable
	 *    single range (inclusive). The caller emits 206.
	 *
	 * @return array{satisfiable:bool,start?:int,end?:int}|null
	 */
	private function parseRange(string $range, int $size): ?array {
		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
			return null; // malformed / multi-range / non-bytes unit → ignore (200)
		}
		[$rawStart, $rawEnd] = [$m[1], $m[2]];
		if ($rawStart === '' && $rawEnd === '') {
			return null; // `bytes=-` carries no range → ignore (200)
		}
		if ($rawStart === '') {
			$suffix = (int)$rawEnd;
			if ($suffix <= 0 || $size === 0) {
				return ['satisfiable' => false];
			}
			$start = max(0, $size - $suffix);
			$end = $size - 1;
		} else {
			$start = (int)$rawStart;
			$end = $rawEnd === '' ? $size - 1 : min((int)$rawEnd, $size - 1);
		}
		if ($start < 0 || $start >= $size || $start > $end) {
			return ['satisfiable' => false];
		}
		return ['satisfiable' => true, 'start' => $start, 'end' => $end];
	}

	/** Read $length bytes from $filePath starting at $start. */
	private function readSlice(string $filePath, int $start, int $length): string {
		$handle = @fopen($filePath, 'rb');
		if ($handle === false) {
			return '';
		}
		try {
			if (@fseek($handle, $start) !== 0) {
				return '';
			}
			$data = @fread($handle, $length);
			return $data === false ? '' : $data;
		} finally {
			@fclose($handle);
		}
	}
}
