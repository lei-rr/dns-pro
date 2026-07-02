<?php

declare(strict_types=1);

namespace app\service\saas;

use app\repository\SaasPreferenceRepository;

/**
 * Hostname 本地附加属性服务（JSON-backed）
 *
 * Cloudflare custom_metadata 仅企业版可用，所以 hostname 的本地配置（preferred_domain / sync_target / sync_zone 等）
 * 存到 data/saas/preferences.json，结构：
 *   {"items": {"<cf_provider_id>:<hostname_id>": {"hostname": "app.example.com", "preferred_domain": "...", "sync_target": "...", "sync_provider_id": "...", "sync_zone": "...", "auto_preferred": false, "ownership_txt_cleaned": false}}}
 *
 * 通过 (cf_provider_id, hostname_id) 二元组定位；字段都为空时整条删除。
 */
class SaasPreferenceService
{
    private readonly SaasPreferenceRepository $store;

    public function __construct(?SaasPreferenceRepository $store = null)
    {
        $this->store = $store ?? new SaasPreferenceRepository();
    }

    /**
     * 读取单条 preference；不存在返回 null
     *
     * @return array{hostname:string,preferred_domain:string,sync_target:string,sync_provider_id:string,sync_zone:string,auto_preferred:bool,ownership_txt_cleaned:bool}|null
     */
    public function get(string $cloudflareProviderId, string $hostnameId): ?array
    {
        $items = $this->readItems();
        $key = $this->buildKey($cloudflareProviderId, $hostnameId);

        return isset($items[$key]) && is_array($items[$key]) ? $this->present($items[$key]) : null;
    }

    /**
     * 列出指定 cloudflare provider 下的所有 preference，返回 [hostname_id => preference]
     *
     * @return array<string, array{hostname:string,preferred_domain:string,sync_target:string,sync_provider_id:string,sync_zone:string,auto_preferred:bool,ownership_txt_cleaned:bool}>
     */
    public function listByProvider(string $cloudflareProviderId): array
    {
        $items = $this->readItems();
        $prefix = $cloudflareProviderId . ':';
        $prefixLen = strlen($prefix);

        $map = [];
        foreach ($items as $key => $value) {
            if (!is_string($key) || strncmp($key, $prefix, $prefixLen) !== 0 || !is_array($value)) {
                continue;
            }
            $hostnameId = substr($key, $prefixLen);
            if ($hostnameId !== '') {
                $map[$hostnameId] = $this->present($value);
            }
        }

        return $map;
    }

    /**
     * 写入 / 更新 preferred_domain；空字符串表示清空该字段
     */
    public function setPreferredDomain(string $cloudflareProviderId, string $hostnameId, string $preferredDomain): array
    {
        $preferred = trim($preferredDomain);

        return $this->save($cloudflareProviderId, $hostnameId, [
            'preferred_domain' => $preferred,
        ]);
    }

    public function setSyncConfig(string $cloudflareProviderId, string $hostnameId, string $syncTarget, string $syncProviderId, string $syncZone, bool $autoPreferred, string $hostname = ''): array
    {
        return $this->save($cloudflareProviderId, $hostnameId, [
            'hostname' => trim($hostname),
            'sync_target' => trim($syncTarget),
            'sync_provider_id' => trim($syncProviderId),
            'sync_zone' => strtolower(trim($syncZone)),
            'auto_preferred' => $autoPreferred,
        ]);
    }

    public function ownershipTxtCleaned(string $cloudflareProviderId, string $hostnameId): bool
    {
        return (bool) (($this->get($cloudflareProviderId, $hostnameId) ?? [])['ownership_txt_cleaned'] ?? false);
    }

    public function markOwnershipTxtCleaned(string $cloudflareProviderId, string $hostnameId, bool $cleaned, string $hostname = ''): array
    {
        return $this->save($cloudflareProviderId, $hostnameId, [
            'hostname' => trim($hostname),
            'ownership_txt_cleaned' => $cleaned,
        ]);
    }

    /**
     * 返回全部 preference，key 仍为 <cf_provider_id>:<hostname_id>
     *
     * @return array<string, array{hostname:string,preferred_domain:string,sync_target:string,sync_provider_id:string,sync_zone:string,auto_preferred:bool,ownership_txt_cleaned:bool}>
     */
    public function listAll(): array
    {
        $items = [];
        foreach ($this->readItems() as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $items[$key] = $this->present($value);
        }

        return $items;
    }

    /**
     * 删除指定 hostname 的 preference（hostname 删除时同步清理）
     */
    public function clear(string $cloudflareProviderId, string $hostnameId): void
    {
        $key = $this->buildKey($cloudflareProviderId, $hostnameId);

        $this->store->transaction(function (array $current) use ($key): array {
            $items = is_array($current['items'] ?? null) ? $current['items'] : [];
            unset($items[$key]);
            return ['items' => $items];
        });
    }

    // ---------- internal ----------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readItems(): array
    {
        $items = $this->store->read()['items'] ?? [];
        return is_array($items) ? $items : [];
    }

    private function buildKey(string $cloudflareProviderId, string $hostnameId): string
    {
        return $cloudflareProviderId . ':' . $hostnameId;
    }

    /**
     * @param array{hostname?:string,preferred_domain?:string,sync_target?:string,sync_provider_id?:string,sync_zone?:string,auto_preferred?:bool,ownership_txt_cleaned?:bool} $changes
     * @return array{hostname:string,preferred_domain:string,sync_target:string,sync_provider_id:string,sync_zone:string,auto_preferred:bool,ownership_txt_cleaned:bool}
     */
    private function save(string $cloudflareProviderId, string $hostnameId, array $changes): array
    {
        $key = $this->buildKey($cloudflareProviderId, $hostnameId);
        $saved = [];

        $this->store->transaction(function (array $current) use ($key, $changes, &$saved): array {
            $items = is_array($current['items'] ?? null) ? $current['items'] : [];
            $row = isset($items[$key]) && is_array($items[$key]) ? $items[$key] : [];
            $normalized = $this->present($row);

            foreach (['hostname', 'preferred_domain', 'sync_target', 'sync_provider_id', 'sync_zone'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $normalized[$field] = (string) $changes[$field];
                }
            }
            if (array_key_exists('auto_preferred', $changes)) {
                $normalized['auto_preferred'] = (bool) $changes['auto_preferred'];
            }
            if (array_key_exists('ownership_txt_cleaned', $changes)) {
                $normalized['ownership_txt_cleaned'] = (bool) $changes['ownership_txt_cleaned'];
            }

            $saved = $normalized;

            if ($normalized['preferred_domain'] === '' && $normalized['sync_target'] === '' && $normalized['sync_provider_id'] === '' && $normalized['sync_zone'] === '' && $normalized['auto_preferred'] === false && $normalized['ownership_txt_cleaned'] === false) {
                unset($items[$key]);
            } else {
                $items[$key] = $normalized;
            }

            return ['items' => $items];
        });

        return $saved;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function present(array $row): array
    {
        return [
            'hostname' => (string) ($row['hostname'] ?? ''),
            'preferred_domain' => (string) ($row['preferred_domain'] ?? ''),
            'sync_target' => (string) ($row['sync_target'] ?? ''),
            'sync_provider_id' => (string) ($row['sync_provider_id'] ?? ''),
            'sync_zone' => (string) ($row['sync_zone'] ?? ''),
            'auto_preferred' => (bool) ($row['auto_preferred'] ?? false),
            'ownership_txt_cleaned' => (bool) ($row['ownership_txt_cleaned'] ?? false),
        ];
    }
}
