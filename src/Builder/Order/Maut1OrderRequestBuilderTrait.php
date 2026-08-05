<?php declare(strict_types=1);

namespace MultiSafepay\Shopware6\Builder\Order;

/**
 * Order ID history handling for MultiSafepay payment change retries.
 */
trait Maut1OrderRequestBuilderTrait
{
    /**
     * @param array<string, mixed> $existingCustomFields
     * @return string[]|null
     */
    private function appendPreviousOrderIdToHistory(array $existingCustomFields): ?array
    {
        $previousOrderId = $existingCustomFields['orderId'] ?? null;
        if (!is_string($previousOrderId) || $previousOrderId === '') {
            return null;
        }

        $orderIdHistory = [];

        $existingHistory = $existingCustomFields['orderIdHistory'] ?? [];
        if (!is_array($existingHistory)) {
            $existingHistory = [];
        }

        foreach ($existingHistory as $entry) {
            if (is_string($entry)) {
                $orderIdHistory[] = $entry;
            } elseif (is_array($entry) && is_string($entry['orderId'] ?? null)) {
                $orderIdHistory[] = $entry['orderId'];
            }
        }

        if (!in_array($previousOrderId, $orderIdHistory, true)) {
            $orderIdHistory[] = $previousOrderId;
        }

        return $orderIdHistory;
    }
}
