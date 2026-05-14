<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Module\StatusFactory;

class OpsCollector implements CollectorInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ModuleManager $moduleManager
    ) {}

    public function collect(): array
    {
        return [
            'cron_seconds_since_last_run' => $this->getCronSecondsSinceLastRun(),
            'cron_stuck_jobs_count'       => $this->getCronStuckJobsCount(),
            'mq_enabled'                  => $this->isMqEnabled(),
            'mq_pending_messages_total'   => $this->getMqPendingTotal(),
            'mq_active_consumers'         => $this->getMqActiveConsumers(),
            'last_backup_timestamp'       => $this->getLastBackupTimestamp(),
            'backup_threshold_hours'      => 24,
            'disk_volumes'                => $this->getDiskVolumes(),
            'disk_threshold_pct'          => 80,
            'agent_self_check'            => $this->selfCheck(),
        ];
    }

    private function getCronSecondsSinceLastRun(): ?int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('cron_schedule');

        if (! $connection->isTableExists($table)) {
            return null;
        }

        $select = $connection->select()
            ->from($table, ['finished_at'])
            ->where('status = ?', 'success')
            ->order('finished_at DESC')
            ->limit(1);

        $last = $connection->fetchOne($select);
        if (! $last) {
            return null;
        }

        return (int) (time() - strtotime($last));
    }

    private function getCronStuckJobsCount(): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('cron_schedule');

        if (! $connection->isTableExists($table)) {
            return 0;
        }

        $thirtyMinutesAgo = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        $select = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('status = ?', 'running')
            ->where('executed_at < ?', $thirtyMinutesAgo);

        return (int) $connection->fetchOne($select);
    }

    private function isMqEnabled(): bool
    {
        $connection = $this->resource->getConnection();
        return $connection->isTableExists($this->resource->getTableName('queue_message'));
    }

    private function getMqPendingTotal(): int
    {
        if (! $this->isMqEnabled()) {
            return 0;
        }
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('queue_message');
        $select     = $connection->select()->from($table, ['COUNT(*)'])->where('status = ?', 0);
        return (int) $connection->fetchOne($select);
    }

    private function getMqActiveConsumers(): array
    {
        if (! $this->isMqEnabled()) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('queue_lock');
        if (! $connection->isTableExists($table)) {
            return [];
        }
        $fiveMinutesAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $select = $connection->select()
            ->from($table, ['consumer_name', 'updated_at'])
            ->where('updated_at > ?', $fiveMinutesAgo);
        return $connection->fetchAll($select);
    }

    private function getLastBackupTimestamp(): ?int
    {
        // Strategy A: check var/backups/ directory
        $backupDir = BP . '/var/backups/';
        if (is_dir($backupDir)) {
            $files = glob($backupDir . '*.sql*') ?: [];
            if ($files) {
                return (int) max(array_map('filemtime', $files));
            }
        }
        return null;
    }

    private function getDiskVolumes(): array
    {
        $volumes = [];
        $paths   = [BP, sys_get_temp_dir()];
        $seen    = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) continue;
            $total = disk_total_space($path);
            $free  = disk_free_space($path);
            if ($total === false || $total === 0) continue;

            $realPath = realpath($path);
            if (isset($seen[$realPath])) continue;
            $seen[$realPath] = true;

            $usedPct = round((($total - $free) / $total) * 100, 1);
            $volumes[] = [
                'path'     => $path,
                'total_gb' => round($total / 1073741824, 1),
                'free_gb'  => round($free / 1073741824, 1),
                'used_pct' => $usedPct,
            ];
        }

        return $volumes;
    }

    private function selfCheck(): array
    {
        return [
            'module_enabled' => $this->moduleManager->isEnabled('W2e_Ticpan'),
            'cron_ok'        => $this->isCronInternalOk(),
            'log_table_ok'   => $this->isLogTableOk(),
        ];
    }

    private function isCronInternalOk(): ?bool
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('cron_schedule');

        if (! $connection->isTableExists($table)) {
            return null;
        }

        $twoHoursAgo = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $select = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('job_code = ?', 'ticpan_agent_heartbeat')
            ->where('status = ?', 'success')
            ->where('finished_at > ?', $twoHoursAgo);

        $count = (int) $connection->fetchOne($select);

        // null = first run (no history yet), true/false otherwise
        $totalSelect = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('job_code = ?', 'ticpan_agent_heartbeat');
        $total = (int) $connection->fetchOne($totalSelect);

        return $total === 0 ? null : ($count > 0);
    }

    private function isLogTableOk(): ?bool
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('w2e_ticpan_js_errors');

        if (! $connection->isTableExists($table)) {
            return null; // table not present, skip
        }

        // Read cached row count from last run (stored in a simple file cache)
        $cacheFile  = BP . '/var/ticpan_js_errors_count.cache';
        $lastCount  = file_exists($cacheFile) ? (int) file_get_contents($cacheFile) : 0;
        $currentCount = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])
        );

        file_put_contents($cacheFile, (string) $currentCount);

        $delta = $currentCount - $lastCount;
        return $delta < 10000;
    }
}
