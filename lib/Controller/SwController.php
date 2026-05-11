<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Serves the eXeLearning runtime Service Worker. Two things matter:
 *
 * 1. The response must have `Content-Type: text/javascript` — Nextcloud is
 *    happy to return `text/plain` for unknown extensions and the browser
 *    refuses to register the worker.
 * 2. The default scope of a Service Worker is the directory of its script
 *    URL. We register it from `/apps/exelearning/sw.js` so the runtime URLs
 *    at `/apps/exelearning/runtime/*` are within scope without needing a
 *    `Service-Worker-Allowed` header. We still emit the header for clarity.
 */
class SwController extends Controller {
	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataDisplayResponse|DataResponse {
		$path = __DIR__ . '/../../src/sw/exelearning-sw.js';
		if (!is_file($path)) {
			return new DataResponse(['error' => 'Service Worker source missing — re-deploy the app.'], Http::STATUS_NOT_FOUND);
		}
		$contents = file_get_contents($path);
		if ($contents === false) {
			return new DataResponse(['error' => 'Cannot read Service Worker bundle.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$response = new DataDisplayResponse($contents, Http::STATUS_OK, [
			'Content-Type' => 'text/javascript; charset=utf-8',
		]);
		$response->addHeader('Service-Worker-Allowed', '/apps/exelearning/');
		$response->addHeader('Cache-Control', 'public, max-age=300');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
