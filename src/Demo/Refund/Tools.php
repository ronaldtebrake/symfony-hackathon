<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('refund_get_order', 'Read the support order, paid amount in minor units, condition, days since delivery and applicable policy ID.',
    method: 'order', effectHints: new ToolEffectHints(readOnlyHint: true),
    metadata: ['code_mode.output_schema' => [
        'type' => 'object',
        'properties' => [
            'orderId' => ['type' => 'string'],
            'policyId' => ['type' => 'string', 'minLength' => 1, 'description' => 'The policyId argument for refund_get_policy.'],
            'currency' => ['type' => 'string'],
            'amountMinor' => ['type' => 'integer'],
            'daysSinceDelivery' => ['type' => 'integer'],
            'itemCondition' => ['type' => 'string'],
        ],
        'required' => ['orderId', 'policyId', 'currency', 'amountMinor', 'daysSinceDelivery', 'itemCondition'],
        'additionalProperties' => false,
    ]])]
#[AsTool('refund_get_policy', 'Read the return window, accepted condition, currency and manual review threshold in minor units for a policy.', method: 'policy', effectHints: new ToolEffectHints(readOnlyHint: true))]
final class Tools
{
    public const NAMES = ['refund_get_order', 'refund_get_policy'];

    public function order(string $orderId): array
    {
        return match ($orderId) {
            'R-1042' => ['orderId' => $orderId, 'policyId' => 'standard-eu', 'currency' => 'EUR', 'amountMinor' => 7500, 'daysSinceDelivery' => 9, 'itemCondition' => 'unopened'],
            'R-1043' => ['orderId' => $orderId, 'policyId' => 'standard-eu', 'currency' => 'EUR', 'amountMinor' => 3200, 'daysSinceDelivery' => 4, 'itemCondition' => 'opened'],
            default => throw new \OutOfBoundsException('Unknown order.'),
        };
    }

    public function policy(string $policyId): array
    {
        return ['policyId' => $policyId, 'currency' => 'EUR', 'returnWindowDays' => 30, 'acceptedCondition' => 'unopened', 'manualReviewAboveMinor' => 5000];
    }
}
