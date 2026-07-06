<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Inlines the eXe-core external-media shim (exe_embed_shim.js) into a served
 * HTML package document, so that inside the opaque iframe every cross-origin /
 * PDF sub-iframe is promoted to a geometry placeholder the parent relay can
 * overlay. Mirrors the inject-at-serve approach of wp-exelearning
 * (ExeLearning_Content_Proxy::inject_embed_shim) and omeka-s-exelearning
 * (ContentController::injectEmbedShim).
 *
 * Pure string transformation (no OCP dependencies) so it is unit-testable.
 */
class EmbedShimInjector {
	private const MARKER = 'data-injected-by="eXeLearning-Viewer"';

	/**
	 * Insert the shim as an inline <script> as early as possible: before
	 * </head> when present (it must run before the package's own media bridge),
	 * otherwise right after <body>, otherwise prepended.
	 */
	public function injectIntoHead(string $html, string $scriptSource): string {
		$tag = '<script ' . self::MARKER . '>' . $scriptSource . '</script>';

		$headClose = stripos($html, '</head>');
		if ($headClose !== false) {
			return substr($html, 0, $headClose) . $tag . substr($html, $headClose);
		}

		if (preg_match('~<body[^>]*>~i', $html, $matches, PREG_OFFSET_CAPTURE) === 1) {
			$at = $matches[0][1] + strlen($matches[0][0]);
			return substr($html, 0, $at) . $tag . substr($html, $at);
		}

		return $tag . $html;
	}
}
