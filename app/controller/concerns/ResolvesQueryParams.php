<?php

declare(strict_types=1);

namespace app\controller\concerns;

/**
 * 控制器查询参数解析辅助
 *
 * 抽取多个 controller 共用的 query 解析逻辑，避免逐份重复。
 */
trait ResolvesQueryParams
{
    /**
     * 读取布尔 query 参数
     *
     * 1/true/on/yes → true；0/false/off/no → false；空或其它 → $default
     */
    protected function boolQuery(string $key, bool $default = false): bool
    {
        $value = strtolower((string) input('get.' . $key, ''));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'on', 'yes'], true) ? true
            : (in_array($value, ['0', 'false', 'off', 'no'], true) ? false : $default);
    }
}
