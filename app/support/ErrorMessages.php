<?php

declare(strict_types=1);

namespace app\support;

/**
 * 错误码 → 中文文案映射
 *
 * 业务代码继续按 error_code 抛 ApiException(英文 code 稳定),
 * 这里集中维护对外可见的中文文案。
 *
 * 约定:
 *   - 文案以用户视角描述"发生了什么";尽量不暴露内部实现
 *   - 文案末尾不加句号
 *   - 如果某个 code 不在表里,返 null,由 ExceptionHandle 回退到原始英文 message
 */
class ErrorMessages
{
    /** @var array<string, string> */
    private const MAP = [
        // 鉴权
        'unauthenticated'          => '请先登录',
        'invalid_credentials'      => '用户名或密码不正确',
        'captcha_required'         => '请输入验证码',
        'invalid_captcha'          => '验证码不正确',

        // 通用
        'validation_failed'        => '参数校验未通过',
        'not_found'                => '接口不存在',
        'http_error'               => '请求失败',
        'server_error'             => '服务内部错误',
        'sync_skipped'             => '同步已跳过',

        // Provider 通用
        'provider_not_found'                  => '服务商不存在',
        'provider_not_configured'             => '服务商尚未配置完整',
        'provider_exists'                     => '该服务商标识已存在',
        'provider_type_immutable'             => '服务商类型不能修改',
        'provider_order_duplicated'           => '排序列表中存在重复的服务商',
        'provider_order_mismatch'             => '排序列表与现有服务商不匹配',
        'provider_in_use'                     => '该服务商正在被其他服务商引用,无法删除',

        // Cloudflare
        'cloudflare_provider_not_found'       => 'Cloudflare 服务商不存在',
        'cloudflare_account_id_required'      => 'Cloudflare 账户 ID 不能为空',
        'cloudflare_zone_not_found'           => 'Cloudflare 站点不存在',
        'cloudflare_connection_failed'        => 'Cloudflare 连接失败',
        'cloudflare_invalid_response'         => 'Cloudflare 返回数据无效',
        'cloudflare_request_failed'           => 'Cloudflare 请求失败',

        // DNSPod
        'dnspod_provider_not_found'           => 'DNSPod 服务商不存在',
        'dnspod_provider_missing'             => '未配置关联的 DNSPod 服务商',
        'dnspod_record_conflict'              => 'DNSPod 已存在冲突的记录类型',
        'dnspod_record_conflict_multiple'     => 'DNSPod 存在多条匹配的记录,无法自动同步',
        'dnspod_record_id_missing'            => 'DNSPod 记录 ID 缺失',
        'dnspod_record_create_failed'         => 'DNSPod 记录创建失败',
        'dnspod_record_update_failed'         => 'DNSPod 记录更新失败',
        'dnspod_record_delete_failed'         => 'DNSPod 记录删除失败',
        'dnspod_record_list_failed'           => 'DNSPod 记录列表获取失败',
        'dnspod_zone_create_failed'           => 'DNSPod 域名添加失败',
        'dnspod_zone_delete_failed'           => 'DNSPod 域名删除失败',
        'dnspod_zone_list_failed'             => 'DNSPod 域名列表获取失败',

        // EdgeOne
        'edgeone_provider_not_found'                       => 'EdgeOne 服务商不存在',
        'edgeone_dnspod_provider_not_found'                => '关联的 DNSPod 服务商不存在',
        'edgeone_zone_not_found'                           => 'EdgeOne 站点不存在',
        'edgeone_zone_list_failed'                         => 'EdgeOne 站点列表获取失败',
        'edgeone_cname_empty'                              => 'EdgeOne 加速域名尚未生成 CNAME',
        'edgeone_cname_status_failed'                      => 'EdgeOne CNAME 解析状态查询失败',
        'edgeone_domain_zone_mismatch'                     => '加速域名不属于该 DNSPod 域名',
        'edgeone_acceleration_domain_not_found'            => 'EdgeOne 加速域名不存在',
        'edgeone_acceleration_domain_create_failed'        => 'EdgeOne 加速域名创建失败',
        'edgeone_acceleration_domain_update_failed'        => 'EdgeOne 加速域名更新失败',
        'edgeone_acceleration_domain_delete_failed'        => 'EdgeOne 加速域名删除失败',
        'edgeone_acceleration_domain_list_failed'          => 'EdgeOne 加速域名列表获取失败',
        'edgeone_acceleration_domain_status_update_failed' => 'EdgeOne 加速域名状态修改失败',
        'edgeone_certificate_update_failed'                => 'EdgeOne 证书更新失败',

        // Hostname
        'hostname_provider_not_found'              => 'Hostname 服务商不存在',
        'hostname_cloudflare_provider_missing'     => 'Hostname 未关联 Cloudflare 服务商',
        'hostname_dnspod_provider_missing'         => 'Hostname 未关联 DNSPod 服务商',
        'hostname_dnspod_zone_not_found'           => 'DNSPod 中找不到与该主机名匹配的域名',
        'hostname_fqdn_empty'                      => '主机名 FQDN 为空',
        'hostname_fqdn_missing'                    => '主机名 FQDN 缺失',
        'hostname_no_sync_records'                 => '该主机名当前没有可同步的 DNS 记录',

        // Preferred Domain
        'preferred_domain_duplicate'     => '该优选域名已存在',
        'preferred_domain_invalid'       => '域名格式不正确',
        'preferred_domain_not_found'     => '优选域名不存在',
        'preferred_domain_not_allowed'   => '该域名不在优选域名列表中',

        // Fallback origin
        'fallback_origin_zone_mismatch'  => '默认回源必须是该站点的子域名',
    ];

    /**
     * 取错误码对应的中文文案;不存在返 null
     */
    public static function translate(string $code): ?string
    {
        return self::MAP[$code] ?? null;
    }
}
