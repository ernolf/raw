<?php

return [
	'routes' => [
		['name' => 'privatePage#getByPath', 'url' => '/u/{userId}/{path}',
			'requirements' => array('path' => '.+')],

		['name' => 'pubPage#getByToken', 'url' => '/s/{token}'],
		['name' => 'pubPage#getByTokenWithoutS', 'url' => '/{token}'],

		['name' => 'pubPage#getByTokenAndPath', 'url' => '/s/{token}/{path}',
			'requirements' => array('path' => '.+')],
		['name' => 'pubPage#getByTokenAndPathWithoutS', 'url' => '/{token}/{path}',
			'requirements' => array('path' => '.+')],

		// Root namespace: /rss -> fixed token "rss"
		// (requires core allowlist for rootUrlApps incl. 'raw')
		['name' => 'pubPage#getRssRoot', 'url' => '/rss', 'root' => '', 'verb' => 'GET'],
		['name' => 'pubPage#getRssRootPath', 'url' => '/rss/{path}', 'root' => '', 'verb' => 'GET',
			'requirements' => array('path' => '.*'),
			'defaults' => array('path' => ''),
		],

		// Legacy root aliases: /raw/s/{token} and /raw/s/{token}/{path}
		['name' => 'pubPage#getByTokenRootLegacyS', 'url' => '/s/{token}', 'root' => '/raw'],
		['name' => 'pubPage#getByTokenAndPathRootLegacyS', 'url' => '/s/{token}/{path}', 'root' => '/raw',
			'requirements' => array('path' => '.+')],

		// Root aliases: /raw/{token} and /raw/{token}/{path}
		// (require core allowlist for rootUrlApps incl. 'raw')
		['name' => 'pubPage#getByTokenRoot', 'url' => '/{token}', 'root' => '/raw'],
		['name' => 'pubPage#getByTokenAndPathRoot', 'url' => '/{token}/{path}', 'root' => '/raw',
			'requirements' => array('path' => '.+')],
	]
];
