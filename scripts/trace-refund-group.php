<?php

declare(strict_types=1);

use App\Demo\Groups\PublishingGroups;
use App\Demo\Refund\Fixture;
use App\Demo\Refund\Tools;
use Composer\InstalledVersions;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

// Use the PHP SDK's stateless request envelopes against two fresh Symfony HTTP fixtures.
// Keep the JSON-RPC bodies and handler events. HTTP headers contain the temporary credential.
$capture = static function (Closure $scenario) use ($root): array {
    $fixture = new Fixture($root, null, 'catalog');
    $calls = [];
    $rpc = static function (string $method, array $params = []) use ($fixture, &$calls): array {
        $wire = $fixture->rpc('reader', $method, $params, retainWire: true);
        $result = $wire['response']['result'] ?? null;
        if (200 !== $wire['httpStatus'] || !is_array($result) || ($result['isError'] ?? false)) {
            throw new UnexpectedValueException('Unexpected MCP result for '.$method);
        }
        $calls[] = [
            'request' => json_decode($wire['requestJson'], flags: \JSON_THROW_ON_ERROR),
            'response' => json_decode($wire['responseJson'], flags: \JSON_THROW_ON_ERROR),
            'httpStatus' => $wire['httpStatus'],
            'handlerEvents' => array_column($wire['trace']['operations'][0]['nativeEvents'] ?? [], 'phase'),
        ];

        return $result;
    };
    try {
        $scenario($rpc);

        return ['calls' => $calls];
    } finally {
        $fixture->close();
    }
};

$single = $capture(static function (Closure $rpc): void {
    $tools = $rpc('tools/list')['tools'];
    if (['search_tools', 'execute_tools'] !== array_column($tools, 'name')) {
        throw new UnexpectedValueException('Unexpected startup catalog.');
    }
    $matches = $rpc('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'refund_get_order']])['structuredContent']['tools'];
    if ('refund_get_order' !== ($matches[0]['name'] ?? null)) {
        throw new UnexpectedValueException('The order reader was not retrieved.');
    }
    $order = $rpc('tools/call', ['name' => 'execute_tools', 'arguments' => ['calls' => [
        ['name' => $matches[0]['name'], 'arguments' => ['orderId' => 'R-1042']],
    ]]])['structuredContent']['results'][0]['value'];
    if ('R-1042' !== $order['orderId']) {
        throw new UnexpectedValueException('Unexpected order.');
    }
});
$groups = $capture(static function (Closure $rpc): void {
    if (!isset($rpc('server/discover')['capabilities']['extensions'][PublishingGroups::ID])) {
        throw new UnexpectedValueException('The server did not advertise Groups.');
    }
    if (['search_tools', 'execute_tools'] !== array_column($rpc('tools/list')['tools'], 'name')) {
        throw new UnexpectedValueException('The scoped readers appeared at startup.');
    }
    if (!in_array('refund-review', array_column($rpc('groups/list')['groups'], 'name'), true)) {
        throw new UnexpectedValueException('The refund Group was unavailable.');
    }
    $group = $rpc('groups/get', ['name' => 'refund-review']);
    $members = array_column($group['tools'], 'name');
    if (Tools::NAMES !== $members || Fixture::instructions() !== $group['instructions']) {
        throw new UnexpectedValueException('Unexpected refund procedure.');
    }
    $order = $rpc('tools/call', ['name' => $members[0], 'arguments' => ['orderId' => 'R-1042']])['structuredContent'];
    $policy = $rpc('tools/call', ['name' => $members[1], 'arguments' => ['policyId' => $order['policyId']]])['structuredContent'];
    if ($order['policyId'] !== $policy['policyId'] || $order['currency'] !== $policy['currency'] || $order['itemCondition'] !== $policy['acceptedCondition']
        || $order['daysSinceDelivery'] > $policy['returnWindowDays'] || $order['amountMinor'] <= $policy['manualReviewAboveMinor']) {
        throw new UnexpectedValueException('The returned order and policy do not support manual review.');
    }
});
echo json_encode([
    'format' => 'symfony-refund-discovery/v1',
    'client' => 'Scripted PHP client using mcp/sdk '.InstalledVersions::getPrettyVersion('mcp/sdk').' stateless request envelopes and Symfony HttpClient.',
    'modelRequests' => 0,
    'scope' => 'Two independent authorized HTTP sessions against the same synthetic catalog. The client declares Groups in both. The single-tool session chooses catalog search and execution. The second exercises the draft Groups methods and invokes each scoped reader by name.',
    'singleTool' => $single,
    'groups' => $groups + ['verifiedDisposition' => 'manual_review'],
], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT)."\n";
