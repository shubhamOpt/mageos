<?php
declare(strict_types=1);

namespace Vendor\AIAssistant\Controller\Chat;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Vendor\AIAssistant\Model\Assistant;

/**
 * POST /aiassistant/chat/ask
 *
 * HttpPostActionInterface makes Magento reject requests without a valid form_key (CSRF check).
 */
class Ask implements HttpPostActionInterface
{
    private const MAX_LENGTH = 500;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly Assistant $assistant,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $message = trim((string)$this->request->getParam('message'));

        if ($message === '' || mb_strlen($message) > self::MAX_LENGTH) {
            return $result->setHttpResponseCode(400)
                ->setData(['error' => sprintf('Please enter a message of up to %d characters.', self::MAX_LENGTH)]);
        }

        $customerId = $this->customerSession->isLoggedIn()
            ? (int)$this->customerSession->getCustomerId()
            : null;

        try {
            $command = $this->assistant->detectCommand($message);
            if ($command !== null) {
                $answer = $this->assistant->answerCommand($command, $customerId);
            } else {
                $history = json_decode((string)$this->request->getParam('history', '[]'), true);
                $answer = $this->assistant->answer($message, $customerId, is_array($history) ? $history : []);
            }

            return $result->setData(['answer' => $answer]);
        } catch (\Throwable $e) {
            $reference = substr(bin2hex(random_bytes(4)), 0, 8);
            $this->logger->error(sprintf('Vendor_AIAssistant [%s]: %s', $reference, $e->getMessage()), ['exception' => $e]);

            return $result->setHttpResponseCode(503)->setData([
                'error' => sprintf(
                    'The assistant is unavailable right now (reference %s). '
                    . 'Type /lastorder or /returnlastorder, or use the buttons below.',
                    $reference
                ),
            ]);
        }
    }
}
