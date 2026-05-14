<?php

namespace W2e\Ticpan\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use W2e\Ticpan\Helper\Config;
use W2e\Ticpan\Model\Collector\CollectorPool;

class Agent
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectorPool $collectorPool,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {}

    public function run(): void
    {
        if (! $this->config->isConfigured()) {
            $this->logger->warning('W2e_Ticpan: agent not configured, skipping report.');
            return;
        }

        try {
            $payload = $this->buildPayload();
            $this->send($payload);
        } catch (\Throwable $e) {
            $this->logger->error('W2e_Ticpan: error sending report — ' . $e->getMessage());
        }
    }

    private function buildPayload(): array
    {
        $data = [];
        foreach ($this->collectorPool->getCollectors() as $pilar => $collector) {
            try {
                $data[$pilar] = $collector->collect();
            } catch (\Throwable $e) {
                $this->logger->error("W2e_Ticpan: collector [{$pilar}] failed — " . $e->getMessage());
                $data[$pilar] = [];
            }
        }

        return [
            'agent_version'    => '1.0.0',
            'store_id'         => $this->config->getStoreId(),
            'timestamp'        => time(),
            'magento_version'  => $this->productMetadata->getVersion(),
            'php_version'      => PHP_VERSION,
            'is_cloud_edition' => $this->detectCloudEdition(),
            'data'             => $data,
        ];
    }

    private function send(array $payload): void
    {
        $body      = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $storeId   = $this->config->getStoreId();
        $signature = 'sha256=' . hash_hmac('sha256', $body, $this->config->getSecretKey());

        $this->curl->setTimeout($this->config->getTimeout());
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-Ticpan-Store-Id', $storeId);
        $this->curl->addHeader('X-Ticpan-Timestamp', (string) $timestamp);
        $this->curl->addHeader('X-Ticpan-Signature', $signature);

        // Gzip compress if payload > 64KB
        if (strlen($body) > 65536) {
            $body = gzencode($body, 6);
            $this->curl->addHeader('Content-Encoding', 'gzip');
        }

        $this->curl->post($this->config->getApiEndpoint(), $body);

        $status = $this->curl->getStatus();
        if ($status !== 202) {
            throw new \RuntimeException("Unexpected HTTP {$status} from Ticpan API");
        }

        $this->logger->info("W2e_Ticpan: report sent successfully (HTTP {$status}).");
    }

    private function detectCloudEdition(): bool
    {
        $lockFile = BP . '/composer.lock';
        if (! file_exists($lockFile)) {
            return false;
        }
        $lock = json_decode(file_get_contents($lockFile), true);
        foreach ($lock['packages'] ?? [] as $package) {
            if ($package['name'] === 'magento/magento-cloud-metapackage') {
                return true;
            }
        }
        return false;
    }
}
