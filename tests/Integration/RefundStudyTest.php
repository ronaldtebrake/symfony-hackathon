<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\RefundStudy\Proof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundStudyTest extends TestCase
{
    #[DataProvider('changes')]
    public function testReviewedConversation(string $change): void
    {
        $root = \dirname(__DIR__, 2);
        $record = json_decode(file_get_contents($root.'/docs/evidence/refund-skill.json'), true, flags: \JSON_THROW_ON_ERROR);
        if ('captured' === $change) {
            Proof::verify($record, $root);
            $passing = array_filter($record['attempts'], static fn ($attempt) => 'passed' === $attempt['status']);
            $this->assertEqualsCanonicalizing(['bare', 'inline', 'skill'], array_unique(array_column($passing, 'arm')));

            return;
        }
        $index = array_key_last(array_filter($record['attempts'], static fn ($attempt) => 'skill' === $attempt['arm'] && 'passed' === $attempt['status']));
        $linked = &$record['attempts'][$index];
        switch ($change) {
            case 'modified skill on the wire':
                $linked['hostCalls'][4]['response']['result']['contents'][0]['text'] .= 'Extra instructions.';
                break;
            case 'guidance omitted from the model context':
                $linked['turns'][0]['requestEvidence'][0]['userMessageSha256'] = [];
                break;
            case 'denied handler invoked':
                $linked['turns'][1]['calls'][0]['trace']['operations'][0]['nativeEvents'][] = ['phase' => 'requested', 'name' => 'refund_get_policy'];
                break;
            case 'changed provider usage':
                ++$linked['turns'][1]['usage']['total'];
                break;
        }
        $this->expectException(\UnexpectedValueException::class);
        Proof::verify($record, $root);
    }

    public static function changes(): iterable
    {
        foreach (['captured', 'modified skill on the wire', 'guidance omitted from the model context', 'denied handler invoked', 'changed provider usage'] as $case) {
            yield $case => [$case];
        }
    }
}
