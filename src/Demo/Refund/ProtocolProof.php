<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use App\Demo\Groups\PublishingGroups;
use App\Demo\Skills\Fixture as SkillsFixture;

final class ProtocolProof
{
    private const SOURCES = [
        'src/Demo/Mcp/CaptureRequestFactory.php', 'src/Demo/Groups/GroupDiscoveryResult.php',
        'src/Demo/Groups/ListGroupsRequest.php', 'src/Demo/Groups/GetGroupRequest.php', 'src/Demo/Groups/PublishingGroups.php',
        'src/Demo/Refund/Access.php', 'src/Demo/Refund/Fixture.php', 'src/Demo/Refund/Gateway.php',
        'src/Demo/Refund/Group.php', 'src/Demo/Refund/Kernel.php', 'src/Demo/Refund/ProtocolProof.php', 'src/Demo/Refund/Tools.php',
        Fixture::SKILL_FILE, 'scripts/refund-server.php', 'composer.lock',
    ];

    public static function record(string $root, string $sdk): array
    {
        $group = ['name' => 'refund-review'];
        $skill = ['uri' => Fixture::URI];
        $order = ['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-1042']];
        $policy = ['name' => 'refund_get_policy', 'arguments' => ['policyId' => 'standard-eu']];
        $calls = [];
        $app = new Fixture($root, $sdk, 'skill');
        $rpc = static function (string $label, string $method, array $arguments = [], string $user = 'reader') use (&$calls, &$app): void {
            $exchange = $app->rpc($user, $method, $arguments, retainWire: true);
            // Preserve object/list distinctions in the JSON view, as well as the exact response bytes.
            if (isset($exchange['responseJson'])) {
                $exchange['response'] = json_decode($exchange['responseJson'], flags: \JSON_THROW_ON_ERROR);
            }
            $calls[$label] = $exchange;
        };
        try {
            $rpc('discover', 'server/discover');
            $rpc('list', 'groups/list');
            $rpc('open', 'groups/get', $group);
            $rpc('manifest', 'skills/get', $skill);
            $rpc('guide', 'resources/read', $skill);
            $rpc('order', 'tools/call', $order);
            $rpc('policy', 'tools/call', $policy);
            $rpc('top_level_tools', 'tools/list');
            $rpc('top_level_resources', 'resources/list');
            $rpc('viewer_groups', 'groups/list', user: 'viewer');
            $rpc('viewer_group', 'groups/get', $group, 'viewer');
            $rpc('viewer_manifest', 'skills/get', $skill, 'viewer');
            $rpc('viewer_guide', 'resources/read', $skill, 'viewer');
            $rpc('viewer_order', 'tools/call', $order, 'viewer');
            $rpc('unassigned_order', 'tools/call', ['name' => 'refund_get_order', 'arguments' => ['orderId' => 'R-2042']]);
            $rpc('anonymous', 'groups/list', user: 'anonymous');
            $rpc('still_visible', 'groups/get', $group);
        } finally {
            $app->close();
        }
        $app = new Fixture($root, $sdk, 'skill');
        try {
            $rpc('open_before_revocation', 'groups/get', $group);
            $rpc('manifest_before_revocation', 'skills/get', $skill);
            $rpc('guide_before_revocation', 'resources/read', $skill);
            $rpc('order_before_revocation', 'tools/call', $order);
            $app->revoke();
            $rpc('denied_policy', 'tools/call', $policy);
            $rpc('hidden_group', 'groups/list');
            $rpc('hidden_known_group', 'groups/get', $group);
            $rpc('hidden_skills', 'skills/list');
            $rpc('hidden_manifest', 'skills/get', $skill);
            $rpc('denied_guide', 'resources/read', $skill);
            for ($i = 3; $i <= Gateway::LIMIT; ++$i) {
                $rpc('denied_attempt_'.$i, 'tools/call', $policy);
            }
            $rpc('budget_exhausted', 'tools/call', $policy);
        } finally {
            $app->close();
        }
        // Separate timing: the reader has only learned the skill URI when its grant changes.
        $app = new Fixture($root, $sdk, 'skill');
        try {
            $rpc('group_before_skill_revoke', 'groups/get', $group);
            $app->revoke();
            $rpc('manifest_after_group_revoke', 'skills/get', $skill);
            $rpc('guide_after_group_revoke', 'resources/read', $skill);
        } finally {
            $app->close();
        }
        $sources = [];
        foreach (self::SOURCES as $file) {
            $sources[$file] = hash_file('sha256', $root.'/'.$file);
        }

        return ['format' => 'symfony-refund-protocol/v1', 'capturedAt' => gmdate(\DATE_ATOM), 'sdkRevision' => SkillsFixture::REVISION,
            'protocolVersion' => SkillsFixture::VERSION, 'modelRequests' => 0, 'sourceSha256' => $sources,
            'operator' => ['action' => 'revoke', 'grant' => 'refund.review', 'after' => 'order_before_revocation', 'before' => 'denied_policy'],
            'skillRevocation' => ['action' => 'revoke', 'grant' => 'refund.review', 'after' => 'group_before_skill_revoke', 'before' => 'manifest_after_group_revoke'],
            'scope' => 'Three disposable Symfony HTTP apps with synthetic data. The scripted client exercises the group, read-only tools and linked skill. The operator revokes before the policy read on the second app and before the skill fetch on the third. These exchanges test transport and access, without asking a model for a recommendation.',
            'calls' => $calls];
    }

    public static function verify(array $report, string $root): void
    {
        self::check('symfony-refund-protocol/v1' === $report['format'] && SkillsFixture::REVISION === $report['sdkRevision']
            && SkillsFixture::VERSION === $report['protocolVersion'] && 0 === $report['modelRequests'], 'identity');
        foreach (self::SOURCES as $file) {
            self::check(hash_file('sha256', $root.'/'.$file) === $report['sourceSha256'][$file], 'source '.$file);
        }
        $calls = $report['calls'];
        $id = 0;
        foreach ($calls as $label => $call) {
            if (\in_array($label, ['open_before_revocation', 'group_before_skill_revoke'], true)) {
                $id = 0;
            }
            self::check(++$id === $call['request']['id'], 'request order');
            if ('anonymous' === $label) {
                self::check(401 === $call['httpStatus'] && null === $call['response'] && 'anonymous' === $call['user'], 'unauthenticated');

                continue;
            }
            self::check($call['request']['id'] === $call['response']['id'] && (isset($call['response']['error']) ? \in_array($call['httpStatus'], [200, 400, 404], true) : 200 === $call['httpStatus'])
                && $call['request'] === json_decode($call['requestJson'], true, flags: \JSON_THROW_ON_ERROR)
                && $call['response'] === json_decode($call['responseJson'], true, flags: \JSON_THROW_ON_ERROR)
                && $call['responseSha256'] === hash('sha256', $call['responseJson'])
                && str_contains($call['cacheControl'], 'no-store') && str_contains($call['cacheControl'], 'private'), 'wire '.$label);
            self::check((str_starts_with($label, 'viewer_') ? 'viewer' : 'reader') === $call['user'], 'principal');
        }
        $result = static fn (string $label): array => $calls[$label]['response']['result'];
        $extensions = $result('discover')['capabilities']['extensions'];
        self::check(isset($extensions[PublishingGroups::ID], $extensions[SkillsFixture::EXTENSION]), 'server extensions');
        self::check(['refund-review'] === array_column($result('list')['groups'], 'name'), 'group list');
        $group = $result('open');
        self::check(Tools::NAMES === array_column($group['tools'], 'name') && [Fixture::URI] === array_column($group['resources'], 'uri')
            && !isset($group['instructions']) && [] === $result('top_level_resources')['resources'] && [] === $result('top_level_tools')['tools'], 'scoped toolkit');
        foreach (['list', 'open', 'top_level_resources'] as $label) {
            self::check('private' === $result($label)['cacheScope'] && 0 === $result($label)['ttlMs'], 'private discovery '.$label);
        }
        foreach (['open', 'still_visible', 'open_before_revocation', 'group_before_skill_revoke'] as $label) {
            self::check($result($label)['tools'] === $group['tools'] && $result($label)['resources'] === $group['resources'], 'same toolkit');
        }
        self::check(['action' => 'revoke', 'grant' => 'refund.review', 'after' => 'group_before_skill_revoke', 'before' => 'manifest_after_group_revoke'] === $report['skillRevocation']
            && 1 === $calls['group_before_skill_revoke']['request']['id'] && 2 === $calls['manifest_after_group_revoke']['request']['id'], 'skill revocation checkpoint');
        $bytes = file_get_contents($root.'/'.Fixture::SKILL_FILE);
        foreach (['', '_before_revocation'] as $suffix) {
            $manifest = $result('manifest'.$suffix)['skill'];
            self::check(Fixture::URI === $manifest['uri'] && [['uri' => Fixture::URI, 'digest' => 'sha256:'.hash('sha256', $bytes), 'size' => \strlen($bytes)]] === $manifest['resources'], 'skill manifest');
            self::check(Fixture::URI === $result('guide'.$suffix)['contents'][0]['uri'] && $bytes === $result('guide'.$suffix)['contents'][0]['text'], 'skill bytes');
        }
        self::check(['orderId' => 'R-1042', 'policyId' => 'standard-eu', 'currency' => 'EUR', 'amountMinor' => 7500, 'daysSinceDelivery' => 9, 'itemCondition' => 'unopened'] === $result('order')['structuredContent']
            && $result('order')['structuredContent'] === $result('order_before_revocation')['structuredContent'], 'order data');
        self::check(['policyId' => 'standard-eu', 'currency' => 'EUR', 'returnWindowDays' => 30, 'acceptedCondition' => 'unopened', 'manualReviewAboveMinor' => 5000] === $result('policy')['structuredContent']
            && $calls['denied_policy']['request']['params'] === $calls['policy']['request']['params'], 'policy lookup');
        foreach (['order', 'policy', 'order_before_revocation'] as $label) {
            $name = $calls[$label]['request']['params']['name'];
            self::check([['name' => $name, 'phase' => 'requested'], ['name' => $name, 'phase' => 'succeeded']] === $calls[$label]['trace']['operations'][0]['nativeEvents'], 'handler '.$label);
        }
        self::check(['action' => 'revoke', 'grant' => 'refund.review', 'after' => 'order_before_revocation', 'before' => 'denied_policy'] === $report['operator']
            && $calls['denied_policy']['request']['id'] === 1 + $calls['order_before_revocation']['request']['id'], 'revocation checkpoint');
        foreach (['viewer_groups', 'hidden_group'] as $label) {
            self::check([] === $result($label)['groups'], 'hidden group');
        }
        self::check([] === $result('hidden_skills')['skills'], 'hidden skills');
        foreach (['viewer_group', 'viewer_manifest', 'viewer_guide', 'hidden_known_group', 'hidden_manifest', 'denied_guide', 'manifest_after_group_revoke', 'guide_after_group_revoke'] as $label) {
            self::check(isset($calls[$label]['response']['error']['code']) && !isset($calls[$label]['response']['result']), 'unavailable '.$label);
        }
        foreach (['viewer_order', 'unassigned_order', 'denied_policy', 'denied_attempt_3', 'denied_attempt_4', 'denied_attempt_5', 'denied_attempt_6', 'budget_exhausted'] as $label) {
            $body = $result($label)['structuredContent'];
            self::check(('budget_exhausted' === $label ? 'execution_limit' : 'tool_denied') === $body['error']['reason']
                && 0 === $body['completedCalls'] && !isset($body['results']) && [] === $calls[$label]['trace']['operations'][0]['nativeEvents'], 'denial '.$label);
        }
    }

    private static function check(bool $ok, string $why): void
    {
        if (!$ok) {
            throw new \UnexpectedValueException('Invalid refund protocol proof: '.$why);
        }
    }
}
