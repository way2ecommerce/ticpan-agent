<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class SecCollector implements CollectorInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource
    ) {}

    public function collect(): array
    {
        return [
            'admin_url_custom'      => $this->isAdminUrlCustom(),
            'tfa_all_admins'        => $this->isTfaAllAdmins(),
            'admins_without_tfa'    => $this->getAdminsWithoutTfa(),
            'password_policy_active' => $this->isPasswordPolicyActive(),
            'captcha_admin_enabled' => $this->isCaptchaAdminEnabled(),
            'inactive_admin_count'  => $this->getInactiveAdminCount(),
            'file_permissions_ok'   => $this->checkFilePermissions(),
            'wrong_permission_paths' => $this->getWrongPermissionPaths(),
        ];
    }

    private function isAdminUrlCustom(): bool
    {
        $frontName = $this->scopeConfig->getValue('admin/url/custom_path')
            ?? $this->scopeConfig->getValue('admin/url/use_custom_path');
        // Default Magento admin path is 'admin'
        $path = $this->scopeConfig->getValue('admin/url/custom_path') ?? 'admin';
        return $path !== 'admin' && $path !== '';
    }

    private function isTfaAllAdmins(): bool
    {
        return count($this->getAdminsWithoutTfa()) === 0;
    }

    private function getAdminsWithoutTfa(): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('admin_user');

        // Check if TFA table exists
        $tfaTable = $this->resource->getTableName('tfa_user_config');
        if (! $connection->isTableExists($tfaTable)) {
            return []; // TFA module not installed
        }

        $select = $connection->select()
            ->from(['u' => $table], ['username'])
            ->joinLeft(['t' => $tfaTable], 'u.user_id = t.user_id', [])
            ->where('u.is_active = ?', 1)
            ->where('t.user_id IS NULL');

        return $connection->fetchCol($select);
    }

    private function isPasswordPolicyActive(): bool
    {
        $minLength = (int) $this->scopeConfig->getValue('admin/security/password_is_forced');
        return (bool) $this->scopeConfig->getValue('admin/security/password_is_forced')
            || (int) $this->scopeConfig->getValue('admin/security/min_admin_password_length') >= 8;
    }

    private function isCaptchaAdminEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('admin/captcha/enable');
    }

    private function getInactiveAdminCount(): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('admin_user');

        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));

        $select = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('is_active = ?', 1)
            ->where('logdate < ?', $ninetyDaysAgo)
            ->orWhere('logdate IS NULL AND created < ?', $ninetyDaysAgo);

        return (int) $connection->fetchOne($select);
    }

    private function checkFilePermissions(): bool
    {
        return count($this->getWrongPermissionPaths()) === 0;
    }

    private function getWrongPermissionPaths(): array
    {
        $wrong = [];
        $checks = [
            BP . '/app/etc/env.php'     => 0640,
            BP . '/app/etc/config.php'  => 0640,
        ];
        foreach ($checks as $path => $expected) {
            if (file_exists($path)) {
                $actual = fileperms($path) & 0777;
                if ($actual > $expected) {
                    $wrong[] = [
                        'path'     => str_replace(BP . '/', '', $path),
                        'actual'   => decoct($actual),
                        'expected' => decoct($expected),
                    ];
                }
            }
        }
        return $wrong;
    }
}
