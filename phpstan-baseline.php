<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Property Nexus\\Mcp\\Core\\Dispatch\\PendingOutboundRequests::$map (array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, response: class-string<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>}>) does not accept non-empty-array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>|Amp\\DeferredFuture<TResponse of Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse = Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, response: class-string<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>}>.',
	'identifier' => 'assign.propertyType',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Dispatch/PendingOutboundRequests.php',
];
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
$ignoreErrors[] = [
	'rawMessage' => 'Casting class ReflectionType to string is deprecated.',
	'identifier' => 'class.toStringDeprecated',
	'count' => 1,
	'path' => __DIR__ . '/src/Server/Discovery/InputSchemaGenerator.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
