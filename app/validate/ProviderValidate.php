<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class ProviderValidate extends Validate
{
    protected $rule = [
        'id' => 'require|string|max:64',
        'name' => 'string|max:64',
        'type' => 'require|string|max:32',
        'order' => 'require|array',
    ];

    protected $field = [
        'id' => '服务商标识',
        'name' => '服务商名称',
        'type' => '服务商类型',
        'order' => '服务商排序',
    ];

    protected $scene = [
        'store' => ['id', 'name', 'type'],
        'sort' => ['order'],
    ];

    public function sceneUpdate(): self
    {
        return $this->only(['name', 'type'])
            ->remove('type', 'require');
    }
}
