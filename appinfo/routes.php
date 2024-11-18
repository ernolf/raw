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
	]
];
