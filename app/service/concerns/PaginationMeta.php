<?php

declare(strict_types=1);

namespace app\service\concerns;

/**
 * REST 分页元数据归一
 *
 * 不同上游 API 用不同的分页风格：
 *   - DNSPod/EdgeOne 使用 offset/limit
 *   - Cloudflare 使用 page/per_page
 *
 * 本 trait 把两种风格都归一到统一 meta：
 *   { page, per_page, offset, limit, count, total, total_pages }
 *
 * 让前端不用关心后端调的是哪种 API。
 */
trait PaginationMeta
{
    /**
     * 把 {offset, limit, total, count} 归一为完整 meta
     */
    protected function offsetPaginationMeta(array $pagination, int $defaultLimit): array
    {
        $offset = (int) ($pagination['offset'] ?? 0);
        $limit = (int) ($pagination['limit'] ?? $defaultLimit);
        $total = $pagination['total'] ?? null;

        return [
            'page' => $limit > 0 ? (int) floor($offset / $limit) + 1 : 1,
            'per_page' => $limit,
            'offset' => $offset,
            'limit' => $limit,
            'count' => $pagination['count'] ?? null,
            'total' => $total,
            'total_pages' => $total !== null && $limit > 0 ? (int) ceil((int) $total / $limit) : null,
        ];
    }

    /**
     * 把 {page, per_page, total_count|total, count, total_pages} 归一为完整 meta
     */
    protected function pagePaginationMeta(array $pagination, int $defaultPerPage): array
    {
        $page = (int) ($pagination['page'] ?? 1);
        $perPage = (int) ($pagination['per_page'] ?? $defaultPerPage);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => max(0, ($page - 1) * $perPage),
            'limit' => $perPage,
            'count' => $pagination['count'] ?? null,
            'total' => $pagination['total_count'] ?? $pagination['total'] ?? null,
            'total_pages' => $pagination['total_pages'] ?? null,
        ];
    }
}
