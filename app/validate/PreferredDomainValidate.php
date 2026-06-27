<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

/**
 * 优选域名入参校验
 *
 * Scenes:
 *   - store  POST /preferred-domains              { domain }
 *   - update PUT  /preferred-domains/:domain      { domain }（重命名）
 *   - sort   PUT  /preferred-domains/sort         { domains: [...] }
 */
class PreferredDomainValidate extends Validate
{
    protected $rule = [
        'domain' => 'require|string|max:253',
        'domains' => 'require|array',
    ];

    protected $field = [
        'domain' => '优选域名',
        'domains' => '排序列表',
    ];

    public function sceneStore(): self
    {
        return $this->only(['domain']);
    }

    public function sceneUpdate(): self
    {
        return $this->only(['domain']);
    }

    public function sceneSort(): self
    {
        return $this->only(['domains']);
    }
}
