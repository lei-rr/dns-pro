<?php

declare(strict_types=1);

namespace app\controller\auth;

use app\service\SessionService;
use app\support\ApiResponse;
use app\validate\SessionValidate;
use think\Response;

class SessionController
{
    public function __construct(private readonly SessionService $session)
    {
    }

    public function store(): Response
    {
        $data = validate(SessionValidate::class)
            ->scene('login')
            ->checked(input('post.', []));

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
