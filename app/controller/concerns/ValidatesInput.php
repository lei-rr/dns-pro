<?php

declare(strict_types=1);

namespace app\controller\concerns;

/**
 * 控制器输入校验辅助
 *
 * 收敛 controller 中重复的 validate()->scene()->checked(input(...)) 模式。
 * 只处理协议层输入读取与校验，不承载业务逻辑。
 */
trait ValidatesInput
{
    protected function queryInput(string $validateClass, string $scene): array
    {
        return validate($validateClass)->scene($scene)->checked(input('get.', []));
    }

    protected function rawQueryInput(string $validateClass, string $scene): array
    {
        $input = input('get.', []);
        validate($validateClass)->scene($scene)->check($input);
        return is_array($input) ? $input : [];
    }

    protected function postInput(string $validateClass, string $scene): array
    {
        return validate($validateClass)->scene($scene)->checked(input('post.', []));
    }

    protected function rawPostInput(string $validateClass, string $scene): array
    {
        $input = input('post.', []);
        validate($validateClass)->scene($scene)->check($input);
        return is_array($input) ? $input : [];
    }

    protected function putInput(string $validateClass, string $scene): array
    {
        return validate($validateClass)->scene($scene)->checked(input('put.', []));
    }

    protected function rawPutInput(string $validateClass, string $scene): array
    {
        $input = input('put.', []);
        validate($validateClass)->scene($scene)->check($input);
        return is_array($input) ? $input : [];
    }
}
