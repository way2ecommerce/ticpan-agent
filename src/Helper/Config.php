<?php

namespace W2e\Ticpan\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED      = 'w2e_ticpan/general/enabled';
    private const XML_PATH_ENDPOINT     = 'w2e_ticpan/general/api_endpoint';
    private const XML_PATH_STORE_ID     = 'w2e_ticpan/general/store_id';
    private const XML_PATH_SECRET_KEY   = 'w2e_ticpan/general/secret_key';
    private const XML_PATH_TIMEOUT      = 'w2e_ticpan/general/timeout';

    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getApiEndpoint(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ENDPOINT);
    }

    public function getStoreId(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_STORE_ID);
    }

    public function getSecretKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_SECRET_KEY);
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function getTimeout(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_PATH_TIMEOUT) ?: 30);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && $this->getStoreId() !== ''
            && $this->getSecretKey() !== ''
            && $this->getApiEndpoint() !== '';
    }
}
