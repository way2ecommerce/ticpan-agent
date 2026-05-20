<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class SeoCollector implements CollectorInterface
{
    public function __construct(private readonly ResourceConnection $resource) {}

    public function collect(): array
    {
        return [
            'product_meta_stats' => $this->getProductMetaStats(),
            'sample_pdp_urls'    => $this->getSamplePdpUrls(),
        ];
    }

    /**
     * Returns up to 8 request_paths of enabled products from url_rewrite.
     * Uses the default store view's store_id to ensure URLs belong to the main store.
     * Used by cloud evaluators (SEO-04, SEO-07, SEO-08) when no sitemap is available.
     */
    private function getSamplePdpUrls(): array
    {
        try {
            $connection   = $this->resource->getConnection();
            $cpe          = $this->resource->getTableName('catalog_product_entity');
            $cpei         = $this->resource->getTableName('catalog_product_entity_int');
            $urlRewrite   = $this->resource->getTableName('url_rewrite');
            $statusAttrId = $this->getAttributeId($connection, 'status');

            if (! $statusAttrId) return [];

            // Resolve default store_id from the primary store group (group_id=1)
            $groupTable = $this->resource->getTableName('store_group');
            $storeId = (int) $connection->fetchOne(
                $connection->select()->from($groupTable, ['default_store_id'])
                    ->where('group_id = 1')
                    ->limit(1)
            ) ?: 1;

            $visibilityAttrId = $this->getAttributeId($connection, 'visibility');

            $select = $connection->select()
                ->from(['r' => $urlRewrite], ['r.request_path'])
                ->join(['e' => $cpe], 'e.entity_id = r.entity_id', [])
                ->join(
                    ['s' => $cpei],
                    "s.entity_id = e.entity_id AND s.attribute_id = {$statusAttrId} AND s.store_id = 0",
                    []
                )
                ->where('r.entity_type = ?', 'product')
                ->where('r.store_id = ?', $storeId)
                ->where('r.redirect_type = ?', 0)
                ->where('e.type_id IN (?)', ['simple', 'virtual', 'downloadable', 'configurable'])
                ->where('s.value = ?', 1);

            // Filter out products not visible individually (child simples of configurables)
            if ($visibilityAttrId) {
                $select->join(
                    ['v' => $cpei],
                    "v.entity_id = e.entity_id AND v.attribute_id = {$visibilityAttrId} AND v.store_id = 0",
                    []
                )->where('v.value != ?', 1); // 1 = Not Visible Individually
            }

            $select->order(new \Zend_Db_Expr('RAND()'))->limit(8);

            $paths = $connection->fetchCol($select);

            return array_values(array_map(fn($p) => '/' . ltrim($p, '/'), $paths));
        } catch (\Throwable) {
            return [];
        }
    }

    private function getProductMetaStats(): array
    {
        try {
            $connection = $this->resource->getConnection();

            $statusAttrId    = $this->getAttributeId($connection, 'status');
            $metaTitleAttrId = $this->getAttributeId($connection, 'meta_title');
            $metaDescAttrId  = $this->getAttributeId($connection, 'meta_description');

            if (! $statusAttrId || ! $metaTitleAttrId || ! $metaDescAttrId) {
                return [];
            }

            $cpe  = $this->resource->getTableName('catalog_product_entity');
            $cpei = $this->resource->getTableName('catalog_product_entity_int');
            $cpev = $this->resource->getTableName('catalog_product_entity_varchar');

            $enabledIds = $connection->fetchCol(
                $connection->select()
                    ->from(['e' => $cpe], ['e.entity_id'])
                    ->join(
                        ['s' => $cpei],
                        "s.entity_id = e.entity_id AND s.attribute_id = {$statusAttrId} AND s.store_id = 0",
                        []
                    )
                    ->where('e.type_id IN (?)', ['simple', 'virtual', 'downloadable'])
                    ->where('s.value = ?', 1)
            );

            $total = count($enabledIds);
            if ($total === 0) {
                return ['total' => 0, 'with_meta_title_pct' => 100.0, 'with_meta_desc_pct' => 100.0,
                        'without_title_count' => 0, 'without_desc_count' => 0];
            }

            $withTitle = $this->countNonEmpty($connection, $cpev, $metaTitleAttrId, $enabledIds);
            $withDesc  = $this->countNonEmpty($connection, $cpev, $metaDescAttrId,  $enabledIds);

            return [
                'total'               => $total,
                'with_meta_title_pct' => round($withTitle / $total * 100, 1),
                'with_meta_desc_pct'  => round($withDesc  / $total * 100, 1),
                'without_title_count' => $total - $withTitle,
                'without_desc_count'  => $total - $withDesc,
            ];
        } catch (\Throwable) {
            return [];
        }
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

    private function countNonEmpty($connection, string $table, int $attrId, array $entityIds): int
    {
        $count = 0;
        foreach (array_chunk($entityIds, 1000) as $chunk) {
            $count += (int) $connection->fetchOne(
                $connection->select()
                    ->from($table, ['COUNT(DISTINCT entity_id)'])
                    ->where('attribute_id = ?', $attrId)
                    ->where('store_id = ?', 0)
                    ->where('entity_id IN (?)', $chunk)
                    ->where('value IS NOT NULL')
                    ->where('TRIM(value) != ?', '')
            );
        }
        return $count;
    }
}
