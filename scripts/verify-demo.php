<?php

declare(strict_types=1);

use App\Demo\Refund\Fixture;
use App\Demo\Refund\Tools;
use Mcp\Schema\Content\TextContent;

require dirname(__DIR__).'/vendor/autoload.php';

// This client only knows the public catalog. The operator alone controls the fixture's permission.
$fixture = new Fixture(dirname(__DIR__), null, 'catalog');
$expect = static function (bool $condition, string $what): void {
    if (!$condition) {
        throw new RuntimeException('Demo verification failed: '.$what);
    }
};
try {
    $client = $fixture->connectReader();
    $server = $client->getServerInfo()?->name;
    $advertised = array_column($client->listTools()->tools, 'name');
    $expect('symfony_code_mode' === $server && ['search_tools', 'execute_tools'] === $advertised, 'server identity and advertised tools');
    $calls = [];
    $call = static function (string $tool, array $arguments, bool $denied = false) use ($client, $fixture, &$calls, $expect): array {
        $reply = $client->callTool($tool, $arguments);
        $result = $reply->structuredContent;
        $trace = $fixture->lastOperation();
        $expect($reply->isError === $denied && is_array($result) && $reply->content[0] instanceof TextContent
            && json_decode($reply->content[0]->text, true, flags: \JSON_THROW_ON_ERROR) === $result, 'MCP result');
        $expect($tool === $trace['operation'] && ($denied ? 'failed' : 'succeeded') === $trace['status'], 'matching Symfony operation');
        $row = ['tool' => $tool, 'arguments' => $arguments, 'outcome' => $denied ? 'denied' : 'succeeded'];
        if ('search_tools' === $tool) {
            $expect([] === $trace['calls'] && [] === $trace['nativeEvents'], 'discovery ran a handler');
            $row['members'] = array_column($result['tools'], 'name');
            if (isset($result['group'])) {
                $row['opened'] = $result['group'];
                $row['instructions'] = $result['instructions'];
            } else {
                $row['groups'] = array_column($result['groups'], 'name');
            }
        } else {
            $reader = $arguments['calls'][0]['name'];
            $expect([$reader] === array_column($trace['calls'], 'name') && $row['outcome'] === $trace['calls'][0]['status'], 'broker outcome');
            $expect(($denied ? [] : [$reader, $reader]) === array_column($trace['nativeEvents'], 'name'), 'handler identity');
            $row['reader'] = $reader;
            $row['handlerEvents'] = array_column($trace['nativeEvents'], 'phase');
            $expect(($denied ? [] : ['requested', 'succeeded']) === $row['handlerEvents'], 'handler events');
            if ($denied) {
                $expect(['error' => ['reason' => 'tool_denied', 'tool' => $reader, 'index' => 0], 'completedCalls' => 0] === $result, 'denied read returned data');
                $row['reason'] = $result['error']['reason'];
            } else {
                $expect([$reader] === array_column($result['results'], 'name'), 'tool result identity');
                $row['result'] = $result['results'][0]['value'];
            }
        }
        $calls[] = $row;

        return $result;
    };
    $query = ['query' => 'refund review'];
    $hits = $call('search_tools', $query);
    $expect(in_array('refund-review', array_column($hits['groups'], 'name'), true) && [] !== $hits['tools'], 'individual tools and refund group are discoverable');
    $group = $call('search_tools', ['group' => 'refund-review']);
    $expect(Tools::NAMES === array_column($group['tools'], 'name') && Fixture::instructions() === $group['instructions'], 'group members and procedure');
    $order = $call('execute_tools', ['calls' => [['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']]]])['results'][0]['value'];
    $expected = new Tools();
    $expect($expected->order('R-1042') === $order, 'order received');
    $policyRead = ['calls' => [['name' => 'refund_get_policy', 'arguments' => ['policyId' => $order['policyId']]]]];
    $policy = $call('execute_tools', $policyRead)['results'][0]['value'];
    $expect($expected->policy($order['policyId']) === $policy
        && $order['currency'] === $policy['currency'] && $order['itemCondition'] === $policy['acceptedCondition']
        && $order['daysSinceDelivery'] <= $policy['returnWindowDays'] && $order['amountMinor'] > $policy['manualReviewAboveMinor'], 'current policy requires review');
    $revokedAfter = count($calls);
    $fixture->revoke();
    $call('execute_tools', $policyRead, true);
    $hidden = $call('search_tools', $query);
    $expect([] === $hidden['tools'] && [] === $hidden['groups'], 'visibility after revocation');
    $report = ['format' => 'symfony-code-mode-judge/v1', 'protocol' => 'MCP over HTTP', 'server' => $server,
        'advertised' => $advertised, 'modelRequests' => 0, 'revokedAfterCall' => $revokedAfter, 'calls' => $calls];

    if (['--json'] === array_slice($argv, 1)) {
        echo json_encode($report, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
    } else {
        echo "Connected to $server over MCP. Advertised tools: ".implode(', ', $advertised).".\n";
        echo "Found and opened refund-review. Read {$order['orderId']}, then its {$policy['policyId']} policy. Manual review required.\n";
        echo "Revoked permission. The same policy read returned tool_denied before its handler ran. Search returned zero tools and groups.\n";
    }
} finally {
    try {
        if (isset($client)) {
            $client->disconnect();
        }
    } finally {
        $fixture->close();
    }
}
