<?php

declare(strict_types=1);

namespace app\support;

use think\Response;

/**
 * 业务 API 响应封装
 *
 * 统一三种响应:
 *   - data():     正常返回 `{data: ...}` JSON
 *   - noContent(): 204 空响应(成功但无 body)
 *   - error():    错误返回 `{message, code, status, details}` JSON
 *
 * 所有响应自动加 `Cache-Control: no-store, must-revalidate`,
 * 防止浏览器/代理缓存任何含鉴权 cookie 的 API 数据。
 */
class ApiResponse
{
    private const NO_STORE_HEADERS = [
        'Cache-Control' => 'no-store, must-revalidate',
        'Pragma' => 'no-cache',
    ];

    public static function data(mixed $data = null, int $status = 200): Response
    {
        return self::withNoStore(json(['data' => $data], $status));
    }

    public static function noContent(): Response
    {
        return self::withNoStore(response('', 204));
    }

    public static function error(string $message, int $status, string $code, array $details = []): Response
    {
        return self::withNoStore(json([
            'message' => $message,
            'code' => $code,
            'status' => $status,
            'details' => $details,
        ], $status));
    }

    private static function withNoStore(Response $response): Response
    {
        return $response->header(self::NO_STORE_HEADERS);
    }
}
