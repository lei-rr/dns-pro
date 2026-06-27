<?php

declare(strict_types=1);

namespace app\service;

use app\exception\ApiException;
use app\support\AppConfig;
use app\support\AuthSession;

/**
 * 鉴权会话服务
 *
 * 凭据来源：data/config.json 的 auth.username / auth.password（明文）。
 * 单用户场景，无需注册/改密。
 *
 * 登录失败一次后强制要求验证码，验证失败/未带都会保持 captcha_required 直到登录成功。
 */
class SessionService
{
    public function __construct(private readonly AppConfig $config)
    {
    }

    public function login(string $username, string $password, ?string $captcha = null): array
    {
        if (AuthSession::captchaRequired()) {
            $this->verifyCaptcha($captcha);
        }

        if (!$this->config->verifyCredentials($username, $password)) {
            AuthSession::requireCaptcha();
            throw new ApiException('Invalid username or password', 401, 'invalid_credentials', [
                'captcha_required' => true,
            ]);
        }

        AuthSession::signIn($username);

        return $this->currentSession();
    }

    public function logout(): void
    {
        AuthSession::signOut();
    }

    public function currentSession(): array
    {
        return [
            'authenticated' => AuthSession::signedIn(),
            'username' => AuthSession::username(),
            'captcha_required' => AuthSession::captchaRequired(),
        ];
    }

    private function verifyCaptcha(?string $captcha): void
    {
        if ($captcha === null || $captcha === '') {
            throw new ApiException('Captcha required', 422, 'captcha_required', [
                'captcha_required' => true,
            ]);
        }

        if (!captcha_check($captcha)) {
            throw new ApiException('Invalid captcha', 422, 'invalid_captcha', [
                'captcha_required' => true,
            ]);
        }
    }
}
