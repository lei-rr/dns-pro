<?php

namespace app\controller;

use think\Response;

class Index
{
    public function index(): Response
    {
        return response(file_get_contents(app()->getRootPath() . 'public/app.html') ?: '', 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
