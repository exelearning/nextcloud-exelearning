<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Maps package entry extensions to MIME types and classifies which served
 * documents need which Content-Security-Policy. Shared by {@see \OCA\ExeLearning\Controller\AssetController}
 * (same-origin fallback) and {@see \OCA\ExeLearning\Controller\ContentController}
 * (opaque serving) so the mapping never drifts between the two.
 *
 * Pure logic (no OCP dependencies) so it is unit-testable.
 */
class PackageMimeService {
	private const MIME_MAP = [
		'html' => 'text/html; charset=utf-8',
		'htm' => 'text/html; charset=utf-8',
		'xhtml' => 'application/xhtml+xml; charset=utf-8',
		'xml' => 'application/xml; charset=utf-8',
		'css' => 'text/css; charset=utf-8',
		'js' => 'text/javascript; charset=utf-8',
		'mjs' => 'text/javascript; charset=utf-8',
		'json' => 'application/json; charset=utf-8',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'mp3' => 'audio/mpeg',
		'mp4' => 'video/mp4',
		'ogg' => 'audio/ogg',
		'wav' => 'audio/wav',
		'webm' => 'video/webm',
		'vtt' => 'text/vtt',
		'woff' => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf' => 'font/ttf',
		'eot' => 'application/vnd.ms-fontobject',
	];

	public function detect(string $entry): string {
		$extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
		return self::MIME_MAP[$extension] ?? 'application/octet-stream';
	}

	/**
	 * A "page" document that runs the eXe engine — gets the full published CSP
	 * and the injected media shim.
	 */
	public function isHtmlDocument(string $mime): bool {
		$type = $this->baseType($mime);
		return $type === 'text/html' || $type === 'application/xhtml+xml';
	}

	/**
	 * A data document (SVG/XML) that must be locked script-free when opened
	 * top-level (an author SVG runs inline <script> without it).
	 */
	public function isLockedXml(string $mime): bool {
		$type = $this->baseType($mime);
		return $type === 'image/svg+xml' || $type === 'application/xml';
	}

	private function baseType(string $mime): string {
		return strtolower(trim(explode(';', $mime, 2)[0]));
	}
}
