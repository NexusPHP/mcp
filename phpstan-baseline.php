<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Offset 1 might not exist on non-empty-list<string>.',
	'identifier' => 'offsetAccess.notFound',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Transport/LineReader.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
