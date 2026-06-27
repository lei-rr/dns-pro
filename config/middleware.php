<?php
// 中间件配置
use app\middleware\AuthRequiredMiddleware;

return [
    // 别名或分组
    'alias'    => [
        'auth.required' => AuthRequiredMiddleware::class,
    ],
    // 优先级设置,此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [],
];
