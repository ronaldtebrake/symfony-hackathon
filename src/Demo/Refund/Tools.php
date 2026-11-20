<?php

declare(strict_types=1);

namespace App\Demo\Refund;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('refund_get_order', 'Read the support order, paid amount in minor units, condition, days since delivery and applicable policy ID.', method: 'order', effectHints: new ToolEffectHints(readOnlyHint: true))]
#[AsTool('refund_get_policy', 'Read the return window, accepted condition, currency and manual review threshold in minor units for a policy.', method: 'policy', effectHints: new ToolEffectHints(readOnlyHint: true))]
final class Tools
{
    public const NAMES = ['refund_get_order', 'refund_get_policy'];

    public function order(string $orderId): array
    {
        return ['orderId' => $orderId, 'policyId' => 'standard-eu', 'currency' => 'EUR', 'amountMinor' => 7500, 'daysSinceDelivery' => 9, 'itemCondition' => 'unopened'];
    }

    public function policy(string $policyId): array
    {
        return ['policyId' => $policyId, 'currency' => 'EUR', 'returnWindowDays' => 30, 'acceptedCondition' => 'unopened', 'manualReviewAboveMinor' => 5000];
    }
}
