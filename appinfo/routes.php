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
		[
			'name' => 'preview#replace',
			'url' => '/preview-session/{fileId}',
			'verb' => 'POST',
			'requirements' => ['fileId' => '\d+'],
		],
		[
			'name' => 'preview#delete',
			'url' => '/preview-session/{fileId}/{previewId}',
			'verb' => 'DELETE',
			'requirements' => [
				'fileId' => '\d+',
				'previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}',
			],
		],
		[
			'name' => 'preview#serveRoot',
			'url' => '/preview/{previewId}',
			'verb' => 'GET',
			'defaults' => ['path' => 'index.html'],
			'requirements' => [
				'previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}',
			],
		],
		[
			'name' => 'preview#serve',
			'url' => '/preview/{previewId}/{path}',
			'verb' => 'GET',
			'requirements' => [
				'previewId' => '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}',
				'path' => '.+',
			],
		],
	],
];
