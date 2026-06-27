<?php

declare(strict_types=1);

namespace app\controller\hostname;

use app\service\hostname\PreferredDomainService;
use app\support\ApiResponse;
use app\validate\PreferredDomainValidate;
use think\Response;

/**
 * 优选域名控制器
 *
 * 全局共享一份列表,用于 hostname 创建/编辑时的"境内优选 CNAME"下拉候选。
 * URL 中的 :domain 即记录标识,需 URL-encode。
 */
class PreferredDomainController
{
    public function __construct(private readonly PreferredDomainService $preferredDomains)
    {
    }

    public function index(): Response
    {
        return ApiResponse::data(['items' => $this->preferredDomains->list()]);
    }

    public function store(): Response
    {
        $data = validate(PreferredDomainValidate::class)->scene('store')->checked(input('post.', []));

        return ApiResponse::data($this->preferredDomains->create((string) $data['domain']), 201);
    }

    public function update(string $domain): Response
    {
        $data = validate(PreferredDomainValidate::class)->scene('update')->checked(input('put.', []));

        return ApiResponse::data($this->preferredDomains->rename(rawurldecode($domain), (string) $data['domain']));
    }

    public function delete(string $domain): Response
    {
        $this->preferredDomains->delete(rawurldecode($domain));

        return ApiResponse::noContent();
    }

    public function sort(): Response
    {
        $data = validate(PreferredDomainValidate::class)->scene('sort')->checked(input('put.', []));
        $domains = array_map(static fn ($v) => (string) $v, (array) ($data['domains'] ?? []));

        return ApiResponse::data(['items' => $this->preferredDomains->reorder($domains)]);
    }
}
