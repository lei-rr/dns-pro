<?php

declare(strict_types=1);

namespace app\support;

use app\repository\AppConfigRepository;
use RuntimeException;

/**
 * 应用配置（data/config.json）
 *
 * 当前用于鉴权用户名/密码（明文，本系统单用户场景）。
 * 通过 JsonStore 读取，进程内单例缓存，避免每次 session 校验都读盘。
 */
class AppConfig
{
    private ?array $cache = null;

    public function __construct(private readonly AppConfigRepository $config = new AppConfigRepository())
    {
    }

    public function authUsername(): string
    {
        return (string) ($this->auth()['username'] ?? '');
    }

    public function authPassword(): string
    {
        return (string) ($this->auth()['password'] ?? '');
    }

    /**
     * 严格凭据校验：用户名+密码完全匹配 config.json 中的明文，且不允许空密码
     */
    public function verifyCredentials(string $username, string $password): bool
    {
        $expectedUser = $this->authUsername();
        $expectedPass = $this->authPassword();

        if ($expectedUser === '' || $expectedPass === '') {
            throw new RuntimeException('Authentication is not configured: data/config.json 的 auth.username 或 auth.password 为空');
        }

        return hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password);
    }

    /**
     * @return array{username:string, password:string}
     */
    private function auth(): array
    {
        if ($this->cache === null) {
            $config = $this->config->read();
            $this->cache = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        }

        return $this->cache;
    }
}
