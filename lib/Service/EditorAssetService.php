<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Locates the optional static eXeLearning editor bundle.
 *
 * The editor is never committed: `make download-editor` / `make build-editor`
 * drop it into `js/editor/`, and release tarballs ship it. Preview-only
 * installs legitimately have no `js/editor/` at all, so every caller has to
 * cope with it being absent.
 *
 * Both callers used to inline `is_file(__DIR__ . '/../../js/editor/index.html')`,
 * which made them depend on the real filesystem of whatever checkout they ran
 * in: the unit tests asserting the "editor missing" behaviour passed in CI and
 * failed on any machine where `make up` had downloaded the bundle. Keeping the
 * path behind an injectable service lets tests point it somewhere empty.
 */
class EditorAssetService {
	private const DEFAULT_INDEX_PATH = __DIR__ . '/../../js/editor/index.html';

	public function __construct(
		private readonly string $editorIndexPath = self::DEFAULT_INDEX_PATH,
	) {
	}

	/**
	 * Whether the editor bundle is present in this install.
	 */
	public function isAvailable(): bool {
		return is_file($this->editorIndexPath);
	}

	/**
	 * The editor's `index.html`, or null when it is absent or unreadable.
	 *
	 * Unreadable and absent are deliberately distinct for callers that need to
	 * tell "not installed" (404) from "installed but broken" (500); use
	 * {@see self::isAvailable()} to tell them apart.
	 */
	public function readIndexHtml(): ?string {
		if (!$this->isAvailable()) {
			return null;
		}
		$html = file_get_contents($this->editorIndexPath);
		return $html === false ? null : $html;
	}
}
