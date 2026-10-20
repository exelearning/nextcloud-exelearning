<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * Framework-agnostic description of a serving-route response.
 *
 * {@see PreviewServer} produces these from pure logic (fully unit-testable and
 * vector-replayable without a Nextcloud server); {@see \OCA\ExeLearning\Controller\PreviewController}
 * is the thin adapter that copies the status, headers and body onto a
 * `DataDisplayResponse`. Keeping the HTTP policy in a value object is what lets
 * the header set, CSP byte-identity, ETag/304 and Range behaviour be asserted
 * directly against the contract vectors.
 */
final class PreviewResponse {
	/**
	 * @param int $status HTTP status code.
	 * @param array<string,string> $headers Response headers (verbatim; the
	 *                                      adapter emits each with addHeader()).
	 * @param string $body Response body (empty for 304/416).
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
	) {
	}
}
