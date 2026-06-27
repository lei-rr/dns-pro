<?php

declare(strict_types=1);

namespace app\support;

use RuntimeException;

/**
 * JSON 文件存储
 *
 * 提供带文件锁的读/写/事务，用于替代轻量数据需求的 SQLite。
 *
 * 行为约定：
 *   - 路径相对 `data/` 目录（项目根 `data/`）
 *   - 读取时若文件不存在/为空，返回构造时传入的 $default
 *   - 写入：先写到 .tmp，再 rename，原子替换
 *   - transaction()：LOCK_EX 持锁期间读 → 修改 → 写
 *
 * 实例与文件路径一一对应，可作为 service 的 readonly 依赖注入。
 */
class JsonStore
{
    private readonly string $absolutePath;

    /**
     * @param string $relativePath 相对 data/ 的路径，如 "providers.json"、"hostname/preferences.json"
     * @param array  $default      文件不存在时返回的默认结构
     */
    public function __construct(
        string $relativePath,
        private readonly array $default = [],
    ) {
        $relative = ltrim($relativePath, '/');
        $this->absolutePath = self::dataRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * 读取整个文件
     */
    public function read(): array
    {
        if (!is_file($this->absolutePath)) {
            return $this->default;
        }

        $fp = fopen($this->absolutePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException(sprintf('Failed to open %s for reading', $this->absolutePath));
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                throw new RuntimeException(sprintf('Failed to acquire shared lock on %s', $this->absolutePath));
            }

            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if ($contents === '' || $contents === false) {
            return $this->default;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON in %s', $this->absolutePath));
        }

        return $decoded;
    }

    /**
     * 整体覆盖写入（原子替换）
     */
    public function write(array $data): void
    {
        $this->ensureDirectory();

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException(sprintf('Failed to encode JSON for %s', $this->absolutePath));
        }

        $tmp = $this->absolutePath . '.tmp';
        if (file_put_contents($tmp, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write %s', $tmp));
        }

        if (!@rename($tmp, $this->absolutePath)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Failed to atomically replace %s', $this->absolutePath));
        }
    }

    /**
     * 持排他锁的"读 → 修改 → 写"事务
     *
     * @template T
     * @param  callable(array): array $mutator 接收当前数据，返回新数据
     * @return array 写入后的数据
     */
    public function transaction(callable $mutator): array
    {
        $this->ensureDirectory();

        $fp = fopen($this->absolutePath, 'cb+');
        if ($fp === false) {
            throw new RuntimeException(sprintf('Failed to open %s for writing', $this->absolutePath));
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException(sprintf('Failed to acquire exclusive lock on %s', $this->absolutePath));
            }

            $contents = stream_get_contents($fp);
            $current = ($contents === '' || $contents === false) ? $this->default : (json_decode($contents, true) ?: $this->default);
            if (!is_array($current)) {
                $current = $this->default;
            }

            $next = $mutator($current);

            $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($encoded === false) {
                throw new RuntimeException(sprintf('Failed to encode JSON for %s', $this->absolutePath));
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded . PHP_EOL);
            fflush($fp);
            flock($fp, LOCK_UN);

            return $next;
        } finally {
            fclose($fp);
        }
    }

    public function path(): string
    {
        return $this->absolutePath;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->absolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Failed to create directory %s', $dir));
        }
    }

    private static function dataRoot(): string
    {
        // app/support/JsonStore.php → 项目根 data/
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
    }
}
