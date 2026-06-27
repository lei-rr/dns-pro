<?php

declare(strict_types=1);

namespace app\service\concerns;

use think\facade\Cache;

/**
 * Provider Service 通用缓存能力
 *
 * 抽取 dnspod / cloudflare / edgeone / hostname 子 service 共享的缓存样板：
 *   - 缓存 key 用稳定可读格式（便于调试，命中规则一目了然）
 *   - 缓存 tag 按 <source>:<entity>:<providerId> 拼，与 ProviderRepository 失效逻辑保持一致
 *
 * 分页归一参见 PaginationMeta trait（按需混入）。
 */
trait ProviderServiceConcern
{
    protected function cacheTtl(): int
    {
        return (int) config('services.cache_ttl');
    }

    /**
     * 生成可读 cache key：
     *   prefix:k1=v1:k2=v2:...
     *
     * - 入参 ksort 保证同语义稳定
     * - 值统一 string 化；空字符串/null 用 "-" 占位
     * - 含 ":"或空格的值做 sanitize 防止解析歧义
     */
    protected function buildCacheKey(string $prefix, array $parts): string
    {
        ksort($parts);

        $segments = [$prefix];
        foreach ($parts as $key => $value) {
            $segments[] = $this->sanitizeKeySegment((string) $key) . '=' . $this->sanitizeKeySegment($this->stringifyValue($value));
        }

        return implode(':', $segments);
    }

    /**
     * 生成 cache tag：以 ":" 拼接，空段过滤
     */
    protected function buildCacheTag(string ...$segments): string
    {
        return implode(':', array_filter($segments, static fn ($s) => $s !== ''));
    }

    protected function providerCacheTag(string $providerId): string
    {
        return $this->buildCacheTag('provider', $providerId);
    }

    protected function getCached(string $cacheKey, bool $refresh): ?array
    {
        if ($refresh) {
            return null;
        }

        $cached = Cache::get($cacheKey);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param string|array $tag 缓存标签
     */
    protected function setCached(string $cacheKey, array $data, string|array $tag): void
    {
        Cache::tag($tag)->set($cacheKey, $data, $this->cacheTtl());
    }

    protected function invalidateCache(string ...$tags): void
    {
        foreach ($tags as $tag) {
            if ($tag !== '') {
                Cache::tag($tag)->clear();
            }
        }
    }

    /**
     * 把任意标量/数组转成稳定的字符串表达
     */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            ksort($value);
            return md5(json_encode($value, JSON_UNESCAPED_UNICODE));
        }
        return (string) $value;
    }

    /**
     * 防止 key 段内出现 ":" 空格等导致歧义
     */
    private function sanitizeKeySegment(string $segment): string
    {
        return strtr($segment, [':' => '_', ' ' => '_']);
    }
}
