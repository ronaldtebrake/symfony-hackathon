<?php

declare(strict_types=1);

namespace CodeModeDemo\Drupal;

/** A generic upstream failure alone must never count as an access denial. */
final class ProofVerifier
{
    public const TOOL = 'tool_api__code_mode_read_article';

    public static function fixture(array $snapshot): bool
    {
        $permissions = $snapshot['account']['permissions'] ?? [];
        sort($permissions);
        return ($snapshot['account']['id'] ?? 0) > 1
            && ($snapshot['account']['name'] ?? null) === 'code_mode_reader'
            && ($snapshot['account']['active'] ?? null) === true
            && $permissions === ['access content', 'read code mode articles']
            && ($snapshot['published']['published'] ?? null) === true
            && ($snapshot['published']['viewAllowed'] ?? null) === true
            && ($snapshot['restricted']['exists'] ?? null) === true
            && ($snapshot['restricted']['published'] ?? null) === false
            && ($snapshot['restricted']['viewAllowed'] ?? null) === false
            && ($snapshot['restricted']['ownedByReader'] ?? null) === false
            && ($snapshot['anonymousMcpAllowed'] ?? null) === false;
    }

    public static function casePassed(array $case, array $fixture): bool
    {
        if (!self::fixture($fixture) || ($case['containerRemoved'] ?? false) !== true) {
            return false;
        }
        $calls = $case['toolCalls'] ?? [];
        if (count($calls) !== 1 || ($calls[0]['name'] ?? null) !== self::TOOL) {
            return false;
        }
        $restricted = ($case['id'] ?? '') === 'restricted_read';
        if (array_key_exists('agentResponse', $case)) {
            $expected = $restricted ? ['error' => ['reason' => 'tool_failed', 'tool' => self::TOOL]] : ['value' => $case['value']];
            if ($case['agentResponse'] !== $expected) { return false; }
        }
        if (!$restricted && ($case['id'] ?? '') !== 'authorized_read') {
            return false;
        }
        $node = $fixture[$restricted ? 'restricted' : 'published']['id'];
        $events = $case['drupalEvents'] ?? [];
        if (count($events) !== ($restricted ? 1 : 2)) {
            return false;
        }
        foreach ($events as $event) {
            if (($event['account'] ?? null) !== $fixture['account']['id'] || ($event['node'] ?? null) !== $node) {
                return false;
            }
        }
        if (($events[0]['event'] ?? null) !== 'article_access' || ($events[0]['decision'] ?? null) !== ($restricted ? 'denied' : 'allowed')) {
            return false;
        }
        if ($restricted) {
            return ($case['reason'] ?? null) === 'tool_failed' && ($case['value'] ?? null) === null
                && ($calls[0]['status'] ?? null) === 'incomplete' && ($calls[0]['reason'] ?? null) === 'tool_failed';
        }
        return ($case['reason'] ?? null) === null && ($calls[0]['status'] ?? null) === 'succeeded'
            && ($events[1]['event'] ?? null) === 'article_read'
            && ($case['value'] ?? null) === ['id' => $node, 'title' => $fixture['published']['title']];
    }
}
