<?php
declare(strict_types=1);

namespace Vendor\AIAssistant\Model;

use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory;

/**
 * Storefront assistant.
 *
 * Commands are answered directly from the database: instant, free, and still working when the
 * AI provider is down.
 *   - "/lastorder", "show my last order"            → last order summary
 *   - "/returnlastorder", "return my last order"    → can it be returned, and a link to the form
 * Everything else goes to the AI, which can call the same two lookups as tools.
 */
class Assistant
{
    public const CONSUMER = 'vendor_ai_assistant';

    public const COMMAND_LAST_ORDER = 'last_order';
    public const COMMAND_RETURN_LAST_ORDER = 'return_last_order';

    /** Checked first: "return my last order" also contains "my last order". */
    private const RETURN_COMMAND_PATTERN = '/^\s*\/return[\s_-]?last[\s_-]?order\s*$|\breturn\s+(my\s+|the\s+)?(last|latest|most recent|recent)\s+order\b/i';
    private const LAST_ORDER_COMMAND_PATTERN = '/^\s*\/?last[\s_-]?order\s*$|\b(my|the)\s+(last|latest|most recent|recent)\s+order\b/i';

    private const SYSTEM_PROMPT = 'You are the customer assistant of this online store. Be brief and friendly. '
        . 'When the customer asks about their last, latest or most recent order, call the get_last_order tool. '
        . 'When they want to return, send back or get a refund for their last order, call the '
        . 'check_last_order_return tool. If it is eligible, list the returnable items and give the return_form_url; '
        . 'explain that they choose the items, reason and refund or exchange on that form. '
        . 'If it is not eligible, explain why using the status and details it returns. '
        . 'Answer only from tool results. Never invent order numbers, statuses, prices, dates or return rules, '
        . 'and never claim that a return has been created. '
        . 'If a tool says the customer is not logged in, ask them to sign in. '
        . 'For anything you cannot answer, suggest contacting customer service. '
        . 'Reply in plain text only: no Markdown, no asterisks, no headings. Put links as plain URLs.';

    private const MAX_TOOL_ROUNDS = 3;
    private const MAX_HISTORY_TURNS = 10;

    public function __construct(
        private readonly AiClientFactoryInterface $clientFactory,
        private readonly ChatRequestBuilderInterfaceFactory $builderFactory,
        private readonly LastOrderProvider $lastOrderProvider,
        private readonly ReturnLastOrderProvider $returnLastOrderProvider
    ) {
    }

    /**
     * @return string|null One of the COMMAND_* constants, or null for a free-form question
     */
    public function detectCommand(string $message): ?string
    {
        if (preg_match(self::RETURN_COMMAND_PATTERN, $message)) {
            return self::COMMAND_RETURN_LAST_ORDER;
        }
        if (preg_match(self::LAST_ORDER_COMMAND_PATTERN, $message)) {
            return self::COMMAND_LAST_ORDER;
        }

        return null;
    }

    /**
     * Answer a command without the AI.
     */
    public function answerCommand(string $command, ?int $customerId): string
    {
        if ($customerId === null) {
            return $command === self::COMMAND_RETURN_LAST_ORDER
                ? 'Please sign in to your account so I can check whether your last order can be returned.'
                : 'Please sign in to your account so I can look up your last order.';
        }

        if ($command === self::COMMAND_RETURN_LAST_ORDER) {
            return $this->returnLastOrderProvider->format($this->returnLastOrderProvider->check($customerId));
        }

        $order = $this->lastOrderProvider->getForCustomer($customerId);

        return $order === null
            ? 'You have not placed any orders in this store yet.'
            : $this->lastOrderProvider->format($order);
    }

    /**
     * Free-form question, answered by the AI with access to the order tools.
     *
     * @param array<int, array{role: string, text: string}> $history
     */
    public function answer(string $message, ?int $customerId, array $history = []): string
    {
        $client = $this->clientFactory->create(null, self::CONSUMER);
        $noArguments = ['type' => 'object', 'properties' => []];

        $builder = $this->builderFactory->create()
            ->withSystemMessage(self::SYSTEM_PROMPT)
            ->withTool(
                'get_last_order',
                'Returns the signed-in customer\'s most recent order: number, date, status, total, items and a link.',
                $noArguments
            )
            ->withTool(
                'check_last_order_return',
                'Checks whether the signed-in customer\'s most recent order can be returned under the store\'s return '
                . 'rules. Returns a status (eligible, returns_disabled, order_status_not_returnable, '
                . 'return_period_expired, nothing_left_to_return, no_orders), the returnable items and, when eligible, '
                . 'the URL of the return form with the order preselected. It does not create a return.',
                $noArguments
            );

        foreach (array_slice($history, -self::MAX_HISTORY_TURNS) as $turn) {
            $text = (string)($turn['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $builder = ($turn['role'] ?? '') === 'assistant'
                ? $builder->withAssistantMessage($text)
                : $builder->withUserMessage($text);
        }
        $builder = $builder->withUserMessage($message);

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $client->chat($builder->build(), ['max_tokens' => 500]);
            if (!$response->hasToolCalls()) {
                return trim($response->getText()) ?: 'Sorry, I don\'t have an answer for that.';
            }

            $builder = $builder->withAssistantTurn($response);
            foreach ($response->getToolCalls() as $call) {
                $builder = $builder->withToolResult($call, json_encode(
                    $this->runTool($call->getName(), $customerId),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }
        }

        return 'Sorry, I could not finish that request. Please try again.';
    }

    /**
     * Only tools listed here can ever run, whatever the model asks for.
     * The customer ID always comes from the session, never from the model.
     */
    private function runTool(string $name, ?int $customerId): array
    {
        if (!in_array($name, ['get_last_order', 'check_last_order_return'], true)) {
            return ['error' => 'Unknown tool'];
        }
        if ($customerId === null) {
            return ['error' => 'not_logged_in'];
        }

        if ($name === 'check_last_order_return') {
            return $this->returnLastOrderProvider->check($customerId);
        }

        return $this->lastOrderProvider->getForCustomer($customerId) ?? ['result' => 'no_orders'];
    }
}
