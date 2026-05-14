<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Shell;

class PerfCollector implements CollectorInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly IndexerRegistry $indexerRegistry
    ) {}

    public function collect(): array
    {
        return [
            'mage_mode'               => $this->deploymentConfig->get('MAGE_MODE') ?? $this->getMageMode(),
            'disabled_caches'         => $this->getDisabledCaches(),
            'fpc_enabled'             => $this->isFpcEnabled(),
            'redis_enabled'           => $this->isRedisEnabled(),
            'opcache_enabled'         => function_exists('opcache_get_status') && (bool) opcache_get_status(false),
            'opcache_memory_mb'       => $this->getOpcacheMemoryMb(),
            'php_version'             => PHP_VERSION,
            'elasticsearch_ok'        => $this->isElasticsearchOk(),
            'invalid_indexers'        => $this->getInvalidIndexers(),
            'static_content_deployed' => $this->isStaticContentDeployed(),
            'pub_static_exists'       => is_dir(BP . '/pub/static/frontend'),
        ];
    }

    private function getMageMode(): string
    {
        return (string) ($this->deploymentConfig->get('MAGE_MODE')
            ?? $_SERVER['MAGE_MODE']
            ?? getenv('MAGE_MODE')
            ?? 'default');
    }

    private function getDisabledCaches(): array
    {
        $disabled = [];
        foreach ($this->cacheTypeList->getTypes() as $type) {
            if (! $type->getStatus()) {
                $disabled[] = $type->getId();
            }
        }
        return $disabled;
    }

    private function isFpcEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('system/full_page_cache/caching_application');
    }

    private function isRedisEnabled(): bool
    {
        $sessionBackend = $this->deploymentConfig->get('session/save');
        $cacheBackend   = $this->deploymentConfig->get('cache/frontend/default/backend');
        return $sessionBackend === 'redis' || str_contains((string) $cacheBackend, 'Redis');
    }

    private function getOpcacheMemoryMb(): int
    {
        if (! function_exists('opcache_get_status')) {
            return 0;
        }
        $status = opcache_get_status(false);
        return (int) round(($status['memory_usage']['used_memory'] ?? 0) / 1048576);
    }

    private function isElasticsearchOk(): bool
    {
        $engine = $this->scopeConfig->getValue('catalog/search/engine');
        if (! str_contains((string) $engine, 'elasticsearch') && ! str_contains((string) $engine, 'opensearch')) {
            return true; // not using ES/OS, not applicable
        }
        $host = $this->scopeConfig->getValue('catalog/search/elasticsearch7_server_hostname')
            ?? $this->scopeConfig->getValue('catalog/search/opensearch_server_hostname')
            ?? 'localhost';
        $port = $this->scopeConfig->getValue('catalog/search/elasticsearch7_server_port')
            ?? $this->scopeConfig->getValue('catalog/search/opensearch_server_port')
            ?? 9200;

        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $result = @file_get_contents("http://{$host}:{$port}/_cluster/health", false, $ctx);
        return $result !== false;
    }

    private function getInvalidIndexers(): array
    {
        $invalid = [];
        $indexerIds = [
            'catalog_category_product', 'catalog_product_category',
            'catalog_product_price', 'catalog_product_attribute',
            'cataloginventory_stock', 'catalogrule_rule',
        ];
        foreach ($indexerIds as $id) {
            try {
                $indexer = $this->indexerRegistry->get($id);
                if ($indexer->isInvalid()) {
                    $invalid[] = $id;
                }
            } catch (\Exception) {
                // indexer might not exist in all Magento versions
            }
        }
        return $invalid;
    }

    private function isStaticContentDeployed(): bool
    {
        return is_dir(BP . '/pub/static/frontend')
            && count(glob(BP . '/pub/static/frontend/*') ?: []) > 0;
    }
}
