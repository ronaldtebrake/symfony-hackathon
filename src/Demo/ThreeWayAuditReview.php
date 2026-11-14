<?php

declare(strict_types=1);

namespace App\Demo;

/** Apply the model's explicit decision, without consulting data or an answer oracle. */
final class ThreeWayAuditReview
{
    public static function apply(string $response, string $draft): array
    {
        try {
            $value = json_decode($response, false, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \UnexpectedValueException('Invalid answer review decision.', previous: $error);
        }
        if ($value instanceof \stdClass && ['decision'] === array_keys(get_object_vars($value)) && 'keep' === $value->decision) {
            return ['decision' => 'keep', 'answer' => $draft];
        }
        if ($value instanceof \stdClass && 2 === \count(get_object_vars($value)) && 'replace' === ($value->decision ?? null) && ($value->answer ?? null) instanceof \stdClass) {
            return ['decision' => 'replace', 'answer' => json_encode($value->answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)];
        }
        throw new \UnexpectedValueException('Invalid answer review decision.');
    }
}
