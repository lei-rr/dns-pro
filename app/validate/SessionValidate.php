<?php

declare(strict_types=1);

namespace app\validate;

use think\Validate;

class SessionValidate extends Validate
{
    protected $rule = [
        'username' => 'require|string|max:64',
        'password' => 'require|string|max:128',
        'captcha' => 'string|max:16',
    ];

    protected $field = [
        'username' => '用户名',
        'password' => '密码',
        'captcha' => '验证码',
    ];

    public function sceneLogin(): self
    {
        return $this->only(['username', 'password', 'captcha']);
    }
}
