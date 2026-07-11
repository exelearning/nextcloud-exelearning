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
		if ($range !== null && trim($range) !== '') {
			$parsed = $this->parseRange($range, $size);
			if ($parsed === null) {
				$headers = $this->baseHeaders();
				$headers['Content-Type'] = $file['contentType'];
				$headers['Cache-Control'] = 'no-cache';
				$headers['ETag'] = $etag;
				$headers['Accept-Ranges'] = 'bytes';
				$headers['Content-Range'] = 'bytes */' . $size;
				return new PreviewResponse(416, $headers, '');
			}
			[$start, $end] = $parsed;
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
	 * Parse a single HTTP byte range against a known size. Returns `[start, end]`
	 * (inclusive) for a satisfiable range, or null for an unsatisfiable one
	 * (caller emits 416). A syntactically malformed header also yields null so it
	 * is treated as unsatisfiable rather than silently serving the whole body.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private function parseRange(string $range, int $size): ?array {
		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
			return null;
		}
		[$rawStart, $rawEnd] = [$m[1], $m[2]];
		if ($rawStart === '' && $rawEnd === '') {
			return null;
		}
		if ($rawStart === '') {
			$suffix = (int)$rawEnd;
			if ($suffix <= 0 || $size === 0) {
				return null;
			}
			$start = max(0, $size - $suffix);
			$end = $size - 1;
		} else {
			$start = (int)$rawStart;
			$end = $rawEnd === '' ? $size - 1 : min((int)$rawEnd, $size - 1);
		}
		if ($start < 0 || $start >= $size || $start > $end) {
			return null;
		}
		return [$start, $end];
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
