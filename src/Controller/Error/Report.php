<?php

namespace W2e\Ticpan\Controller\Error;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\JsonFactory;

class Report implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ResourceConnection $resource,
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $body = json_decode($this->request->getContent(), true);
            if (! is_array($body)) {
                return $result->setData(['ok' => false]);
            }

            $message = mb_substr(trim($body['message'] ?? ''), 0, 500);
            if ($message === '') {
                return $result->setData(['ok' => false]);
            }

            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName('w2e_ticpan_js_errors');

            if (! $connection->isTableExists($table)) {
                return $result->setData(['ok' => false]);
            }

            $connection->insert($table, [
                'message'    => $message,
                'source'     => mb_substr($body['source'] ?? '', 0, 500),
                'url'        => mb_substr($body['url'] ?? '', 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Silently ignore — never break the frontend
        }

        return $result->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
