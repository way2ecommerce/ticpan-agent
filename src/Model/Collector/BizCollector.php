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

        return [
            'pct_products_without_image' => $this->getPctWithoutImage($connection, $statusAttrId, $totalActive),
            'pct_without_desc'           => $this->getPctWithoutDesc($connection, $statusAttrId, $totalActive),
            'duplicate_sku_count'        => $this->getDuplicateSkuCount($connection),
            'problematic_stock_pct'      => $this->getProblematicStockPct($connection, $statusAttrId, $totalActive),
            'price_rule_conflicts'       => $this->getPriceRuleConflicts($connection),
            'guest_checkout_enabled'     => $this->isGuestCheckoutEnabled(),
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

    private function getDuplicateSkuCount($connection): int
    {
        $cpe = $this->resource->getTableName('catalog_product_entity');

        $sub = $connection->select()
            ->from(['cpe' => $cpe], ['sku', 'cnt' => 'COUNT(*)'])
            ->group('sku')
            ->having('COUNT(*) > 1');

        $rows = $connection->fetchAll($sub);

        return count($rows);
    }

    private function getProblematicStockPct($connection, ?int $statusAttrId, int $totalActive): ?float
    {
        if (! $statusAttrId || $totalActive === 0) return null;

        $cpe   = $this->resource->getTableName('catalog_product_entity');
        $cpei  = $this->resource->getTableName('catalog_product_entity_int');
        $stock = $this->resource->getTableName('cataloginventory_stock_item');

        // Enabled products that are out of stock but still visible
        $visAttrId = $this->getAttributeId($connection, 'visibility');
        if (! $visAttrId) return null;

        // Products: active + visible (not "Not Visible Individually" = 1) + out of stock
        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from(['cpe' => $cpe], ['COUNT(DISTINCT cpe.entity_id)'])
                ->join(['cpei' => $cpei],
                    "cpe.entity_id = cpei.entity_id AND cpei.attribute_id = {$statusAttrId} AND cpei.value = 1",
                    [])
                ->join(['vis' => $cpei],
                    "cpe.entity_id = vis.entity_id AND vis.attribute_id = {$visAttrId} AND vis.value > 1",
                    [])
                ->join(['s' => $stock],
                    'cpe.entity_id = s.product_id AND s.is_in_stock = 0 AND s.manage_stock = 1',
                    [])
                ->where('cpe.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
        );

        return round($count / $totalActive * 100, 2);
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

    private function isGuestCheckoutEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'checkout/options/guest_checkout',
            ScopeInterface::SCOPE_STORE
        );
    }
}
