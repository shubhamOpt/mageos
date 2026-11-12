<?php
declare(strict_types=1);

namespace Vendor\AIAssistant\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Looks up the most recent order of ONE customer.
 *
 * The customer ID always comes from the server-side session, never from the request or the AI,
 * so a shopper (or a tricked model) can never read someone else's order.
 */
class LastOrderProvider
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<string, mixed>|null Order summary, or null when the customer has no orders
     */
    public function getForCustomer(int $customerId): ?array
    {
        $order = $this->getLastOrder($customerId);

        return $order === null ? null : $this->summarize($order);
    }

    /**
     * The customer's most recent order in the current store, or null.
     */
    public function getLastOrder(int $customerId): ?OrderInterface
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField(OrderInterface::CREATED_AT)
            ->setDescendingDirection()
            ->create();

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::CUSTOMER_ID, $customerId)
            ->addFilter(OrderInterface::STORE_ID, (int)$this->storeManager->getStore()->getId())
            ->setSortOrders([$sortOrder])
            ->setPageSize(1)
            ->setCurrentPage(1)
            ->create();

        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        return $order ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                // Sample data and some imports store names with HTML entities (e.g. &trade;).
                'name' => html_entity_decode((string)$item->getName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => (string)$item->getSku(),
                'qty' => (float)$item->getQtyOrdered(),
                'price' => $order->formatPriceTxt($item->getPrice()),
            ];
        }

        return [
            'order_number' => (string)$order->getIncrementId(),
            'placed_at' => (string)$order->getCreatedAt(),
            'status' => (string)$order->getStatusLabel(),
            'grand_total' => $order->formatPriceTxt($order->getGrandTotal()),
            'shipping_method' => (string)$order->getShippingDescription(),
            'items' => $items,
            'view_url' => $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $order->getEntityId()]),
        ];
    }

    /**
     * Plain-text version for the chat command, no AI involved.
     */
    public function format(array $order): string
    {
        $lines = [
            sprintf('Your last order is #%s, placed on %s.', $order['order_number'], substr($order['placed_at'], 0, 10)),
            sprintf('Status: %s', $order['status']),
            sprintf('Total: %s', $order['grand_total']),
        ];
        if ($order['shipping_method'] !== '') {
            $lines[] = sprintf('Shipping: %s', $order['shipping_method']);
        }
        $lines[] = 'Items:';
        foreach ($order['items'] as $item) {
            $lines[] = sprintf('  • %s × %s (%s)', $item['name'], rtrim(rtrim(number_format($item['qty'], 2, '.', ''), '0'), '.'), $item['price']);
        }
        $lines[] = 'Details: ' . $order['view_url'];

        return implode("\n", $lines);
    }
}
