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
    /** @var array<string, array{model: string, input: int, cached_input: int, output: int}> alias => upstream model id and rates (credits per million tokens) */
    public readonly array $models;
    public readonly int $maxTokensCap;
    public readonly int $maxConcurrentPerKey;
    /** Default per-key limits over the last 60 seconds; null = no limit. A key's own setting overrides them. */
    public readonly ?int $requestsPerMinute;
    public readonly ?int $creditsPerMinute;
    public readonly int $leaseSeconds;
    /** @var array<string, mixed> merged into every streaming request as stream_options */
    public readonly array $streamOptions;
    public readonly string $adminTokenSha256;
    /** Directory for the daily JSONL log files; null logs to PHP's error_log. */
    public readonly ?string $logDir;
    /** error | warn | info | debug */
    public readonly string $logLevel;

    /** @param array<string, mixed> $c */
    public function __construct(array $c)
    {
        $this->dbPath = self::str($c, 'db_path');
        $this->upstreamUrl = self::str($c, 'upstream_url');
        $this->upstreamKeyFile = self::str($c, 'upstream_key_file');
        $this->upstreamTimeout = self::int($c, 'upstream_timeout', 600);
        $this->maxTokensCap = self::int($c, 'max_tokens_cap', 16384);
        if ($this->maxTokensCap > Credits::MAX_COUNT) {
            throw new \InvalidArgumentException('config: max_tokens_cap must be at most ' . Credits::MAX_COUNT);
        }
        $this->maxConcurrentPerKey = self::int($c, 'max_concurrent_per_key', 4);
        $this->leaseSeconds = self::int($c, 'lease_seconds', 900);
        $this->requestsPerMinute = self::limit($c, 'requests_per_minute');
        $this->creditsPerMinute = self::limit($c, 'credits_per_minute');
        $this->adminTokenSha256 = strtolower(self::str($c, 'admin_token_sha256'));
        $this->logDir = isset($c['log_dir']) ? self::str($c, 'log_dir') : null;
        $this->logLevel = $c['log_level'] ?? 'warn';
        if (!is_string($this->logLevel) || !isset(Log::LEVELS[$this->logLevel])) {
            throw new \InvalidArgumentException('config: log_level must be error, warn, info or debug');
        }

        $models = $c['models'] ?? null;
        if (!is_array($models) || $models === []) {
            throw new \InvalidArgumentException('config: models must be a non-empty map of alias => model and rates');
        }
        $parsed = [];
        foreach ($models as $alias => $m) {
            if (!is_string($alias) || $alias === '' || !is_array($m) || !is_string($m['model'] ?? null) || $m['model'] === '') {
                throw new \InvalidArgumentException('config: each models entry needs a non-empty alias and a model string');
            }
            $rate = static fn (string $k, int $min): int => is_int($m[$k] ?? null) && $m[$k] >= $min && $m[$k] <= Credits::MAX_RATE ? $m[$k]
                : throw new \InvalidArgumentException("config: models.$alias.$k must be an integer from $min to " . Credits::MAX_RATE);
            $p = ['model' => $m['model'], 'input' => $rate('input', 1), 'cached_input' => $rate('cached_input', 0), 'output' => $rate('output', 1)];
            // Reservations price the whole prompt at the input rate; that bounds the charge only if cached input costs no more.
            if ($p['cached_input'] > $p['input']) {
                throw new \InvalidArgumentException("config: models.$alias.cached_input must not exceed input");
            }
            $parsed[$alias] = $p;
        }
        $this->models = $parsed;

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

    /**
     * The configured per-key limits, used where a key has no setting of its own.
     * @return array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int}
     */
    public function limitDefaults(): array
    {
        return ['max_concurrent' => $this->maxConcurrentPerKey, 'requests_per_minute' => $this->requestsPerMinute, 'credits_per_minute' => $this->creditsPerMinute];
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
    private static function limit(array $c, string $k): ?int
    {
        $v = $c[$k] ?? null;
        if ($v !== null && (!is_int($v) || $v < 1 || $v > Ledger::MAX_GRANT)) {
            throw new \InvalidArgumentException("config: $k must be a positive integer of at most " . Ledger::MAX_GRANT . ' (or unset for no limit)');
        }
        return $v;
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
