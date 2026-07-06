<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Single source of truth for the secure (opaque-origin) iframe policy used to
 * render published .elpx content: the sandbox token set, the published
 * Content-Security-Policy, the SVG/XML locked policy, the Permissions-Policy,
 * and the external-media provider whitelist.
 *
 * Mirrors the ecosystem contract shared with mod_exelearning
 * (\mod_exelearning\local\ui\player_iframe), wp-exelearning
 * (ExeLearning_Iframe_Sandbox) and omeka-s-exelearning (IframeSandbox). The
 * secure token set MUST stay 'allow-scripts allow-popups allow-forms' with NO
 * 'allow-same-origin' — its absence is what makes the document opaque. The only
 * path back to a same-origin iframe is the dev-only escape hatch
 * EXELEARNING_UNSAFE_LEGACY_IFRAME (off by default, never a UI setting).
 */
class IframeSandbox {
	public const MODE_SECURE = 'secure';
	public const MODE_LEGACY = 'legacy';

	private const SECURE_TOKENS = 'allow-scripts allow-popups allow-forms';
	private const LEGACY_TOKENS = 'allow-same-origin allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox';

	private const LEGACY_ENV = 'EXELEARNING_UNSAFE_LEGACY_IFRAME';
	private const EMBED_OPEN_ENV = 'EXELEARNING_EMBED_OPEN';

	public const EMBED_STRICT = 'strict';
	public const EMBED_OPEN = 'open';

	/** Hosts whose embedded players the relay may overlay (see exe_embed_relay.js). */
	private const PROVIDER_WHITELIST = [
		'www.youtube.com',
		'youtube.com',
		'www.youtube-nocookie.com',
		'youtube-nocookie.com',
		'player.vimeo.com',
		'vimeo.com',
		'www.dailymotion.com',
		'dailymotion.com',
		'geo.dailymotion.com',
		'mediateca.educa.madrid.org',
	];

	/** @var callable(string):?string */
	private $envReader;

	/**
	 * @param (callable(string):?string)|null $envReader Injectable environment
	 *        reader (tests pass a stub); defaults to getenv().
	 */
	public function __construct(?callable $envReader = null) {
		$this->envReader = $envReader ?? static function (string $name): ?string {
			$value = getenv($name);
			return $value === false ? null : $value;
		};
	}

	/**
	 * Always 'secure' unless the dev escape hatch is explicitly on.
	 */
	public function resolveMode(): string {
		$raw = ($this->envReader)(self::LEGACY_ENV);
		$on = $raw !== null && in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
		return $on ? self::MODE_LEGACY : self::MODE_SECURE;
	}

	/**
	 * External-media relay mode. 'strict' (default, fail-safe) overlays only the
	 * maintained provider hosts; 'open' (opt-in via EXELEARNING_EMBED_OPEN)
	 * overlays any cross-origin https player the shim reports.
	 */
	public function embedMode(): string {
		$raw = ($this->envReader)(self::EMBED_OPEN_ENV);
		$on = $raw !== null && in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
		return $on ? self::EMBED_OPEN : self::EMBED_STRICT;
	}

	/**
	 * The iframe `sandbox` attribute token list for the given (or resolved) mode.
	 */
	public function sandboxTokens(?string $mode = null): string {
		$mode ??= $this->resolveMode();
		return $mode === self::MODE_LEGACY ? self::LEGACY_TOKENS : self::SECURE_TOKENS;
	}

	/**
	 * Response-level CSP for served HTML package documents.
	 *
	 * 'strict' (default) keeps script/img/media/frame-src pinned to 'self' + the
	 * maintained providers so a URL-borne capability token can never be
	 * exfiltrated to an arbitrary host; the trailing `sandbox` directive keeps
	 * the document opaque even if opened top-level. 'compatible' re-opens https:
	 * for deployments that need arbitrary external assets.
	 */
	public function contentSecurityPolicy(string $profile = 'strict'): string {
		$scriptSrc = "script-src 'self' 'unsafe-inline' 'unsafe-eval'";
		$imgSrc = "img-src 'self' data: blob:";
		$mediaSrc = "media-src 'self' data: blob:";
		$frameSrc = "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com "
			. 'https://www.dailymotion.com https://mediateca.educa.madrid.org';
		if ($profile === 'compatible') {
			$scriptSrc = "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:";
			$imgSrc = "img-src 'self' data: blob: https:";
			$mediaSrc = "media-src 'self' data: blob: https:";
			$frameSrc = "frame-src 'self' https:";
		}
		return implode('; ', [
			"default-src 'self'",
			$scriptSrc,
			"style-src 'self' 'unsafe-inline'",
			$imgSrc,
			$mediaSrc,
			"font-src 'self' data:",
			"connect-src 'self'",
			$frameSrc,
			"frame-ancestors 'self'",
			"form-action 'self'",
			"base-uri 'self'",
			"object-src 'none'",
			'sandbox ' . self::SECURE_TOKENS,
		]);
	}

	/**
	 * Locked, script-free CSP for SVG/XML documents opened top-level.
	 */
	public function svgCsp(): string {
		return "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'none'; sandbox";
	}

	/**
	 * Deny hardware access; deliberately does NOT deny fullscreen (video needs it).
	 */
	public function permissionsPolicy(): string {
		return 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), '
			. 'bluetooth=(), hid=(), magnetometer=(), accelerometer=(), gyroscope=(), '
			. 'midi=(), display-capture=()';
	}

	/**
	 * @return string[]
	 */
	public function providerWhitelist(): array {
		return self::PROVIDER_WHITELIST;
	}
}
