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
        ];
    }

    private function getProductMetaStats(): array
    {
        try {
            $connection = $this->resource->getConnection();

            $statusAttrId    = $this->getAttributeId($connection, 'status');
            $typeAttrId      = $this->getAttributeId($connection, 'type_id');
            $metaTitleAttrId = $this->getAttributeId($connection, 'meta_title');
            $metaDescAttrId  = $this->getAttributeId($connection, 'meta_description');

            if (! $statusAttrId || ! $metaTitleAttrId || ! $metaDescAttrId) {
                return [];
            }

            $cpe  = $this->resource->getTableName('catalog_product_entity');
            $cpei = $this->resource->getTableName('catalog_product_entity_int');
            $cpev = $this->resource->getTableName('catalog_product_entity_varchar');

            // Enabled products of evaluable types (simple, virtual, downloadable)
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
        // Process in chunks to avoid hitting IN() limits
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
