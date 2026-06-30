<?php
namespace app;

use app\exception\ApiException;
use app\support\ApiResponse;
use app\support\ErrorMessages;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 *
 * 仅对 api/ 路径的异常作 JSON 响应,其他交给框架默认渲染(SPA HTML / 错误页)。
 *
 * message 优先取 ErrorMessages 中的中文文案,找不到时回退到原始英文 message。
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息(日志)的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        if (str_starts_with($request->pathinfo(), 'api/')) {
            return $this->renderApi($e);
        }

        return parent::render($request, $e);
    }

    private function renderApi(Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            $code = $e->getErrorCode();
            $message = $this->apiExceptionMessage($e);

            return ApiResponse::error($message, $e->getStatus(), $code, $e->getDetails());
        }

        if ($e instanceof ValidateException) {
            // Validate 自身的 message 已经是中文(取自 $field 标签 + 内置中文模板)
            return ApiResponse::error(
                ErrorMessages::translate('validation_failed') ?? 'Validation failed',
                422,
                'validation_failed',
                ['errors' => $e->getError()],
            );
        }

        if ($e instanceof HttpException) {
            return ApiResponse::error(
                ErrorMessages::translate('http_error') ?? $e->getMessage(),
                $e->getStatusCode(),
                'http_error',
            );
        }

        return ApiResponse::error(
            ErrorMessages::translate('server_error') ?? 'Internal server error',
            500,
            'server_error',
            app()->isDebug() ? [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ] : [],
        );
    }

    private function apiExceptionMessage(ApiException $e): string
    {
        $code = $e->getErrorCode();
        $translated = ErrorMessages::translate($code);
        if ($translated === null) {
            return $e->getMessage();
        }

        if ($code === 'cloudflare_request_failed') {
            $detail = $this->upstreamErrorDetail($e);
            if ($detail !== '') {
                return $translated . '：' . $detail;
            }
        }

        return $translated;
    }

    private function upstreamErrorDetail(ApiException $e): string
    {
        $errors = $e->getDetails()['errors'] ?? [];
        if (is_array($errors) && isset($errors[0])) {
            $first = $errors[0];

            if (is_array($first)) {
                $detail = trim((string) ($first['message'] ?? $first['error'] ?? ''));
                if ($detail !== '') {
                    return $detail;
                }
            }

            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        $message = trim($e->getMessage());
        if (str_contains($message, ':')) {
            $detail = trim(substr($message, strpos($message, ':') + 1));
            if ($detail !== '') {
                return $detail;
            }
        }

        return '';
    }
}
