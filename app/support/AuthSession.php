<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Session;

/**
 * 鉴权会话状态
 *
 * 单用户场景下只需记录"已登录"标记 + 用户名展示。
 * 登录失败时设置 captcha_required，下一次登录必须带验证码（防暴力破解）。
 */
class AuthSession
{
    public const SIGNED_IN_KEY = 'auth.signed_in';
    public const USERNAME_KEY = 'auth.username';
    public const CAPTCHA_REQUIRED_KEY = 'auth.captcha_required';

    public static function signedIn(): bool
    {
        return session(self::SIGNED_IN_KEY) === true;
    }

    public static function signIn(string $username): void
    {
        Session::regenerate(true);
        session(self::SIGNED_IN_KEY, true);
        session(self::USERNAME_KEY, $username);
        self::clearCaptchaRequired();
    }

    public static function signOut(): void
    {
        session(self::SIGNED_IN_KEY, null);
        session(self::USERNAME_KEY, null);
    }

    public static function username(): ?string
    {
        $username = session(self::USERNAME_KEY);

        return is_string($username) && $username !== '' ? $username : null;
    }

    public static function captchaRequired(): bool
    {
        return session('?' . self::CAPTCHA_REQUIRED_KEY);
    }

    public static function requireCaptcha(): void
    {
        session(self::CAPTCHA_REQUIRED_KEY, true);
    }

    public static function clearCaptchaRequired(): void
    {
        session(self::CAPTCHA_REQUIRED_KEY, null);
    }
}
