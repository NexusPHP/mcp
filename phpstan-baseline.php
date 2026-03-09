<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Method Nexus\\Mcp\\Core\\Schema\\Icon::toArray() should return array{src: non-empty-string, mimeType?: non-empty-string, sizes?: list<non-empty-string>, theme?: \'dark\'|\'light\'} but returns array{src: non-empty-string, mimeType?: non-empty-string, sizes?: list<string>, theme?: \'dark\'|\'light\'}.',
	'identifier' => 'return.type',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Schema/Icon.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Property Nexus\\Mcp\\Core\\Schema\\Icon::$sizes (list<non-empty-string>|null) does not accept list<string>|null.',
	'identifier' => 'assign.propertyType',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Schema/Icon.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
