<?php

declare(strict_types=1);

namespace app\service\hostname;

use app\exception\ApiException;
use app\repository\PreferredDomainRepository;

/**
 * 优选域名服务（JSON-backed）
 *
 * 单一数据源：data/hostname/preferred-domains.json，结构 {"items": ["domain", ...]}。
 * 数组顺序即显示顺序；domain 全局唯一并作为对外标识。
 */
class PreferredDomainService
{
    private readonly PreferredDomainRepository $store;

    public function __construct(?PreferredDomainRepository $store = null)
    {
        $this->store = $store ?? new PreferredDomainRepository();
    }

    /**
     * @return array<int, array{domain:string, sort_order:int}>
     */
    public function list(): array
    {
        return $this->present($this->readDomains());
    }

    public function create(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);

        $this->store->transaction(function (array $current) use ($domain): array {
            $items = $this->normalizeItems($current['items'] ?? []);
            if (in_array($domain, $items, true)) {
                throw new ApiException('Preferred domain already exists', 422, 'preferred_domain_duplicate', ['domain' => $domain]);
            }
            $items[] = $domain;
            return ['items' => $items];
        });

        return ['domain' => $domain, 'sort_order' => $this->indexOf($domain)];
    }

    /**
     * 重命名：把 $oldDomain 在原位替换为 $newDomain
     */
    public function rename(string $oldDomain, string $newDomain): array
    {
        $newDomain = $this->normalizeDomain($newDomain);

        $this->store->transaction(function (array $current) use ($oldDomain, $newDomain): array {
            $items = $this->normalizeItems($current['items'] ?? []);
            $index = $this->requireIndex($oldDomain, $items);

            if ($items[$index] !== $newDomain && in_array($newDomain, $items, true)) {
                throw new ApiException('Preferred domain already exists', 422, 'preferred_domain_duplicate', ['domain' => $newDomain]);
            }

            $items[$index] = $newDomain;
            return ['items' => $items];
        });

        return ['domain' => $newDomain, 'sort_order' => $this->indexOf($newDomain)];
    }

    public function delete(string $domain): void
    {
        $this->store->transaction(function (array $current) use ($domain): array {
            $items = $this->normalizeItems($current['items'] ?? []);
            $index = $this->requireIndex($domain, $items);
            array_splice($items, $index, 1);
            return ['items' => $items];
        });
    }

    /**
     * 按给定 domain 顺序重排；未列出的现存项追加到末尾保持原序
     *
     * @param string[] $domains
     * @return array<int, array{domain:string, sort_order:int}>
     */
    public function reorder(array $domains): array
    {
        $this->store->transaction(function (array $current) use ($domains): array {
            $items = $this->normalizeItems($current['items'] ?? []);
            $seen = [];
            $ordered = [];

            foreach ($domains as $value) {
                $domain = trim((string) $value);
                if ($domain === '' || isset($seen[$domain])) {
                    continue;
                }
                if (in_array($domain, $items, true)) {
                    $ordered[] = $domain;
                    $seen[$domain] = true;
                }
            }

            foreach ($items as $domain) {
                if (!isset($seen[$domain])) {
                    $ordered[] = $domain;
                }
            }

            return ['items' => $ordered];
        });

        return $this->list();
    }

    /**
     * 校验 domain 是否在当前列表中（HostnameService 创建/更新时用作白名单）
     */
    public function isAllowed(string $domain): bool
    {
        $domain = trim($domain);
        if ($domain === '') {
            return false;
        }

        return in_array($domain, $this->readDomains(), true);
    }

    // ---------- internal ----------

    /**
     * @return string[]
     */
    private function readDomains(): array
    {
        return $this->normalizeItems($this->store->read()['items'] ?? []);
    }

    /**
     * 把 items 输入归一为 string[] 并去重保持原序
     *
     * @return string[]
     */
    private function normalizeItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($items as $value) {
            $domain = trim((string) $value);
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $normalized[] = $domain;
        }

        return $normalized;
    }

    private function normalizeDomain(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = preg_replace('#^[a-z]+://#', '', $value) ?? $value;
        $value = rtrim(explode('/', $value, 2)[0], '.');

        if ($value === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value)) {
            throw new ApiException('Invalid domain format', 422, 'preferred_domain_invalid', ['domain' => $domain]);
        }

        return $value;
    }

    /**
     * @param string[] $items
     */
    private function requireIndex(string $domain, array $items): int
    {
        $index = array_search($domain, $items, true);
        if ($index === false) {
            throw new ApiException('Preferred domain not found', 404, 'preferred_domain_not_found', ['domain' => $domain]);
        }

        return $index;
    }

    private function indexOf(string $domain): int
    {
        $index = array_search($domain, $this->readDomains(), true);
        return $index === false ? 0 : $index;
    }

    /**
     * @param string[] $items
     * @return array<int, array{domain:string, sort_order:int}>
     */
    private function present(array $items): array
    {
        return array_map(static fn (string $d, int $i) => ['domain' => $d, 'sort_order' => $i], $items, array_keys($items));
    }
}
