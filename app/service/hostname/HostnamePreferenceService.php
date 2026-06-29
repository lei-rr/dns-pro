<?php

declare(strict_types=1);

namespace app\service\hostname;

use app\repository\HostnamePreferenceRepository;

/**
 * Hostname 本地附加属性服务（JSON-backed）
 *
 * Cloudflare custom_metadata 仅企业版可用，所以 hostname 的本地配置（preferred_domain 等）
 * 存到 data/hostname/preferences.json，结构：
 *   {"items": {"<cf_provider_id>:<hostname_id>": {"preferred_domain": "..."}}}
 *
 * 通过 (cf_provider_id, hostname_id) 二元组定位；preferred_domain 为空字符串时整条删除。
 */
class HostnamePreferenceService
{
    private readonly HostnamePreferenceRepository $store;

    public function __construct(?HostnamePreferenceRepository $store = null)
    {
        $this->store = $store ?? new HostnamePreferenceRepository();
    }

    /**
     * 读取单条 preference；不存在返回 null
     *
     * @return array{preferred_domain:string}|null
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
     * @return array<string, array{preferred_domain:string}>
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
     * 写入 / 更新 preferred_domain；空字符串表示清空该条 preference
     */
    public function setPreferredDomain(string $cloudflareProviderId, string $hostnameId, string $preferredDomain): array
    {
        $key = $this->buildKey($cloudflareProviderId, $hostnameId);
        $preferred = trim($preferredDomain);

        $this->store->transaction(function (array $current) use ($key, $preferred): array {
            $items = is_array($current['items'] ?? null) ? $current['items'] : [];

            if ($preferred === '') {
                unset($items[$key]);
            } else {
                $items[$key] = ['preferred_domain' => $preferred];
            }

            return ['items' => $items];
        });

        return ['preferred_domain' => $preferred];
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
     * @param array<string, mixed> $row
     */
    private function present(array $row): array
    {
        return [
            'preferred_domain' => (string) ($row['preferred_domain'] ?? ''),
        ];
    }
}
