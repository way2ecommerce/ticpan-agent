<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;

class CodeCollector implements CollectorInterface
{
    /** @var DirectoryList */
    private $directoryList;
    /** @var ResourceConnection */
    private $resource;

    public function __construct(
        DirectoryList $directoryList,
        ResourceConnection $resource
    ) {
        $this->directoryList = $directoryList;
        $this->resource      = $resource;
    }

    private const CORE_FILE_SAMPLES = [
        'vendor/magento/framework/App/Bootstrap.php',
        'vendor/magento/framework/App/FrontController.php',
        'vendor/magento/framework/App/Http.php',
        'vendor/magento/framework/HTTP/PhpEnvironment/Request.php',
        'vendor/magento/framework/Encryption/Encryptor.php',
        'vendor/magento/module-backend/App/Action/Plugin/Authentication.php',
        'vendor/magento/module-catalog/Model/Product.php',
        'vendor/magento/module-catalog/Model/ResourceModel/Product.php',
        'vendor/magento/module-checkout/Model/Session.php',
        'vendor/magento/module-customer/Model/Customer.php',
        'vendor/magento/module-quote/Model/Quote.php',
        'vendor/magento/module-sales/Model/Order.php',
        'vendor/magento/module-store/Model/Store.php',
        'vendor/magento/module-user/Model/User.php',
        'vendor/magento/module-payment/Model/Method/AbstractMethod.php',
    ];

    public function collect(): array
    {
        $exceptionData = $this->analyzeExceptionLog();
        $psr12Data     = $this->runPhpcs('PSR12');
        $magento2Data  = $this->runPhpcs('Magento2');

        return [
            'composer_lock_exists'    => file_exists(BP . '/composer.lock'),
            'composer_lock_hash'      => $this->getComposerLockHash(),
            'composer_packages'       => $this->getComposerPackages(),
            'core_file_hashes'        => $this->getCoreFileHashes(),
            'js_errors_7d'            => $this->getJsErrors7d(),
            'js_errors_total_count'   => $this->getJsErrorsTotalCount(),

            // CODE-05
            'exception_count_7d'              => $exceptionData['count'],
            'exception_threshold'             => 100,
            'exception_log_readable'          => $exceptionData['readable'],
            'exception_log_size_bytes'        => $exceptionData['size_bytes'],
            'exception_log_top_types'         => $exceptionData['top_types'],
            'exception_log_sample_traces'     => $exceptionData['sample_traces'],

            // CODE-07
            'phpcs_psr12_percent_compliant'  => $psr12Data['percent'],
            'phpcs_psr12_total_files'        => $psr12Data['total_files'],
            'phpcs_psr12_compliant_files'    => $psr12Data['compliant_files'],
            'phpcs_psr12_top_violations'     => $psr12Data['top_violations'],
            'phpcs_psr12_sample_violations'  => $psr12Data['sample_violations'],

            // CODE-08
            'phpcs_magento2_percent_compliant'  => $magento2Data['percent'],
            'phpcs_magento2_total_files'        => $magento2Data['total_files'],
            'phpcs_magento2_compliant_files'    => $magento2Data['compliant_files'],
            'phpcs_magento2_top_violations'     => $magento2Data['top_violations'],
            'phpcs_magento2_sample_violations'  => $magento2Data['sample_violations'],
            'phpcs_magento2_ruleset_available'  => $magento2Data['ruleset_available'],

            'phpcs_from_cache' => $psr12Data['from_cache'] || $magento2Data['from_cache'],
        ];
    }

    // -------------------------------------------------------------------------
    // CODE-05 — Exception log
    // -------------------------------------------------------------------------

    private function analyzeExceptionLog(): array
    {
        $empty = [
            'count'         => 0,
            'readable'      => false,
            'size_bytes'    => 0,
            'top_types'     => [],
            'sample_traces' => [],
        ];

        $logFile = BP . '/var/log/exception.log';
        if (! file_exists($logFile) || ! is_readable($logFile)) {
            return $empty;
        }

        $sizeBytes = filesize($logFile);

        // Daily cache for top_types and sample_traces (expensive to compute)
        $cacheFile = BP . '/var/ticpan_exceptions.cache';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_merge($cached, ['size_bytes' => $sizeBytes]);
            }
        }

        $sevenDaysAgo = strtotime('-7 days');
        $typeCounts   = [];
        $sampleTraces = [];
        $seenClasses  = [];

        $handle = fopen($logFile, 'r');
        if (! $handle) {
            return array_merge($empty, ['size_bytes' => $sizeBytes]);
        }

        $currentEntry = '';
        $currentDate  = null;

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/', $line, $m)) {
                if ($currentEntry !== '' && $currentDate >= $sevenDaysAgo) {
                    $this->processEntry($currentEntry, $typeCounts, $sampleTraces, $seenClasses);
                }
                $currentEntry = $line;
                $currentDate  = strtotime($m[1]);
            } else {
                $currentEntry .= $line;
            }
        }
        if ($currentEntry !== '' && $currentDate >= $sevenDaysAgo) {
            $this->processEntry($currentEntry, $typeCounts, $sampleTraces, $seenClasses);
        }
        fclose($handle);

        arsort($typeCounts);
        $topTypes = [];
        foreach (array_slice($typeCounts, 0, 10, true) as $class => $data) {
            $topTypes[] = [
                'exception_class' => $class,
                'count'           => $data['count'],
                'sample_message'  => $data['sample_message'],
            ];
        }

        $result = [
            'count'         => array_sum(array_column($typeCounts, 'count')),
            'readable'      => true,
            'size_bytes'    => $sizeBytes,
            'top_types'     => $topTypes,
            'sample_traces' => array_slice($sampleTraces, 0, 5),
        ];

        // Cache for 24h (size_bytes excluded — always fresh)
        $toCache = $result;
        unset($toCache['size_bytes']);
        file_put_contents($cacheFile, json_encode($toCache));

        return $result;
    }

    private function processEntry(string $entry, array &$typeCounts, array &$sampleTraces, array &$seenClasses): void
    {
        $class = $this->extractExceptionClass($entry);
        if (! $class) return;

        if (! isset($typeCounts[$class])) {
            $typeCounts[$class] = ['count' => 0, 'sample_message' => $this->extractMessage($entry)];
        }
        $typeCounts[$class]['count']++;

        // Keep one sample trace per unique class (up to 5 total)
        if (! isset($seenClasses[$class]) && count($sampleTraces) < 5) {
            $seenClasses[$class] = true;
            // Truncate to 3000 chars to avoid huge payloads
            $sampleTraces[] = mb_substr($entry, 0, 3000);
        }
    }

    private function extractExceptionClass(string $entry): ?string
    {
        // Format: [object] (Full\Class\Name(code: ...) or [object] (Full\Class\Name: ...)
        if (preg_match('/\[object\]\s*\(([\\\\a-zA-Z0-9_]+)/', $entry, $m)) {
            return $m[1];
        }
        // Format: Full\Class\Name: message in /path
        if (preg_match('/([A-Z][a-zA-Z0-9_\\\\]+Exception[a-zA-Z0-9_]*)/', $entry, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractMessage(string $entry): string
    {
        // Get first line, strip timestamp, truncate
        $firstLine = explode("\n", $entry)[0] ?? $entry;
        $firstLine = preg_replace('/^\[\d{4}-\d{2}-\d{2}[T ][^\]]+\]\s*\S+:\s*/', '', $firstLine);
        return mb_substr(trim($firstLine), 0, 200);
    }

    // -------------------------------------------------------------------------
    // CODE-07 / CODE-08 — PHP_CodeSniffer
    // -------------------------------------------------------------------------

    private function runPhpcs(string $standard): array
    {
        $empty = [
            'percent'           => null,
            'total_files'       => 0,
            'compliant_files'   => 0,
            'top_violations'    => [],
            'sample_violations' => [],
            'ruleset_available' => true,
            'from_cache'        => false,
        ];

        $appCodeDir = BP . '/app/code/';
        if (! is_dir($appCodeDir) || count(glob($appCodeDir . '*') ?: []) === 0) {
            return $empty;
        }

        $phpcs = BP . '/vendor/bin/phpcs';
        if (! file_exists($phpcs)) {
            return $empty;
        }

        // Check Magento2 ruleset availability
        if ($standard === 'Magento2') {
            $check = shell_exec(PHP_BINARY . " {$phpcs} -i 2>/dev/null");
            if (! $check || stripos($check, 'magento') === false) {
                return array_merge($empty, ['ruleset_available' => false]);
            }
        }

        // Weekly cache — skip if cached percent is null (stale data from old code version)
        $cacheFile = BP . "/var/ticpan_phpcs_{$standard}.cache";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && $cached['percent'] !== null) {
                return array_merge($cached, ['from_cache' => true]);
            }
        }

        $cmd    = PHP_BINARY . " " . escapeshellarg($phpcs) . " --standard={$standard} --report=json --no-colors " . escapeshellarg($appCodeDir) . " 2>/dev/null";
        $output = shell_exec($cmd);
        if (! $output) {
            return $empty;
        }

        $result = json_decode($output, true);
        if (! $result) {
            return $empty;
        }

        $files      = $result['files'] ?? [];
        $totalFiles = count($files);
        if ($totalFiles === 0) {
            // All files passed — no violations in JSON output. Cache and return 100%.
            $allClear = array_merge($empty, ['percent' => 100.0]);
            file_put_contents($cacheFile, json_encode($allClear));
            return $allClear;
        }

        $compliantFiles  = 0;
        $violationCounts = [];   // sniff => count
        $sampleViolations = [];  // up to 20

        foreach ($files as $filePath => $fileData) {
            $messages = $fileData['messages'] ?? [];
            if (empty($messages)) {
                $compliantFiles++;
                continue;
            }
            foreach ($messages as $msg) {
                $sniff = $msg['source'] ?? 'Unknown';
                $violationCounts[$sniff] = ($violationCounts[$sniff] ?? 0) + 1;

                if (count($sampleViolations) < 20) {
                    $sampleViolations[] = [
                        'file'    => str_replace(BP . '/app/code/', '', $filePath),
                        'line'    => $msg['line'] ?? 0,
                        'rule'    => $sniff,
                        'message' => mb_substr($msg['message'] ?? '', 0, 150),
                    ];
                }
            }
        }

        // Top 10 violations
        arsort($violationCounts);
        $topViolations = [];
        foreach (array_slice($violationCounts, 0, 10, true) as $sniff => $count) {
            $topViolations[] = ['rule' => $sniff, 'count' => $count];
        }

        $percent = round(($compliantFiles / $totalFiles) * 100, 1);

        $data = [
            'percent'           => $percent,
            'total_files'       => $totalFiles,
            'compliant_files'   => $compliantFiles,
            'top_violations'    => $topViolations,
            'sample_violations' => $sampleViolations,
            'ruleset_available' => true,
            'from_cache'        => false,
        ];

        file_put_contents($cacheFile, json_encode($data));

        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getComposerLockHash(): ?string
    {
        $lockFile = BP . '/composer.lock';
        if (! file_exists($lockFile)) return null;
        $lock = json_decode(file_get_contents($lockFile), true);
        return $lock['content-hash'] ?? md5_file($lockFile);
    }

    private function getCoreFileHashes(): array
    {
        $hashes = [];
        foreach (self::CORE_FILE_SAMPLES as $relPath) {
            $absPath = BP . '/' . $relPath;
            if (is_readable($absPath)) {
                $hashes[$relPath] = hash('sha256', file_get_contents($absPath));
            }
        }
        return $hashes;
    }

    private function getJsErrors7d(): array
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName('w2e_ticpan_js_errors');

            if (! $connection->isTableExists($table)) {
                return [];
            }

            $since = date('Y-m-d H:i:s', strtotime('-7 days'));
            $rows  = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['message', 'count' => new \Zend_Db_Expr('COUNT(*)')])
                    ->where('created_at >= ?', $since)
                    ->group('message')
                    ->order('count DESC')
                    ->limit(50)
            );

            return array_map(fn ($r) => [
                'message' => mb_substr($r['message'], 0, 300),
                'count'   => (int) $r['count'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getJsErrorsTotalCount(): int
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName('w2e_ticpan_js_errors');

            if (! $connection->isTableExists($table)) {
                return 0;
            }

            $since = date('Y-m-d H:i:s', strtotime('-7 days'));
            return (int) $connection->fetchOne(
                $connection->select()
                    ->from($table, ['COUNT(*)'])
                    ->where('created_at >= ?', $since)
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getComposerPackages(): array
    {
        $lockFile = BP . '/composer.lock';
        if (! file_exists($lockFile)) return [];

        $lock = json_decode(file_get_contents($lockFile), true);
        if (! is_array($lock)) return [];

        $result = [];
        $allPkgs = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        foreach ($allPkgs as $pkg) {
            $name = $pkg['name'] ?? '';
            if (! $name) continue;

            $abandoned = $pkg['abandoned'] ?? false;

            $result[] = [
                'name'      => $name,
                'version'   => $pkg['version'] ?? '',
                'time'      => $pkg['time'] ?? null,
                'abandoned' => $abandoned === true ? true : (is_string($abandoned) ? $abandoned : false),
                'type'      => $pkg['type'] ?? 'library',
            ];
        }

        return $result;
    }
}
