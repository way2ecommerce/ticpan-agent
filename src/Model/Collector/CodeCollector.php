<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;

class CodeCollector implements CollectorInterface
{
    public function __construct(private readonly DirectoryList $directoryList) {}

    public function collect(): array
    {
        return [
            'composer_lock_exists'    => file_exists(BP . '/composer.lock'),
            'composer_lock_hash'      => $this->getComposerLockHash(),
            'exception_count_7d'      => $this->countExceptions7d(),
            'exception_threshold'     => 100,
            'js_critical_errors_count' => 0, // populated by JS error collector if installed
            'phpcs_psr12_percent_compliant'    => $this->runPhpcs('PSR12'),
            'phpcs_magento2_percent_compliant' => $this->runPhpcs('Magento2'),
            'phpcs_from_cache'        => false,
        ];
    }

    private function getComposerLockHash(): ?string
    {
        $lockFile = BP . '/composer.lock';
        if (! file_exists($lockFile)) {
            return null;
        }
        $lock = json_decode(file_get_contents($lockFile), true);
        return $lock['content-hash'] ?? md5_file($lockFile);
    }

    private function countExceptions7d(): int
    {
        $logFile = BP . '/var/log/exception.log';
        if (! file_exists($logFile)) {
            return 0;
        }

        $sevenDaysAgo = strtotime('-7 days');
        $count        = 0;
        $handle       = fopen($logFile, 'r');

        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                // Lines start with [YYYY-MM-DD
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $m)) {
                    if (strtotime($m[1]) >= $sevenDaysAgo) {
                        $count++;
                    }
                }
            }
            fclose($handle);
        }

        return $count;
    }

    private function runPhpcs(string $standard): ?float
    {
        $appCodeDir = BP . '/app/code/';
        if (! is_dir($appCodeDir) || count(glob($appCodeDir . '*') ?: []) === 0) {
            return null; // no custom code
        }

        // Check weekly cache
        $cacheFile = BP . "/var/ticpan_phpcs_{$standard}.cache";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            return (float) file_get_contents($cacheFile);
        }

        $phpcs = BP . '/vendor/bin/phpcs';
        if (! file_exists($phpcs)) {
            return null;
        }

        $cmd    = escapeshellcmd("{$phpcs} --standard={$standard} --report=json --no-colors " . escapeshellarg($appCodeDir) . " 2>/dev/null");
        $output = shell_exec($cmd);
        if (! $output) {
            return null;
        }

        $result = json_decode($output, true);
        $total  = $result['totals']['files'] ?? 0;
        if ($total === 0) {
            return 100.0;
        }

        $filesWithErrors = count(array_filter($result['files'] ?? [], fn ($f) => $f['errors'] > 0 || $f['warnings'] > 0));
        $compliantPct    = round((($total - $filesWithErrors) / $total) * 100, 1);

        file_put_contents($cacheFile, (string) $compliantPct);

        return $compliantPct;
    }
}
