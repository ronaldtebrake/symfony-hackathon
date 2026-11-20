---
name: refund-review
description: Prepare a read-only refund recommendation from an order and its applicable policy.
---

Read the order, then read the policy named by that order. Compare its age and condition with the policy. If eligible, compare the amount with the manual-review threshold in the same currency. Report the recommendation and the reason. If a required read is denied, keep the facts already received, identify the missing evidence and report the review as incomplete. A review never issues a refund.
