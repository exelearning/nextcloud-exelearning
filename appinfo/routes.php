<?php

declare(strict_types=1);

return [
	'routes' => [
		[
			'name' => 'package#byFileId',
			'url' => '/package/by-file-id/{fileId}',
			'verb' => 'GET',
			'requirements' => ['fileId' => '\d+'],
		],
		[
			'name' => 'package#byPath',
			'url' => '/package/by-path',
			'verb' => 'GET',
		],
		[
			'name' => 'asset#fetch',
			'url' => '/asset/{sessionId}/{path}',
			'verb' => 'GET',
			'requirements' => ['path' => '.+'],
		],
		[
			'name' => 'content#serve',
			'url' => '/content/{token}/{path}',
			'verb' => 'GET',
			'requirements' => ['token' => '[A-Za-z0-9._~-]+', 'path' => '.+'],
			'defaults' => ['path' => 'index.html'],
		],
		// Opaque editor preview. The serving route is an authless, cookieless
		// capability URL gated solely on the unguessable previewId UUID; the
		// management routes are authenticated + owner-scoped (CSRF on).
		[
			'name' => 'preview#serve',
			'url' => '/preview/{previewId}/{path}',
			'verb' => 'GET',
			'requirements' => [
				'previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
				'path' => '.+',
			],
		],
		[
			'name' => 'preview#serveRoot',
			'url' => '/preview/{previewId}',
			'verb' => 'GET',
			'requirements' => ['previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
		],
		[
			'name' => 'previewSession#create',
			'url' => '/api/preview-session',
			'verb' => 'POST',
		],
		[
			'name' => 'previewSession#delete',
			'url' => '/api/preview-session/{previewId}',
			'verb' => 'DELETE',
			'requirements' => ['previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
		],
		[
			'name' => 'thumbnail#byFileId',
			'url' => '/thumbnail/by-file-id/{fileId}',
			'verb' => 'GET',
			'requirements' => ['fileId' => '\d+'],
		],
		[
			'name' => 'editor#index',
			'url' => '/editor',
			'verb' => 'GET',
		],
		[
			'name' => 'editor#save',
			'url' => '/editor/save',
			'verb' => 'POST',
		],
		[
			'name' => 'editor#iframe',
			'url' => '/editor/iframe',
			'verb' => 'GET',
		],
		[
			'name' => 'view#index',
			'url' => '/view',
			'verb' => 'GET',
		],
		[
			'name' => 'sw#index',
			'url' => '/sw.js',
			'verb' => 'GET',
		],
		[
			'name' => 'template#blank',
			'url' => '/template/blank',
			'verb' => 'GET',
		],
	],
];
