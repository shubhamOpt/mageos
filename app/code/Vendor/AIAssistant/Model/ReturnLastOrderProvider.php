<?php
declare(strict_types=1);

namespace Vendor\AIAssistant\Model;

use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use MageOS\RMA\Helper\ModuleConfig as RmaConfig;
use MageOS\RMA\Service\OrderEligibility;

/**
 * Checks whether the customer's last order can be returned, using the same rules as the
 * Mage-OS returns module (MageOS_RMA), and points to its return form with the order preselected.
 *
 * The return itself is submitted on that form: it asks for a reason, item condition and
 * resolution, which the RMA module validates.
 */
class ReturnLastOrderProvider
{
    public const STATUS_ELIGIBLE = 'eligible';
    public const STATUS_NO_ORDERS = 'no_orders';
    public const STATUS_RETURNS_DISABLED = 'returns_disabled';
    public const STATUS_ORDER_STATUS_NOT_RETURNABLE = 'order_status_not_returnable';
    public const STATUS_RETURN_PERIOD_EXPIRED = 'return_period_expired';
    public const STATUS_NOTHING_LEFT_TO_RETURN = 'nothing_left_to_return';

    public function __construct(
        private readonly LastOrderProvider $lastOrderProvider,
        private readonly OrderEligibility $orderEligibility,
        private readonly RmaConfig $rmaConfig,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<string, mixed> Always has 'status'; other keys depend on it
     */
    public function check(int $customerId): array
    {
        $order = $this->lastOrderProvider->getLastOrder($customerId);
        if ($order === null) {
            return ['status' => self::STATUS_NO_ORDERS];
        }

        $base = [
            'order_number' => (string)$order->getIncrementId(),
            'order_status' => (string)$order->getStatusLabel(),
            'order_url' => $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $order->getEntityId()]),
            'returns_history_url' => $this->urlBuilder->getUrl('rma/customer/history'),
        ];
        $storeId = (int)$order->getStoreId();

        if (!$this->rmaConfig->isEnabled($storeId)) {
            return ['status' => self::STATUS_RETURNS_DISABLED] + $base;
        }

        if (!in_array($order->getStatus(), $this->rmaConfig->getAllowedOrderStatuses($storeId), true)) {
            return ['status' => self::STATUS_ORDER_STATUS_NOT_RETURNABLE] + $base;
        }

        $returnable = [];
        $expired = [];
        foreach ($this->orderEligibility->getEligibleItems($order) as $item) {
            $name = html_entity_decode((string)$item['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($item['is_eligible']) {
                $returnable[] = ['name' => $name, 'sku' => (string)$item['sku'], 'qty_returnable' => (int)$item['qty_available']];
            } elseif ($item['disabled_reason'] !== '') {
                $expired[] = ['name' => $name, 'reason' => (string)$item['disabled_reason']];
            }
        }

        if ($returnable === []) {
            return [
                'status' => $expired === [] ? self::STATUS_NOTHING_LEFT_TO_RETURN : self::STATUS_RETURN_PERIOD_EXPIRED,
                'expired_items' => $expired,
            ] + $base;
        }

        return [
            'status' => self::STATUS_ELIGIBLE,
            'return_period_days' => $this->rmaConfig->getReturnPeriod($storeId),
            'returnable_items' => $returnable,
            'expired_items' => $expired,
            'return_form_url' => $this->urlBuilder->getUrl('rma/customer/create', ['order_id' => $order->getEntityId()]),
        ] + $base;
    }

    /**
     * Plain-text answer for the chat command, no AI involved.
     */
    public function format(array $result): string
    {
        switch ($result['status']) {
            case self::STATUS_NO_ORDERS:
                return 'You have not placed any orders in this store yet, so there is nothing to return.';

            case self::STATUS_RETURNS_DISABLED:
                return sprintf(
                    "Online returns are not available in this store right now.\n"
                    . 'Please contact customer service about order #%s.',
                    $result['order_number']
                );

            case self::STATUS_ORDER_STATUS_NOT_RETURNABLE:
                return sprintf(
                    "Your last order #%s can't be returned online because its status is \"%s\".\n"
                    . "Orders can be returned once they are complete.\n"
                    . 'Order details: %s',
                    $result['order_number'],
                    $result['order_status'],
                    $result['order_url']
                );

            case self::STATUS_RETURN_PERIOD_EXPIRED:
                $lines = [sprintf('The return period for your last order #%s has ended:', $result['order_number'])];
                foreach ($result['expired_items'] as $item) {
                    $lines[] = sprintf('  • %s: %s', $item['name'], $item['reason']);
                }
                return implode("\n", $lines);

            case self::STATUS_NOTHING_LEFT_TO_RETURN:
                return sprintf(
                    "Everything in your last order #%s has already been returned or requested for return.\n"
                    . 'Your returns: %s',
                    $result['order_number'],
                    $result['returns_history_url']
                );

            case self::STATUS_ELIGIBLE:
            default:
                $lines = [sprintf('You can return these items from your last order #%s:', $result['order_number'])];
                foreach ($result['returnable_items'] as $item) {
                    $lines[] = sprintf('  • %s (up to %d)', $item['name'], $item['qty_returnable']);
                }
                foreach ($result['expired_items'] as $item) {
                    $lines[] = sprintf('  • %s: %s', $item['name'], $item['reason']);
                }
                $lines[] = 'Start your return here (the order is already selected): ' . $result['return_form_url'];
                return implode("\n", $lines);
        }
    }
}
