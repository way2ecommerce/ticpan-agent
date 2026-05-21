<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;

class BizCollector implements CollectorInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {}

    public function collect(): array
    {
        $connection = $this->resource->getConnection();

        $statusAttrId = $this->getAttributeId($connection, 'status');
        $totalActive  = $this->countActiveProducts($connection, $statusAttrId);

        $priceRules = $this->getPriceRulesDetail($connection);

        return [
            'pct_products_without_image' => $this->getPctWithoutImage($connection, $statusAttrId, $totalActive),
            'pct_without_desc'           => $this->getPctWithoutDesc($connection, $statusAttrId, $totalActive),
'problematic_stock_pct'      => $this->getProblematicStockPct($connection, $statusAttrId, $totalActive),
            'problematic_stock_detail'   => $this->getProblematicStockDetail($connection, $statusAttrId),
            'price_rule_conflicts'       => $this->getPriceRuleConflicts($connection),
            'guest_checkout_enabled'     => $this->isGuestCheckoutEnabled(),
            // BIZ-05 AI detail
            'catalogrule_active_count'   => count($priceRules['catalog']),
            'catalogrule_rules'          => $priceRules['catalog'],
            'salesrule_active_count'     => count($priceRules['sales']),
            'salesrule_rules'            => $priceRules['sales'],
        ];
    }

    private function getAttributeId($connection, string $code): ?int
    {
        $table = $this->resource->getTableName('eav_attribute');
        $id    = $connection->fetchOne(
            $connection->select()
                ->from($table, ['attribute_id'])
                ->where('attribute_code = ?', $code)
                ->where('entity_type_id = ?', 4)
        );

        return $id ? (int) $id : null;
    }

    private function countActiveProducts($connection, ?int $statusAttrId): int
    {
        if (! $statusAttrId) return 0;

        $cpe  = $this->resource->getTableName('catalog_product_entity');
        $cpei = $this->resource->getTableName('catalog_product_entity_int');

        return (int) $connection->fetchOne(
            $connection->select()
                ->from(['cpe' => $cpe], ['COUNT(*)'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable', 'configurable', 'bundle'])
        );
    }

    private function getPctWithoutImage($connection, ?int $statusAttrId, int $totalActive): ?float
    {
        if (! $statusAttrId || $totalActive === 0) return null;

        $cpe    = $this->resource->getTableName('catalog_product_entity');
        $cpei   = $this->resource->getTableName('catalog_product_entity_int');
        $gallery = $this->resource->getTableName('catalog_product_entity_media_gallery_value');

        $withImage = $connection->select()
            ->from(['g' => $gallery], ['entity_id'])
            ->where('g.position = 1')
            ->where('g.disabled = 0');

        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from(['cpe' => $cpe], ['COUNT(DISTINCT cpe.entity_id)'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable', 'configurable', 'bundle'])
                ->where('cpe.entity_id NOT IN (?)', $withImage)
        );

        return round($count / $totalActive * 100, 2);
    }

    private function getPctWithoutDesc($connection, ?int $statusAttrId, int $totalActive): ?float
    {
        if (! $statusAttrId || $totalActive === 0) return null;

        $descAttrId = $this->getAttributeId($connection, 'description');
        if (! $descAttrId) return null;

        $cpe   = $this->resource->getTableName('catalog_product_entity');
        $cpei  = $this->resource->getTableName('catalog_product_entity_int');
        $cpet  = $this->resource->getTableName('catalog_product_entity_text');

        // Products with sufficient description (>= 50 chars)
        $withDesc = $connection->select()
            ->from(['t' => $cpet], ['entity_id'])
            ->where('t.attribute_id = ?', $descAttrId)
            ->where('CHAR_LENGTH(t.value) >= 50');

        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from(['cpe' => $cpe], ['COUNT(DISTINCT cpe.entity_id)'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable', 'configurable', 'bundle'])
                ->where('cpe.entity_id NOT IN (?)', $withDesc)
        );

        return round($count / $totalActive * 100, 2);
    }

    private function getProblematicStockPct($connection, ?int $statusAttrId, int $totalActive): ?float
    {
        if (! $statusAttrId || $totalActive === 0) return null;

        $cpe        = $this->resource->getTableName('catalog_product_entity');
        $cpei       = $this->resource->getTableName('catalog_product_entity_int');
        $stockStatus = $this->resource->getTableName('cataloginventory_stock_status');

        if (! $connection->isTableExists($stockStatus)) return null;

        $visAttrId = $this->getAttributeId($connection, 'visibility');
        if (! $visAttrId) return null;

        // Active + individually visible + out of stock (stock_status = 0)
        // cataloginventory_stock_status is kept in sync by Magento indexers
        // for both legacy inventory and MSI, making it safe in all configurations.
        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from(['cpe' => $cpe], ['COUNT(DISTINCT cpe.entity_id)'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->join(['vis' => $cpei],
                    "cpe.entity_id = vis.entity_id AND vis.attribute_id = {$visAttrId} AND vis.value > 1",
                    [])
                ->join(['ss' => $stockStatus],
                    'cpe.entity_id = ss.product_id AND ss.stock_status = 0 AND ss.stock_id = 1',
                    [])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
        );

        return round($count / $totalActive * 100, 2);
    }

    private function getProblematicStockDetail($connection, ?int $statusAttrId): array
    {
        if (! $statusAttrId) return [];

        $cpe        = $this->resource->getTableName('catalog_product_entity');
        $cpei       = $this->resource->getTableName('catalog_product_entity_int');
        $stockStatus = $this->resource->getTableName('cataloginventory_stock_status');
        $stockItem   = $this->resource->getTableName('cataloginventory_stock_item');
        $nameAttrId  = $this->getAttributeId($connection, 'name');
        $visAttrId   = $this->getAttributeId($connection, 'visibility');

        if (! $connection->isTableExists($stockStatus) || ! $visAttrId || ! $nameAttrId) return [];

        $nameTable = $this->resource->getTableName('catalog_product_entity_varchar');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['cpe' => $cpe], ['sku'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->join(['vis' => $cpei],
                    "cpe.entity_id = vis.entity_id AND vis.attribute_id = {$visAttrId} AND vis.value > 1",
                    [])
                ->join(['ss' => $stockStatus],
                    'cpe.entity_id = ss.product_id AND ss.stock_status = 0 AND ss.stock_id = 1',
                    [])
                ->joinLeft(['si' => $stockItem],
                    'cpe.entity_id = si.product_id AND si.stock_id = 1',
                    ['qty'])
                ->joinLeft(['n' => $nameTable],
                    "cpe.entity_id = n.entity_id AND n.attribute_id = {$nameAttrId} AND n.store_id = 0",
                    ['name' => 'value'])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
                ->order('si.qty ASC')
                ->limit(40)
        );

        return array_map(fn ($r) => [
            'sku'  => $r['sku'],
            'name' => $r['name'] ?? '',
            'qty'  => $r['qty'] !== null ? (float) $r['qty'] : null,
        ], $rows);
    }

    private function getPriceRuleConflicts($connection): int
    {
        $catalogRule = $this->resource->getTableName('catalogrule');
        $salesRule   = $this->resource->getTableName('salesrule');

        // Both tables may not exist in all Magento installs
        if (! $connection->isTableExists($catalogRule) || ! $connection->isTableExists($salesRule)) {
            return 0;
        }

        // Active catalog rules
        $activeCatalogRules = (int) $connection->fetchOne(
            $connection->select()
                ->from($catalogRule, ['COUNT(*)'])
                ->where('is_active = 1')
        );

        // Active sales rules with "stop further rules" = 0 that could stack unexpectedly
        // Conflict heuristic: more than 3 active catalog rules (likely overlapping)
        if ($activeCatalogRules > 3) {
            return $activeCatalogRules - 3;
        }

        return 0;
    }

    private function getPriceRulesDetail($connection): array
    {
        $empty = ['catalog' => [], 'sales' => []];

        $cacheFile = BP . '/var/ticpan_price_rules.cache';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $catalogTable = $this->resource->getTableName('catalogrule');
        $salesTable   = $this->resource->getTableName('salesrule');

        if (! $connection->isTableExists($catalogTable) || ! $connection->isTableExists($salesTable)) {
            return $empty;
        }

        $catalogRules = $connection->fetchAll(
            $connection->select()
                ->from($catalogTable, [
                    'rule_id', 'name', 'is_active', 'from_date', 'to_date',
                    'simple_action', 'discount_amount', 'stop_rules_processing',
                ])
                ->where('is_active = 1')
                ->where('to_date IS NULL OR to_date >= ?', date('Y-m-d'))
                ->order('sort_order ASC')
                ->limit(30)
        );

        $salesRules = $connection->fetchAll(
            $connection->select()
                ->from($salesTable, [
                    'rule_id', 'name', 'is_active', 'from_date', 'to_date',
                    'simple_action', 'discount_amount', 'stop_rules_processing',
                    'coupon_type', 'uses_per_customer', 'simple_free_shipping',
                ])
                ->where('is_active = 1')
                ->where('to_date IS NULL OR to_date >= ?', date('Y-m-d'))
                ->order('sort_order ASC')
                ->limit(30)
        );

        $result = ['catalog' => $catalogRules, 'sales' => $salesRules];
        file_put_contents($cacheFile, json_encode($result));

        return $result;
    }

    private function isGuestCheckoutEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'checkout/options/guest_checkout',
            ScopeInterface::SCOPE_STORE
        );
    }
}
