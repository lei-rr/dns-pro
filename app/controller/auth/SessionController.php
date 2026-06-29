<?php

declare(strict_types=1);

namespace app\controller\auth;

use app\controller\concerns\ValidatesInput;
use app\service\SessionService;
use app\support\ApiResponse;
use app\validate\SessionValidate;
use think\Response;

class SessionController
{
    use ValidatesInput;

    public function __construct(private readonly SessionService $session)
    {
    }

    public function store(): Response
    {
        $data = $this->postInput(SessionValidate::class, 'login');

        return ApiResponse::data($this->session->login(
            (string) $data['username'],
            (string) $data['password'],
            $data['captcha'] ?? null,
        ));
    }

    public function show(): Response
    {
        return ApiResponse::data($this->session->currentSession());
    }

    public function delete(): Response
    {
        $this->session->logout();

        return ApiResponse::noContent();
    }
}
