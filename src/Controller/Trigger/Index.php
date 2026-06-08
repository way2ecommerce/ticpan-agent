<?php

namespace W2e\Ticpan\Controller\Trigger;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use W2e\Ticpan\Helper\Config;
use W2e\Ticpan\Model\Agent;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private RequestInterface $request;
    private JsonFactory      $jsonFactory;
    private Config           $config;
    private Agent            $agent;

    public function __construct(
        RequestInterface $request,
        JsonFactory      $jsonFactory,
        Config           $config,
        Agent            $agent
    ) {
        $this->request     = $request;
        $this->jsonFactory = $jsonFactory;
        $this->config      = $config;
        $this->agent       = $agent;
    }

    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $timestamp = (int) $this->request->getHeader('X-Ticpan-Timestamp');
        $signature = (string) $this->request->getHeader('X-Ticpan-Signature');
        $body      = (string) $this->request->getContent();

        if (! $this->config->isConfigured()) {
            return $result->setHttpResponseCode(503)->setData(['error' => 'Agent not configured']);
        }

        // Anti-replay: 300 second window
        if (abs(time() - $timestamp) > 300) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'Timestamp outside replay window']);
        }

        // HMAC-SHA256 verification (Ticpan signs, Magento verifies — same shared secret)
        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->config->getSecretKey());
        if (! hash_equals($expected, $signature)) {
            return $result->setHttpResponseCode(401)->setData(['error' => 'Invalid signature']);
        }

        // Run agent immediately (errors are caught internally by Agent::run())
        $this->agent->run();

        return $result->setHttpResponseCode(202)->setData(['status' => 'triggered']);
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
