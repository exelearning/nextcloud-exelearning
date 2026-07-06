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
