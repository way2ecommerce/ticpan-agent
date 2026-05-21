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
            'admin_security_config'  => $this->getAdminSecurityConfig(),
            'captcha_admin_enabled'   => $this->isCaptchaAdminEnabled(),
            'recaptcha_admin_enabled' => $this->isRecaptchaAdminEnabled(),
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

        // If TFA table doesn't exist the module is disabled — treat ALL admins as unconfigured
        $tfaTable = $this->resource->getTableName('tfa_user_config');
        if (! $connection->isTableExists($tfaTable)) {
            return $connection->fetchCol(
                $connection->select()->from($table, ['username'])->where('is_active = ?', 1)
            );
        }

        $select = $connection->select()
            ->from(['u' => $table], ['username'])
            ->joinLeft(['t' => $tfaTable], 'u.user_id = t.user_id', [])
            ->where('u.is_active = ?', 1)
            ->where('t.user_id IS NULL');

        return $connection->fetchCol($select);
    }

    private function getAdminSecurityConfig(): array
    {
        $get = fn (string $path) => $this->scopeConfig->getValue($path);

        return [
            'min_admin_password_length' => (int)   ($get('admin/security/min_admin_password_length') ?? 7),
            'password_is_forced'        => (bool)   $get('admin/security/password_is_forced'),
            'password_lifetime'         => (int)   ($get('admin/security/password_lifetime') ?? 0),
            'admin_account_sharing'     => (int)   ($get('admin/security/admin_account_sharing') ?? 1),
            'lockout_failures'          => (int)   ($get('admin/security/lockout_failures') ?? 6),
            'lockout_threshold'         => (int)   ($get('admin/security/lockout_threshold') ?? 30),
        ];
    }

    private function isCaptchaAdminEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('admin/captcha/enable');
    }

    private function isRecaptchaAdminEnabled(): bool
    {
        $type = $this->scopeConfig->getValue('recaptcha_backend/type_for/user_login');
        return ! empty($type);
    }

    private function getInactiveAdminCount(): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('admin_user');

        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));

        $quoted = $connection->quote($ninetyDaysAgo);
        $select = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('is_active = ?', 1)
            ->where("(logdate < {$quoted} OR (logdate IS NULL AND created < {$quoted}))");

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
