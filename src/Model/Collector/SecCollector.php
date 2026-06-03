<?php

namespace W2e\Ticpan\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;

class SecCollector implements CollectorInterface
{
    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var ResourceConnection */
    private $resource;
    /** @var DeploymentConfig */
    private $deploymentConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig
    ) {
        $this->scopeConfig      = $scopeConfig;
        $this->resource         = $resource;
        $this->deploymentConfig = $deploymentConfig;
    }

    // Known third-party 2FA module names as they appear in setup_module
    private const THIRD_PARTY_TFA_MODULES = [
        'Amasty_TwoFactorAuth',
        'Magezon_TwoFactorAuth',
        'Mageplaza_TwoFactorAuth',
    ];

    public function collect(): array
    {
        $inactiveAdmins  = $this->getInactiveAdmins();
        $tfaModuleType   = $this->getTfaModuleType();
        $thirdPartyMods  = $tfaModuleType === 'third_party' ? $this->getThirdPartyTfaModules() : [];

        return [
            'admin_url_custom'           => $this->isAdminUrlCustom(),
            'use_secure_urls_in_admin'   => (bool) $this->scopeConfig->getValue('web/secure/use_in_adminhtml'),
            'tfa_module_type'            => $tfaModuleType,
            'tfa_third_party_modules'    => $thirdPartyMods,
            'tfa_all_admins'             => $tfaModuleType === 'native' ? $this->isTfaAllAdmins() : null,
            'admins_without_tfa'         => $tfaModuleType === 'native' ? $this->getAdminsWithoutTfa() : [],
            'admin_security_config'      => $this->getAdminSecurityConfig(),
            'captcha_admin_enabled'      => $this->isCaptchaAdminEnabled(),
            'recaptcha_admin_enabled'    => $this->isRecaptchaAdminEnabled(),
            'inactive_admin_count'       => count($inactiveAdmins),
            'inactive_admin_usernames'   => $inactiveAdmins,
            'file_permissions_ok'        => $this->checkFilePermissions(),
            'wrong_permission_paths'     => $this->getWrongPermissionPaths(),
        ];
    }

    private function getTfaModuleType(): string
    {
        $connection = $this->resource->getConnection();

        // Native Magento TFA: table tfa_user_config exists
        if ($connection->isTableExists($this->resource->getTableName('tfa_user_config'))) {
            return 'native';
        }

        // Check for known third-party 2FA modules in setup_module
        if (! empty($this->getThirdPartyTfaModules())) {
            return 'third_party';
        }

        return 'none';
    }

    private function getThirdPartyTfaModules(): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('setup_module');

        if (! $connection->isTableExists($table)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count(self::THIRD_PARTY_TFA_MODULES), '?'));
        $rows = $connection->fetchCol(
            "SELECT module FROM {$table} WHERE module IN ({$placeholders}) AND schema_version IS NOT NULL",
            self::THIRD_PARTY_TFA_MODULES
        );

        return $rows ?: [];
    }

    private function isAdminUrlCustom(): bool
    {
        // Priority 1: env.php via DeploymentConfig (the authoritative runtime source)
        $frontName = $this->deploymentConfig->get('backend/frontName');
        if ($frontName !== null) {
            return $frontName !== 'admin' && $frontName !== '';
        }

        // Fallback: ScopeConfig (set via admin UI with use_custom_path enabled)
        $useCustom = (bool) $this->scopeConfig->getValue('admin/url/use_custom_path');
        if (! $useCustom) {
            return false;
        }
        $path = $this->scopeConfig->getValue('admin/url/custom_path') ?? '';
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

    private function getInactiveAdmins(): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('admin_user');

        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime('-90 days'));

        $quoted = $connection->quote($ninetyDaysAgo);
        $select = $connection->select()
            ->from($table, ['username'])
            ->where('is_active = ?', 1)
            ->where("(logdate < {$quoted} OR (logdate IS NULL AND created < {$quoted}))");

        return $connection->fetchCol($select);
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
            // Resolve symlinks: fileperms() on a symlink returns the link's own permissions
            // (usually 0777), not the target's — which would always trigger a false positive.
            $resolved = is_link($path) ? (realpath($path) ?: null) : $path;
            if ($resolved === null || ! file_exists($resolved)) continue;

            $actual = fileperms($resolved) & 0777;
            if ($actual > $expected) {
                $wrong[] = [
                    'path'     => str_replace(BP . '/', '', $path),
                    'actual'   => decoct($actual),
                    'expected' => decoct($expected),
                ];
            }
        }
        return $wrong;
    }
}
