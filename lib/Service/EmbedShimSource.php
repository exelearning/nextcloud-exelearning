<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Reads the child half of the shared eXeLearning external-media bundle — the script
 * inlined into every served package so that, inside the opaque iframe, each cross-origin
 * or PDF sub-iframe is replaced by a geometry placeholder the parent host can overlay.
 *
 * eXeLearning core is canonical (eXe ADR-0021): this app holds the BYTES and verifies them
 * against the manifest core published, rather than a copy of the logic that could drift.
 * Do not patch the artifact here — a local edit is invisible upstream, is overwritten on
 * the next re-vendor, and fails `verify.mjs` in CI in the meantime.
 *
 * Free of OCP dependencies on purpose, like {@see EmbedShimInjector}, so the question
 * "which bytes do we serve?" can be asserted in a plain unit test.
 */
class EmbedShimSource {
	private const CHILD_BUNDLE = 'exe-external-media-child.min.js';

	public function __construct(
		private readonly string $vendoredDir = __DIR__ . '/../../src/embed/exe_external_media',
	) {
	}

	/** The bundle source, or null when the vendored artifact is not present. */
	public function read(): ?string {
		$path = $this->vendoredDir . '/' . self::CHILD_BUNDLE;
		if (!is_file($path)) {
			return null;
		}
		$source = file_get_contents($path);
		return $source === false ? null : $source;
	}
}
