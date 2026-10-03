<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * Loads and validates the deployment configuration (a PHP file returning an array).
 * See config.example.php for every key and its meaning.
 */
final class Config
{
    public readonly string $dbPath;
    public readonly string $upstreamUrl;
    public readonly string $upstreamKeyFile;
    public readonly int $upstreamTimeout;
    /** @var array<string, string> alias => upstream model id */
    public readonly array $models;
    public readonly int $maxTokensCap;
    public readonly int $maxConcurrentPerKey;
    public readonly int $leaseSeconds;
    /** @var array<string, mixed> merged into every streaming request as stream_options */
    public readonly array $streamOptions;
    public readonly string $adminTokenSha256;

    /** @param array<string, mixed> $c */
    public function __construct(array $c)
    {
        $this->dbPath = self::str($c, 'db_path');
        $this->upstreamUrl = self::str($c, 'upstream_url');
        $this->upstreamKeyFile = self::str($c, 'upstream_key_file');
        $this->upstreamTimeout = self::int($c, 'upstream_timeout', 600);
        $this->maxTokensCap = self::int($c, 'max_tokens_cap', 16384);
        $this->maxConcurrentPerKey = self::int($c, 'max_concurrent_per_key', 4);
        $this->leaseSeconds = self::int($c, 'lease_seconds', 900);
        $this->adminTokenSha256 = strtolower(self::str($c, 'admin_token_sha256'));

        $models = $c['models'] ?? null;
        if (!is_array($models) || $models === []) {
            throw new \InvalidArgumentException('config: models must be a non-empty alias => upstream model map');
        }
        foreach ($models as $alias => $upstream) {
            if (!is_string($alias) || !is_string($upstream) || $alias === '' || $upstream === '') {
                throw new \InvalidArgumentException('config: models entries must be non-empty strings');
            }
        }
        $this->models = $models;

        $opts = $c['stream_options'] ?? ['include_usage' => true];
        if (!is_array($opts) || ($opts['include_usage'] ?? false) !== true) {
            throw new \InvalidArgumentException('config: stream_options must include include_usage => true');
        }
        $this->streamOptions = $opts;

        if (!preg_match('/^[0-9a-f]{64}$/', $this->adminTokenSha256)) {
            throw new \InvalidArgumentException('config: admin_token_sha256 must be a sha256 hex digest');
        }
        // A lease shorter than the upstream timeout would stop counting a live request against the concurrency limit.
        if ($this->leaseSeconds <= $this->upstreamTimeout) {
            throw new \InvalidArgumentException('config: lease_seconds must be greater than upstream_timeout');
        }
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("config: file not found: $path");
        }
        $c = require $path;
        if (!is_array($c)) {
            throw new \RuntimeException('config: file must return an array');
        }
        return new self($c);
    }

    public function upstreamKey(): string
    {
        $key = is_readable($this->upstreamKeyFile) ? trim((string) file_get_contents($this->upstreamKeyFile)) : '';
        if ($key === '') {
            throw new \RuntimeException('upstream key file missing or empty');
        }
        return $key;
    }

    /** @param array<string, mixed> $c */
    private static function str(array $c, string $k): string
    {
        if (!isset($c[$k]) || !is_string($c[$k]) || $c[$k] === '') {
            throw new \InvalidArgumentException("config: $k must be a non-empty string");
        }
        return $c[$k];
    }

    /** @param array<string, mixed> $c */
    private static function int(array $c, string $k, int $default): int
    {
        $v = $c[$k] ?? $default;
        if (!is_int($v) || $v < 1) {
            throw new \InvalidArgumentException("config: $k must be a positive integer");
        }
        return $v;
    }
}
