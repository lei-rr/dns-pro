<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class EdgeOneZoneValidate extends Validate
{
    protected $rule = [
        'offset' => 'number|egt:0',
        'limit' => 'number|between:1,100',
        'refresh' => 'boolean',
    ];

    protected $field = [
        'offset' => '偏移量',
        'limit' => '每页数量',
        'refresh' => '刷新缓存',
    ];

    public function sceneIndex(): self
    {
        return $this->only(['offset', 'limit', 'refresh']);
    }
}
