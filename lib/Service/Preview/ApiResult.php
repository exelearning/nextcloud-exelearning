<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * Framework-agnostic description of a management-API response.
 *
 * {@see PreviewSessionApi} produces these from pure logic;
 * {@see \OCA\ExeLearning\Controller\PreviewSessionController} maps them onto a
 * `DataResponse`. This keeps the full protocol semantics (status codes,
 * 409/422 shapes, the create-session `limits` block) OCP-free and testable.
 */
final class ApiResult {
	/**
	 * @param int $status HTTP status code.
	 * @param array<string,mixed> $body JSON response body.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
	) {
	}
}
