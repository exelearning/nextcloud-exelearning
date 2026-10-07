<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * HTTP serving policy for the authless preview capability URL
 * (`GET {basePath}/preview/{previewId}/{path}`).
 *
 * Pure logic over {@see PreviewSnapshotStore}: it validates the capability id,
 * resolves the path inside the snapshot, and applies the contract's response
 * policy — the hardening header set on EVERY response (404s included), the
 * tiered `Cache-Control`, `ETag`/`If-None-Match` → 304 and single-range 206/416
 * on assets, and the sandbox-first CSP on every scriptable document type. It
 * returns a {@see PreviewResponse} value object so the whole policy is
 * unit-testable without a Nextcloud server;
 * {@see \OCA\ExeLearning\Controller\PreviewController} is the thin adapter onto
 * `DataDisplayResponse`.
 */
final class PreviewServer {
	public function __construct(
		private readonly PreviewSnapshotStore $store,
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
		$file = $this->store->resolve($previewId, $rawPath);
		if ($file === null) {
			return $this->notFound();
		}

		return match ($file['kind']) {
			'asset' => $this->serveAsset($file, $ifNoneMatch, $range),
			default => $this->serveDocument($file),
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
	 * the whole decision stays OCP-free.
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
	 * A scriptable document. It is rewritten on every refresh, so `no-store`, and
	 * it ALWAYS carries the sandbox CSP: `resolve()` only labels a file a
	 * document when its type is scriptable, so there is no case here that should
	 * go out without it.
	 *
	 * @param array{contentType:string,bytes?:string} $file
	 */
	private function serveDocument(array $file): PreviewResponse {
		$headers = $this->baseHeaders();
		$headers['Content-Type'] = $file['contentType'];
		$headers['Cache-Control'] = 'no-store';
		$headers['Content-Security-Policy'] = PreviewPolicy::CSP;
		return new PreviewResponse(200, $headers, $file['bytes'] ?? '');
	}

	/**
	 * A non-scriptable asset: revalidated (`no-cache`) with an ETag,
	 * `Accept-Ranges: bytes`, `If-None-Match` → 304 and single-range 206/416.
	 *
	 * No sandbox CSP here, and that is not an omission: `resolve()` labels a file
	 * an asset precisely when its type is NOT scriptable, so a CSP branch on this
	 * path could never fire.
	 *
	 * @param array{contentType:string,filePath:string,size:int,etag:string} $file
	 */
	private function serveAsset(array $file, ?string $ifNoneMatch, ?string $range): PreviewResponse {
		$etag = '"' . $file['etag'] . '"';
		$size = $file['size'];

		$headers = $this->baseHeaders();
		$headers['Content-Type'] = $file['contentType'];
		$headers['Cache-Control'] = 'no-cache';
		$headers['ETag'] = $etag;
		$headers['Accept-Ranges'] = 'bytes';

		if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
			return new PreviewResponse(304, $headers, '');
		}

		$parsed = ($range !== null && trim($range) !== '') ? $this->parseRange($range, $size) : null;
		if ($parsed !== null && $parsed['satisfiable'] === false) {
			// A syntactically valid single range wholly outside the entity (e.g.
			// `bytes=99-` on a 10-byte body) is unsatisfiable → 416. A malformed,
			// multi-range or non-bytes header is IGNORED by parseRange (null) and
			// falls through to the normal 200 full body below.
			$headers['Content-Range'] = 'bytes */' . $size;
			return new PreviewResponse(416, $headers, '');
		}
		if ($parsed !== null) {
			[$start, $end] = [$parsed['start'], $parsed['end']];
			$length = $end - $start + 1;
			$headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
			$headers['Content-Length'] = (string)$length;
			return new PreviewResponse(206, $headers, $this->readSlice($file['filePath'], $start, $length));
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
	 * Parse a single HTTP byte range against a known size, per the contract's
	 * canonical classification (RFC 9110 §14.1.2). Three outcomes:
	 *
	 *  - `null` — IGNORE the header, serve a normal 200 full body. This covers a
	 *    non-`bytes` unit, a multi-range set, any malformed value, AND a valid
	 *    syntax whose last-byte-pos is less than its first-byte-pos (`bytes=5-2`)
	 *    — an *invalid* spec, which RFC 9110 says to ignore, not 416.
	 *  - `['satisfiable' => false]` — a valid single range that is *unsatisfiable*:
	 *    first-byte-pos at/after EOF (`bytes=99-`) or a zero-length suffix
	 *    (`bytes=-0`). The caller emits 416.
	 *  - `['satisfiable' => true, 'start' => int, 'end' => int]` — a satisfiable
	 *    single range (inclusive). The caller emits 206.
	 *
	 * @return array{satisfiable:bool,start?:int,end?:int}|null
	 */
	private function parseRange(string $range, int $size): ?array {
		if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
			return null; // non-bytes unit / multi-range / garbage → ignore (200)
		}
		[$rawStart, $rawEnd] = [$m[1], $m[2]];
		if ($rawStart === '' && $rawEnd === '') {
			return null; // `bytes=-` carries no range → ignore (200)
		}
		if ($rawStart === '') {
			// Suffix range `bytes=-N`: the last N bytes. A zero-length suffix or
			// an empty entity is unsatisfiable (416); otherwise it clamps in.
			$suffix = (int)$rawEnd;
			if ($suffix === 0 || $size === 0) {
				return ['satisfiable' => false];
			}
			return ['satisfiable' => true, 'start' => max(0, $size - $suffix), 'end' => $size - 1];
		}
		$start = (int)$rawStart;
		if ($rawEnd !== '' && (int)$rawEnd < $start) {
			// last-byte-pos < first-byte-pos is an INVALID spec, not an
			// unsatisfiable one → ignore the header and serve 200.
			return null;
		}
		if ($start >= $size) {
			return ['satisfiable' => false]; // first-byte-pos at/after EOF → 416
		}
		$end = $rawEnd === '' ? $size - 1 : min((int)$rawEnd, $size - 1);
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
