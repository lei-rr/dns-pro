<?php

declare(strict_types=1);

namespace app\controller\hostname;

use app\controller\concerns\ValidatesInput;
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
    use ValidatesInput;

    public function __construct(private readonly PreferredDomainService $preferredDomains)
    {
    }

    public function index(): Response
    {
        return ApiResponse::data(['items' => $this->preferredDomains->list()]);
    }

    public function store(): Response
    {
        $data = $this->postInput(PreferredDomainValidate::class, 'store');

        return ApiResponse::data($this->preferredDomains->create((string) $data['domain']), 201);
    }

    public function update(string $domain): Response
    {
        $data = $this->putInput(PreferredDomainValidate::class, 'update');

        return ApiResponse::data($this->preferredDomains->rename(rawurldecode($domain), (string) $data['domain']));
    }

    public function delete(string $domain): Response
    {
        $this->preferredDomains->delete(rawurldecode($domain));

        return ApiResponse::noContent();
    }

    public function sort(): Response
    {
        $data = $this->putInput(PreferredDomainValidate::class, 'sort');
        $domains = array_map(static fn ($v) => (string) $v, (array) ($data['domains'] ?? []));

        return ApiResponse::data(['items' => $this->preferredDomains->reorder($domains)]);
    }
}
