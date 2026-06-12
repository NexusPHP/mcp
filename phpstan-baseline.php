<?php declare(strict_types = 1);

$ignoreErrors = [];
$ignoreErrors[] = [
	'rawMessage' => 'Property Nexus\\Mcp\\Core\\Dispatch\\PendingOutboundRequests::$map (array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse<Nexus\\Mcp\\Core\\Schema\\Result<array<string, mixed>>>>, result: class-string<Nexus\\Mcp\\Core\\Schema\\Result<array<string, mixed>>>}>) does not accept non-empty-array<non-empty-string, array{deferred: Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse<Nexus\\Mcp\\Core\\Schema\\Result<array<string, mixed>>>>|Amp\\DeferredFuture<Nexus\\Mcp\\Core\\Schema\\JsonRpc\\JsonRpcResultResponse<T of Nexus\\Mcp\\Core\\Schema\\Result>>, result: class-string<Nexus\\Mcp\\Core\\Schema\\Result<array<string, mixed>>>|class-string<T of Nexus\\Mcp\\Core\\Schema\\Result>}>.',
	'identifier' => 'assign.propertyType',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Dispatch/PendingOutboundRequests.php',
];
$ignoreErrors[] = [
	'rawMessage' => 'Method Nexus\\Mcp\\Core\\Schema\\RequestMetaObject::toArray() should return array{\'io.modelcontextprotocol/protocolVersion\': non-empty-string, \'io.modelcontextprotocol/clientInfo\': array{name: non-empty-string, version: non-empty-string, title?: non-empty-string, description?: non-empty-string, websiteUrl?: non-empty-string, icons?: list<array{src: non-empty-string, mimeType?: non-empty-string, sizes?: list<non-empty-string>, theme?: \'dark\'|\'light\'}>}, \'io.modelcontextprotocol/clientCapabilities\': array{elicitation?: array{form?: array<string, mixed>, url?: array<string, mixed>}, experimental?: array<string, array<string, mixed>>, extensions?: array<string, array<string, mixed>>, sampling?: array{context?: array<string, mixed>, tools?: array<string, mixed>}}, \'io.modelcontextprotocol/logLevel\'?: \'alert\'|\'critical\'|\'debug\'|\'emergency\'|\'error\'|\'info\'|\'notice\'|\'warning\', progressToken?: int|non-empty-string, ...<string, mixed>} but returns non-empty-array<string, mixed>.',
	'identifier' => 'return.type',
	'count' => 1,
	'path' => __DIR__ . '/src/Core/Schema/RequestMetaObject.php',
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

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
