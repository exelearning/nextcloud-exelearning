<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\ZipEntryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Server-side fallback for serving package assets. Most deployments will use
 * the in-browser Service Worker route instead, but this exists for environments
 * where Service Workers are not viable (e.g. embedded in another origin).
 *
 * `sessionId` here is the integer file id; the path is the entry inside the
 * archive. The server never trusts the URL alone — it always re-checks that
 * the current user can read the underlying file before serving any bytes.
 */
class AssetController extends Controller {
	private const MIME_MAP = [
		'html' => 'text/html; charset=utf-8',
		'htm' => 'text/html; charset=utf-8',
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

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ElpxPackageService $packageService,
		private readonly ZipEntryService $zipEntries,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fetch(string $sessionId, string $path): DataDisplayResponse|DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$fileId = (int)$sessionId;
		if ($fileId <= 0) {
			return new DataResponse(['error' => 'Invalid session'], Http::STATUS_BAD_REQUEST);
		}
		$entry = $this->zipEntries->normalizeEntry($path);
		if ($entry === null) {
			return new DataResponse(['error' => 'Unsafe path'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$file = $this->packageService->getForUserById($user->getUID(), $fileId);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$contents = $this->zipEntries->readEntry($file, $entry);
		if ($contents === null) {
			return new DataResponse(['error' => 'Entry not found'], Http::STATUS_NOT_FOUND);
		}

		$mime = $this->detectMime($entry);
		$response = new DataDisplayResponse($contents, Http::STATUS_OK, ['Content-Type' => $mime]);
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Content-Security-Policy', "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; frame-ancestors 'self'");
		$response->addHeader('Cache-Control', 'private, max-age=300');
		return $response;
	}

	private function detectMime(string $entry): string {
		$extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
		return self::MIME_MAP[$extension] ?? 'application/octet-stream';
	}
}
