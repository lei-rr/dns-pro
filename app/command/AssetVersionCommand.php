<?php

declare(strict_types=1);

namespace app\command;

use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class AssetVersionCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('assets:version')
            ->addArgument('version', Argument::OPTIONAL, 'Asset version value')
            ->setDescription('Update frontend asset cache-bust version in public/app.html');
    }

    protected function execute(Input $input, Output $output): int
    {
        $version = trim((string) ($input->getArgument('version') ?: date('YmdHis')));

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
            throw new RuntimeException('Asset version may only contain letters, numbers, dot, underscore, and dash.');
        }

        $path = app()->getRootPath() . 'public/app.html';
        $html = file_get_contents($path);

        if ($html === false) {
            throw new RuntimeException('Unable to read public/app.html.');
        }

        $count = 0;
        $next = preg_replace_callback(
            '#(/assets/app\.(?:css|js)\?v=)[A-Za-z0-9._-]+#',
            static fn (array $matches): string => $matches[1] . $version,
            $html,
            -1,
            $count,
        );

        if (!is_string($next) || $count === 0) {
            throw new RuntimeException('No asset version references were updated.');
        }

        if ($next === $html) {
            $output->writeln('Asset version already current: ' . $version);

            return 0;
        }

        if (file_put_contents($path, $next, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write public/app.html.');
        }

        $output->writeln('Asset version updated: ' . $version);

        return 0;
    }
}
