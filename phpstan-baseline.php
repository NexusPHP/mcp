<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Strict comparison using === between Nexus\\Mcp\\Core\\Transport\\TransportState::Closed and Nexus\\Mcp\\Core\\Transport\\TransportState::Running will always evaluate to false.',
	'identifier' => 'identical.alwaysFalse',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Transport/LineDuplex.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Offset 1 might not exist on non-empty-list<string>.',
	'identifier' => 'offsetAccess.notFound',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Transport/LineReader.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
