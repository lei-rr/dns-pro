<?php

declare(strict_types=1);

namespace app\support;

/**
 * 副作用结果构造器
 *
 * 统一编排类 service 对外暴露的副作用返回契约。
 */
class SideEffectResult
{
    public static function dns(array $operations): array
    {
        return ['side_effects' => ['dns' => $operations]];
    }

    public static function operation(string $status, string $message, array $details = []): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }
}
