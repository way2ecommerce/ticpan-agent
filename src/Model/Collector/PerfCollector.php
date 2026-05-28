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
            'opcache_enabled'              => $this->isOpcacheEnabled(),
            'opcache_memory_mb'            => $this->getOpcacheMemoryMb(),
            'opcache_max_accelerated_files'=> $this->getOpcacheMaxAcceleratedFiles(),
            'opcache_validate_timestamps'  => $this->getOpcacheValidateTimestamps(),
            'realpath_cache_size_bytes'    => $this->getRealpathCacheSizeBytes(),
            'realpath_cache_ttl'           => (int) ini_get('realpath_cache_ttl'),
            'php_version'             => PHP_VERSION,
            'search_engine'           => $this->getSearchEngine(),
            'elasticsearch_ok'        => $this->isElasticsearchOk(),
            'invalid_indexers'        => $this->getInvalidIndexers(),
            'static_content_deployed' => $this->isStaticContentDeployed(),
            'pub_static_exists'       => count(glob(BP . '/pub/static/frontend/*/*') ?: []) > 0,
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

    private function isOpcacheEnabled(): bool
    {
        if (! function_exists('opcache_get_status')) return false;
        $status = opcache_get_status(false);
        // opcache_get_status() returns false when OPCache is disabled for CLI but enabled for FPM.
        // In that case fall back to the ini config, which reflects the FPM setting.
        if ($status !== false) return (bool) ($status['opcache_enabled'] ?? false);
        return function_exists('opcache_get_configuration')
            && (bool) (opcache_get_configuration()['directives']['opcache.enable'] ?? false);
    }

    private function getOpcacheMemoryMb(): int
    {
        if (! function_exists('opcache_get_configuration')) return 0;
        // opcache.memory_consumption is the CONFIGURED limit in MB — what matters for the rule.
        // Used memory (opcache_get_status) can be well below the limit on a fresh server.
        return (int) (opcache_get_configuration()['directives']['opcache.memory_consumption'] ?? 0);
    }

    private function getOpcacheMaxAcceleratedFiles(): int
    {
        if (! function_exists('opcache_get_configuration')) return 0;
        return (int) (opcache_get_configuration()['directives']['opcache.max_accelerated_files'] ?? 0);
    }

    private function getOpcacheValidateTimestamps(): bool
    {
        if (! function_exists('opcache_get_configuration')) return true; // assume worst case
        return (bool) (opcache_get_configuration()['directives']['opcache.validate_timestamps'] ?? true);
    }

    private function getRealpathCacheSizeBytes(): int
    {
        $raw = ini_get('realpath_cache_size');
        if ($raw === false || $raw === '') return 0;
        // ini_get returns the raw ini string which may use shorthand notation (10M, 512K, 1G)
        $raw   = trim($raw);
        $unit  = strtoupper(substr($raw, -1));
        $value = (int) $raw;
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    private function getSearchEngine(): string
    {
        return (string) ($this->scopeConfig->getValue('catalog/search/engine') ?? 'mysql');
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
            'catalogsearch_fulltext',
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
        // Check for at least one Vendor/theme subdirectory (depth 2), not just any file.
        // A .gitkeep or a partial failed deploy would pass the old depth-1 check.
        return is_dir(BP . '/pub/static/frontend')
            && count(glob(BP . '/pub/static/frontend/*/*') ?: []) > 0;
    }
}
