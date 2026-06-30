<?php

declare(strict_types=1);

namespace app\repository;

use app\support\JsonStore;

/**
 * Hostname 偏好仓储
 *
 * 统一 data/saas/preferences.json 的本地持久化访问边界。
 */
class SaasPreferenceRepository
{
    private readonly JsonStore $store;

    public function __construct(?JsonStore $store = null)
    {
        if ($store !== null) {
            $this->store = $store;
            return;
        }

        $this->migrateLegacyFile('hostname/preferences.json', 'saas/preferences.json');
        $this->store = new JsonStore('saas/preferences.json', ['items' => []]);
    }

    public function read(): array
    {
        return $this->store->read();
    }

    public function transaction(callable $mutator): array
    {
        return $this->store->transaction($mutator);
    }

    private function migrateLegacyFile(string $legacyRelativePath, string $targetRelativePath): void
    {
        $dataRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
        $legacyPath = $dataRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $legacyRelativePath);
        $targetPath = $dataRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRelativePath);

        if (!is_file($legacyPath) || is_file($targetPath)) {
            return;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        @rename($legacyPath, $targetPath);
    }
}
