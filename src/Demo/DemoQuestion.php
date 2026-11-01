<?php

declare(strict_types=1);

namespace App\Demo;

/** A question is data. It cannot select tools, identity, endpoints or execution limits. */
final readonly class DemoQuestion
{
    public const MAX_BYTES = 2000;

    private function __construct(public string $id, public string $text) {}

    public static function preset(string $id = 'audit'): self
    {
        return new self($id, match ($id) {
            'audit' => AuditDataset::TASK,
            'impact' => 'Check every published article for an empty meta_description and empty hero-image alt text. Group article IDs by issue, and identify images with missing alt text reused by more than one published article. Return JSON with audited (number of published articles checked), groups (an object with missing_description and missing_alt, each a sorted list of article IDs), and shared_images (sorted by image_id, each with image_id and sorted article_ids). Include both groups even when empty. Exclude drafts. Use batched reads and do not change content.',
            default => throw new \InvalidArgumentException('Choose the audit or impact scenario.'),
        });
    }

    public static function custom(string $text): self
    {
        // Reject terminal controls and invisible formatting; never interpolate a question into PHP.
        if (strlen($text) > self::MAX_BYTES || 1 !== preg_match('//u', $text)
            || 1 === preg_match('/[\p{Cc}\p{Cf}]/u', str_replace(["\n", "\r", "\t"], '', $text))) {
            throw new \InvalidArgumentException('Use at most 2000 UTF-8 bytes without control or invisible formatting characters.');
        }
        $text = trim($text);
        if ('' === $text) {
            throw new \InvalidArgumentException('Write a question about the published articles and their hero images.');
        }
        return new self('custom', $text);
    }

    public function requireSupportedRun(string $mode, string $backend): void
    {
        if ('audit' !== $this->id && ('live' !== $mode || 'fixture' !== $backend)) {
            throw new \InvalidArgumentException('Changed questions require live mode and the Symfony fixture backend.');
        }
    }

    public static function choices(): array
    {
        return [
            ['id' => 'audit', 'label' => 'Find missing descriptions and alt text', 'summary' => 'Find every published article with a missing meta description or hero-image alt text.', 'reference' => true],
            ['id' => 'impact', 'label' => 'Group issues and find shared-image impact', 'summary' => 'Group article IDs by issue, then find images whose missing alt text affects multiple articles.', 'reference' => false],
            ['id' => 'custom', 'label' => 'Ask your own question', 'summary' => 'Ask about published articles, languages, metadata or hero images. Custom answers require your review.', 'reference' => false],
        ];
    }
}
