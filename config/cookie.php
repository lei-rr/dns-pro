<?php

return [
    'expire' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => env('COOKIE_SECURE', false),
    'httponly' => true,
    'setcookie' => true,
    'samesite' => 'lax',
];
