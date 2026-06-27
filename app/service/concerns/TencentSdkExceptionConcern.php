<?php

declare(strict_types=1);

namespace app\service\concerns;

use app\exception\ApiException;
use TencentCloud\Common\Exception\TencentCloudSDKException;

/**
 * Tencent SDK 异常处理特性
 *
 * 统一将Tencent SDK异常转换为ApiException
 */
trait TencentSdkExceptionConcern
{
    /**
     * 将Tencent SDK异常转换为ApiException
     *
     * @param string               $message    错误消息
     * @param string               $code       错误代码
     * @param string               $providerId 服务商ID
     * @param TencentCloudSDKException $exception SDK异常
     * @param array                $details    额外的详细信息
     */
    protected function wrapSdkException(
        string $message,
        string $code,
        string $providerId,
        TencentCloudSDKException $exception,
        array $details = [],
    ): ApiException {
        return new ApiException($message, 502, $code, $details + [
            'provider_id' => $providerId,
            'sdk_code' => $exception->getErrorCode(),
            'request_id' => $exception->getRequestId(),
        ]);
    }
}
