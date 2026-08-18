<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Property Nexus\\Mcp\\Core\\Dispatch\\PendingOutboundRequests::$map (array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, response: class-string<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, request: Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcRequest<non-empty-string, array<string, mixed>>|null, context: Nexus\\Mcp\\Core\\Transport\\SendContext|null}>) does not accept non-empty-array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>|Amp\\DeferredFuture<TResponse of Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse = Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, response: class-string<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse>, request: Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcRequest<non-empty-string, array<string, mixed>>|null, context: Nexus\\Mcp\\Core\\Transport\\SendContext|null}>.',
	'identifier' => 'assign.propertyType',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Dispatch/PendingOutboundRequests.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Parameter #2 $message of static method Nexus\\Mcp\\Core\\JsonRpc\\ErrorFactory::create() expects non-empty-string, string given.',
	'identifier' => 'argument.type',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Dispatch/ResponseSender.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Method Nexus\\Mcp\\Core\\Schema\\ServerCapabilities::extractListChangedOnly() should return array{listChanged?: bool, ...<string, mixed>}|null but returns array<string, mixed>.',
	'identifier' => 'return.type',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Schema/ServerCapabilities.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Method Nexus\\Mcp\\Core\\Schema\\ServerCapabilities::extractResources() should return array{listChanged?: bool, subscribe?: bool, ...<string, mixed>}|null but returns array<string, mixed>.',
	'identifier' => 'return.type',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Schema/ServerCapabilities.php',
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
	'rawMessage' => 'Parameter #2 $message of static method Nexus\\Mcp\\Core\\JsonRpc\\ErrorFactory::create() expects non-empty-string, string given.',
	'identifier' => 'argument.type',
	'count' => 2,
	'path' => __DIR__ . '/src/Extension/Tasks/Server/ToolTaskRunner.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Casting class ReflectionType to string is deprecated.',
	'identifier' => 'class.toStringDeprecated',
	'count' => 1,
	'path' => __DIR__ . '/src/Server/Discovery/InputSchemaGenerator.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Offset non-empty-string might not exist on array<non-empty-string, int>.',
	'identifier' => 'offsetAccess.notFound',
	'count' => 1,
	'path' => __DIR__ . '/src/Server/Subscription/SubscriptionStore.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Casting class ReflectionType to string is deprecated.',
	'identifier' => 'class.toStringDeprecated',
	'count' => 1,
	'path' => __DIR__ . '/tests/AutoReview/SchemaConformanceTest.php',
];

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
