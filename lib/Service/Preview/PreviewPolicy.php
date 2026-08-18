<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * Single source of truth for the eXeLearning **editor-preview** isolation
 * policy: the sandbox-first Content-Security-Policy, the scriptable-document
 * classification, the Permissions-Policy, the served MIME resolution, and the
 * traversal-safe path normalization.
 *
 * This mirrors eXe core (repo `exelearning/exelearning`), which is authoritative:
 *
 *  - CSP / scriptable set / Permissions-Policy → `src/shared/security/previewSandbox.ts`
 *  - MIME + path normalization                 → `src/utils/content-path.util.ts`
 *
 * The class is intentionally OCP-free (pure static logic, no Nextcloud
 * dependencies) so every protocol rule is unit-testable without a server and
 * the {@see \OCA\ExeLearning\Controller\PreviewController} stays a thin adapter.
 *
 * WARNING: {@see self::CSP} MUST stay **byte-identical** to `previewCspHeader()`
 * in eXe core. Do not re-order, re-quote, reformat, or add a trailing `;`. This
 * is enforced ecosystem-wide by the `serving-contract` drift-check in core's
 * `scripts/check-embed-sync.mjs`.
 *
 * Note: this is a DIFFERENT string from {@see \OCA\ExeLearning\Service\IframeSandbox}
 * used for *published* content. Published content pins `frame-src`/`img-src` to
 * the maintained provider hosts (token-exfiltration hardening for a longer-lived
 * capability URL); the editor preview is a short-lived, ephemeral capability and
 * matches core's preview CSP verbatim. The two are deliberately not unified — see
 * docs/preview-serving-contract.md.
 */
final class PreviewPolicy {
	/**
	 * Byte-identical to `previewCspHeader()` in eXe core
	 * `src/shared/security/previewSandbox.ts`. The leading `sandbox` directive
	 * drops the document into an opaque, unique origin even when the capability
	 * URL is opened top-level (new tab / popup / raw URL); the rest is
	 * defence-in-depth. Emitted verbatim on every scriptable document type.
	 */
	public const CSP = 'sandbox allow-scripts allow-popups allow-forms; '
		. "default-src 'self'; "
		. "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
		. "style-src 'self' 'unsafe-inline'; "
		. "img-src 'self' data: blob: https:; "
		. "media-src 'self' data: blob: https:; "
		. "font-src 'self' data:; "
		. "connect-src 'self'; "
		. "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
		. "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
		. "object-src 'none'; "
		. "base-uri 'none'; "
		. "form-action 'self'; "
		. "frame-ancestors 'self'";

	/** Permissions-Policy emitted on every preview response. */
	public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=()';

	/**
	 * Capability id shape (a server-minted v4 UUID). Anything else is a hard 404
	 * on the serving route and a 404 on the management route.
	 */
	public const PREVIEW_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Scriptable document types that MUST carry the sandbox-first CSP so they
	 * stay opaque even when opened top-level. Not just `text/html`: an
	 * author-supplied `image/svg+xml` served without the sandbox CSP runs its
	 * inline `<script>` same-origin when opened in a new tab (`nosniff` does not
	 * help — SVG is already a scriptable document type).
	 *
	 * Matches the contract's `isScriptableDocument(mime)` set (eXe core
	 * `isScriptableDocumentType()`): text/html, image/svg+xml, application/xml,
	 * text/xml and application/xhtml+xml.
	 */
	private const SCRIPTABLE_TYPES = [
		'text/html',
		'image/svg+xml',
		'application/xml',
		'text/xml',
		'application/xhtml+xml',
	];

	/**
	 * Extension → base MIME type. Mirrors the shared eXe map; textual types get
	 * a `; charset=utf-8` appended by {@see self::mimeForPath()} so responses
	 * paired with `nosniff` stay both strict and readable.
	 */
	private const MIME_MAP = [
		'html' => 'text/html',
		'htm' => 'text/html',
		'xhtml' => 'application/xhtml+xml',
		'xml' => 'application/xml',
		'css' => 'text/css',
		'js' => 'text/javascript',
		'mjs' => 'text/javascript',
		'json' => 'application/json',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'ico' => 'image/x-icon',
		'bmp' => 'image/bmp',
		'mp3' => 'audio/mpeg',
		'wav' => 'audio/wav',
		'ogg' => 'audio/ogg',
		'oga' => 'audio/ogg',
		'm4a' => 'audio/mp4',
		'mp4' => 'video/mp4',
		'm4v' => 'video/mp4',
		'webm' => 'video/webm',
		'ogv' => 'video/ogg',
		'mov' => 'video/quicktime',
		'vtt' => 'text/vtt',
		'srt' => 'application/x-subrip',
		'pdf' => 'application/pdf',
		'wasm' => 'application/wasm',
		'woff' => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf' => 'font/ttf',
		'otf' => 'font/otf',
		'eot' => 'application/vnd.ms-fontobject',
		'txt' => 'text/plain',
	];

	/** Extensions that are textual and therefore get a UTF-8 charset appended. */
	private const CHARSET_EXTENSIONS = ['js', 'mjs', 'json', 'svg', 'xml', 'xhtml'];

	/** No instances — pure static policy. */
	private function __construct() {
	}

	/** Whether $previewId is a well-formed capability id. */
	public static function isValidPreviewId(string $previewId): bool {
		return preg_match(self::PREVIEW_ID_RE, $previewId) === 1;
	}

	/**
	 * True when the given MIME (with any `; charset=…` stripped) is a scriptable
	 * document type and therefore needs the sandbox CSP.
	 */
	public static function isScriptable(string $mime): bool {
		$base = strtolower(trim(explode(';', $mime, 2)[0]));
		return in_array($base, self::SCRIPTABLE_TYPES, true);
	}

	/**
	 * Resolve the Content-Type for a served path from its extension, appending a
	 * UTF-8 charset to textual types. Mirrors `contentTypeFor()` in eXe core.
	 */
	public static function mimeForPath(string $path): string {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$base = self::MIME_MAP[$ext] ?? 'application/octet-stream';
		$textual = str_starts_with($base, 'text/') || in_array($ext, self::CHARSET_EXTENSIONS, true);
		if ($textual && !str_contains($base, 'charset')) {
			$base .= '; charset=utf-8';
		}
		return $base;
	}

	/**
	 * Normalize a requested/served relative path against a content-map root.
	 *
	 * Returns a safe, root-relative POSIX path, or null when the request escapes
	 * the root (traversal, literal or percent-encoded), contains a NUL byte, or
	 * collapses to nothing. The SAME normalization is applied to stored paths
	 * (revision writes/deletes and both ref-map key sets) and to served paths,
	 * so a lookup can only ever name a stored entry — never a filesystem
	 * location. Mirrors `normalizeContentPath()` in eXe core, plus backslash
	 * folding (a served key is always POSIX, so treating `\` as a separator can
	 * only turn a would-be traversal into a 404, never a false hit).
	 */
	public static function normalizePath(string $raw): ?string {
		// Drop any query/fragment the caller left attached.
		$p = preg_split('/[?#]/', $raw, 2)[0];
		// Percent-decode so encoded traversal (`%2e%2e%2f`) becomes literal `../`
		// and is rejected below, exactly like core's decodeURIComponent step.
		$p = rawurldecode($p);
		if (str_contains($p, "\0")) {
			return null;
		}
		$p = str_replace('\\', '/', $p);
		$p = ltrim($p, '/');
		if ($p === '') {
			$p = 'index.html';
		}
		$out = [];
		foreach (explode('/', $p) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				return null;
			}
			$out[] = $segment;
		}
		if (count($out) === 0) {
			return null;
		}
		return implode('/', $out);
	}
}
