<?php

declare(strict_types=1);

namespace app\validate;

use app\exception\ApiException;

/**
 * Provider 数据标准化器
 *
 * 负责服务商数据的格式化和验证，避免与 ThinkPHP 的 ProviderValidate 命名混淆
 */
class ProviderNormalizer
{
    /**
     * 保留的服务商ID列表
     */
    private const RESERVED_PROVIDER_IDS = ['home', 'login', 'providers', 'user'];

    /**
     * 字段最大长度限制
     */
    private const FIELD_MAX_LENGTHS = [
        'secret_id' => 128,
        'secret_key' => 256,
        'api_token' => 512,
        'account_id' => 128,
        'dnspod_provider' => 64,
        'cloudflare_provider' => 64,
        'cloudflare_dns_provider' => 64,
    ];

    /**
     * 验证并标准化服务商数据
     *
     * @param array $data       服务商数据
     * @param array $definition 服务商定义（已验证的类型定义）
     *
     * @return array 标准化后的服务商数据
     * @throws ApiException
     */
    public function normalize(array $data, array $definition): array
    {
        $id = $this->validateId($data['id'] ?? '');
        $type = $definition['type'] ?? '';

        $provider = [
            'id' => $id,
            'name' => $this->validateName($data['name'] ?? '', $definition['name'] ?? ''),
            'type' => $type,
        ];

        foreach ($definition['fields'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if (in_array($field, $definition['required'], true) && $value === '') {
                throw new ApiException('Provider field is required', 422, 'validation_failed', [
                    'errors' => [$field => 'Provider field is required'],
                ]);
            }

            if ($value !== '') {
                $this->validateField($field, $value);
            }

            $provider[$field] = $value;
        }

        return $provider;
    }

    /**
     * 验证服务商ID
     *
     * @throws ApiException
     */
    public function validateId(mixed $id): string
    {
        $id = trim((string) $id);

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $id)) {
            throw new ApiException('Invalid provider id', 422, 'validation_failed', [
                'errors' => ['id' => 'Invalid provider id'],
            ]);
        }

        if (in_array(strtolower($id), self::RESERVED_PROVIDER_IDS, true)) {
            throw new ApiException('Provider id is reserved', 422, 'validation_failed', [
                'errors' => ['id' => 'Provider id is reserved'],
            ]);
        }

        return $id;
    }

    /**
     * 验证服务商名称
     */
    public function validateName(mixed $name, string $defaultName): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : $defaultName;
    }

    /**
     * 验证字段值
     *
     * @throws ApiException
     */
    public function validateField(string $field, string $value): void
    {
        $maxLength = self::FIELD_MAX_LENGTHS[$field] ?? 255;

        if (strlen($value) > $maxLength) {
            throw new ApiException('Provider field is too long', 422, 'validation_failed', [
                'errors' => [$field => "Provider field is too long (max: {$maxLength})"],
            ]);
        }

        if (in_array($field, ['dnspod_provider', 'cloudflare_provider', 'cloudflare_dns_provider'], true) && !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $value)) {
            throw new ApiException('Invalid referenced provider id', 422, 'validation_failed', [
                'errors' => [$field => 'Invalid provider id'],
            ]);
        }
    }
}
