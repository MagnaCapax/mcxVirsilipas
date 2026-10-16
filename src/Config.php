<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * Loads and validates the deployment configuration (a PHP file returning an array).
 * See config.example.php for every key and its meaning.
 */
final class Config
{
    /** Request fields a model's params may not set: the proxy owns them (metering, n=1, the alias mapping). */
    public const PARAMS_RESERVED = ['model', 'messages', 'max_tokens', 'max_completion_tokens', 'stream', 'stream_options', 'n'];
    /** Extra attempts on the same upstream after a failure that cost nothing upstream. */
    public const MAX_RETRIES = 2;
    /** Every setting each level of the config may have: anything else is refused, so a misspelt key never silently drops a limit. */
    private const KEYS = ['db_path', 'upstream_url', 'upstream_key_file', 'upstream_timeout', 'max_tokens_cap', 'max_concurrent_per_key', 'lease_seconds',
        'requests_per_minute', 'credits_per_minute', 'admin_token_sha256', 'log_dir', 'log_level', 'throttle', 'stream_options', 'retries',
        'upstreams', 'models', 'caps'];
    private const MODEL_KEYS = ['input', 'cached_input', 'output', 'route', 'model', 'url', 'key_file', 'stream_options', 'params', 'description', 'context_length'];
    private const UPSTREAM_KEYS = ['url', 'key_file', 'stream_options'];
    private const ROUTE_KEYS = ['upstream', 'model', 'params', 'cost'];

    public readonly string $dbPath;
    public readonly ?string $upstreamUrl;
    public readonly ?string $upstreamKeyFile;
    /** Seconds one request may spend upstream, all attempts and failovers together. */
    public readonly int $upstreamTimeout;
    /** @var array<string, array{model: string, input: int, cached_input: int, output: int}> alias => its first upstream model id and its rates (credits per million tokens) */
    public readonly array $models;
    /** @var array<string, array{description: string, context_length: ?int}> alias => what /v1/models shows about it besides its rates */
    public readonly array $info;
    /**
     * @var array<string, list<array{url: string, key_file: string, stream_options: array<string, mixed>, model: string, params: array<string, mixed>, cost: ?array{input: int, cached_input: int, output: int}}>>
     * alias => its upstreams in failover order: where each request goes, the model id it asks for and the request fields set there
     */
    public readonly array $routes;
    public readonly int $retries;
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
    /** Soft throttling and per-key priority weights (the optional `throttle` block; see Throttle). */
    public readonly Throttle $throttle;
    /** Spend windows a cap may be set for, in the order they are checked. */
    public const CAP_WINDOWS = ['hour', 'day', 'month'];
    /**
     * Server-wide hard caps (the optional `caps` block): upstream spend per UTC hour, day and month, in millionths of the
     * upstream currency unit (null = no cap, 0 = nothing may start), and requests started per 60 seconds across all keys.
     * @var array{spend: array<string, ?int>, requests_per_minute: ?int}
     */
    public readonly array $caps;
    /**
     * alias => the highest price any of its upstreams charges, per field (millionths per million tokens), or null when an
     * upstream of the alias has no 'cost'. A request's cost bound for the spend caps is priced at these.
     * @var array<string, ?array{input: int, cached_input: int, output: int}>
     */
    public readonly array $costBounds;

    /** @param array<string, mixed> $c */
    public function __construct(array $c)
    {
        self::known($c, self::KEYS, '');
        $this->dbPath = self::str($c, 'db_path');
        // The global upstream is optional once every model names its upstreams; when given it needs both parts.
        $hasGlobal = isset($c['upstream_url']) || isset($c['upstream_key_file']);
        $this->upstreamUrl = $hasGlobal ? self::url($c, 'upstream_url') : null;
        $this->upstreamKeyFile = $hasGlobal ? self::str($c, 'upstream_key_file') : null;
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

        $this->throttle = Throttle::fromConfig($c['throttle'] ?? null);
        $this->streamOptions = self::streamOptions($c, 'stream_options');
        $retries = $c['retries'] ?? 1;
        if (!is_int($retries) || $retries < 0 || $retries > self::MAX_RETRIES) {
            throw new \InvalidArgumentException('config: retries must be an integer from 0 to ' . self::MAX_RETRIES);
        }
        $this->retries = $retries;
        $upstreams = $this->upstreams($c);

        $models = $c['models'] ?? null;
        if (!is_array($models) || $models === []) {
            throw new \InvalidArgumentException('config: models must be a non-empty map of alias => rates and upstreams');
        }
        $parsed = $routes = $info = [];
        foreach ($models as $alias => $m) {
            if (!is_string($alias) || $alias === '' || !is_array($m)) {
                throw new \InvalidArgumentException('config: each models entry needs a non-empty alias and a settings array');
            }
            self::known($m, self::MODEL_KEYS, "models.$alias.");
            $rate = static fn (string $k, int $min): int => is_int($m[$k] ?? null) && $m[$k] >= $min && $m[$k] <= Credits::MAX_RATE ? $m[$k]
                : throw new \InvalidArgumentException("config: models.$alias.$k must be an integer from $min to " . Credits::MAX_RATE);
            $hops = $this->hops($alias, $m, $upstreams, self::params($m, "models.$alias."));
            $p = ['model' => $hops[0]['model'], 'input' => $rate('input', 1), 'cached_input' => $rate('cached_input', 0), 'output' => $rate('output', 1)];
            // Reservations price the whole prompt at the input rate; that bounds the charge only if cached input costs no more.
            if ($p['cached_input'] > $p['input']) {
                throw new \InvalidArgumentException("config: models.$alias.cached_input must not exceed input");
            }
            $d = $m['description'] ?? '';
            if (!is_string($d) || strlen($d) > 500) {
                throw new \InvalidArgumentException("config: models.$alias.description must be a string of at most 500 bytes");
            }
            $ctx = $m['context_length'] ?? null;
            if ($ctx !== null && (!is_int($ctx) || $ctx < 1)) {
                throw new \InvalidArgumentException("config: models.$alias.context_length must be a positive integer (or unset)");
            }
            [$parsed[$alias], $routes[$alias], $info[$alias]] = [$p, $hops, ['description' => $d, 'context_length' => $ctx]];
        }
        $this->models = $parsed;
        $this->routes = $routes;
        $this->info = $info;
        $this->caps = self::caps($c['caps'] ?? null);
        $bounds = [];
        foreach ($routes as $alias => $hops) {
            $bounds[$alias] = null;
            foreach ($hops as $i => $h) {
                if ($h['cost'] === null) {
                    // A spend cap only binds requests it can price: with a cap set, every upstream must carry its prices.
                    if (array_filter($this->caps['spend'], static fn (?int $v): bool => $v !== null) !== []) {
                        throw new \InvalidArgumentException("config: caps needs a cost on every route entry; models.$alias.route.$i has none (a model without a route cannot have one: give it a route)");
                    }
                    $bounds[$alias] = null;
                    break;
                }
                foreach ($h['cost'] as $field => $price) {
                    $bounds[$alias][$field] = max($bounds[$alias][$field] ?? 0, $price);
                }
            }
        }
        $this->costBounds = $bounds;

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

    /**
     * Where a model alias's requests go on its $hop-th upstream (0 = the first), with that upstream's own
     * key read from its own key file at request time. A missing key fails this upstream; it never borrows another's.
     * @return array{url: string, key: string, stream_options: array<string, mixed>, model: string, params: array<string, mixed>}
     */
    public function route(string $alias, int $hop = 0): array
    {
        $r = $this->routes[$alias][$hop] ?? throw new \InvalidArgumentException("unknown model alias $alias or upstream $hop");
        $key = is_readable($r['key_file']) ? trim((string) file_get_contents($r['key_file'])) : '';
        if ($key === '') {
            throw new \RuntimeException('upstream key file missing or empty');
        }
        return ['url' => $r['url'], 'key' => $key, 'stream_options' => $r['stream_options'], 'model' => $r['model'], 'params' => $r['params']];
    }

    /**
     * The named upstreams: name => url, key_file and stream_options. The global upstream_url and
     * upstream_key_file, when set, are the upstream that models without a route of their own use.
     * @param array<string, mixed> $c
     * @return array<string, array{url: string, key_file: string, stream_options: array<string, mixed>}>
     */
    private function upstreams(array $c): array
    {
        $list = $c['upstreams'] ?? [];
        if (!is_array($list)) {
            throw new \InvalidArgumentException('config: upstreams must be a map of name => url and key_file');
        }
        $out = [];
        foreach ($list as $name => $u) {
            if (!is_string($name) || $name === '' || !is_array($u)) {
                throw new \InvalidArgumentException('config: each upstreams entry needs a non-empty name and a settings array');
            }
            self::known($u, self::UPSTREAM_KEYS, "upstreams.$name.");
            $out[$name] = ['url' => self::url($u, 'url', "upstreams.$name."), 'key_file' => self::str($u, 'key_file', "upstreams.$name."),
                'stream_options' => isset($u['stream_options']) ? self::streamOptions($u, 'stream_options', "upstreams.$name.") : $this->streamOptions];
        }
        return $out;
    }

    /**
     * A route entry's optional `cost`: what the upstream charges, in millionths of its own currency unit per million
     * tokens (fresh input, cached input, output). Used only to record a cost for requests whose upstream reports none.
     * @param array<string, mixed> $h
     * @return ?array{input: int, cached_input: int, output: int}
     */
    private static function cost(array $h, string $at): ?array
    {
        if (!array_key_exists('cost', $h)) {
            return null;
        }
        $c = $h['cost'];
        if (!is_array($c) || count($c) !== 3 || array_diff(['input', 'cached_input', 'output'], array_keys($c)) !== []) {
            throw new \InvalidArgumentException("config: {$at}cost must set exactly input, cached_input and output");
        }
        foreach ($c as $k => $v) {
            if (!is_int($v) || $v < 0 || $v > Credits::MAX_RATE) {
                throw new \InvalidArgumentException("config: {$at}cost.$k must be an integer from 0 to " . Credits::MAX_RATE);
            }
        }
        return ['input' => $c['input'], 'cached_input' => $c['cached_input'], 'output' => $c['output']];
    }

    /**
     * A model's upstreams in failover order. A model either lists them ('route': upstream name, model id and
     * optional params per entry), or names one model id served by its own url and key_file, or by the global upstream.
     * Params are merged per entry: the model's, then the entry's on top.
     * @param array<string, mixed> $m
     * @param array<string, array{url: string, key_file: string, stream_options: array<string, mixed>}> $upstreams
     * @param array<string, mixed> $params
     * @return list<array{url: string, key_file: string, stream_options: array<string, mixed>, model: string, params: array<string, mixed>, cost: ?array{input: int, cached_input: int, output: int}}>
     */
    private function hops(string $alias, array $m, array $upstreams, array $params): array
    {
        if (isset($m['route'])) {
            if (isset($m['model']) || isset($m['url']) || isset($m['key_file']) || isset($m['stream_options'])) {
                throw new \InvalidArgumentException("config: models.$alias has a route, so its model belongs in the route entries and url, key_file and stream_options in upstreams");
            }
            if (!is_array($m['route']) || $m['route'] === [] || !array_is_list($m['route'])) {
                throw new \InvalidArgumentException("config: models.$alias.route must be a non-empty list of upstream and model entries");
            }
            $hops = [];
            foreach ($m['route'] as $i => $h) {
                $at = "models.$alias.route.$i.";
                if (!is_array($h) || !is_string($h['upstream'] ?? null) || !isset($upstreams[$h['upstream']])) {
                    throw new \InvalidArgumentException("config: {$at}upstream must name an entry of upstreams");
                }
                self::known($h, self::ROUTE_KEYS, $at);
                $hops[] = $upstreams[$h['upstream']] + ['model' => self::str($h, 'model', $at), 'params' => self::params($h, $at) + $params, 'cost' => self::cost($h, $at)];
            }
            return $hops;
        }
        if (!is_string($m['model'] ?? null) || $m['model'] === '') {
            throw new \InvalidArgumentException("config: models.$alias needs a model string or a route");
        }
        if (isset($m['url'])) {
            // A model with its own url must name its own key file too: the global key never goes to another upstream.
            if (!isset($m['key_file'])) {
                throw new \InvalidArgumentException("config: models.$alias.url needs models.$alias.key_file as well");
            }
            $u = ['url' => self::url($m, 'url', "models.$alias."), 'key_file' => self::str($m, 'key_file', "models.$alias.")];
        } elseif ($this->upstreamUrl !== null && $this->upstreamKeyFile !== null) {
            $u = ['url' => $this->upstreamUrl, 'key_file' => isset($m['key_file']) ? self::str($m, 'key_file', "models.$alias.") : $this->upstreamKeyFile];
        } else {
            throw new \InvalidArgumentException("config: models.$alias has no upstream: give it a route, its own url and key_file, or set upstream_url");
        }
        $u['stream_options'] = isset($m['stream_options']) ? self::streamOptions($m, 'stream_options', "models.$alias.") : $this->streamOptions;
        return [$u + ['model' => $m['model'], 'params' => $params, 'cost' => null]];
    }

    /**
     * Request fields a model (or one of its upstreams) always sends, such as a reasoning setting; they
     * override what the client sent. Fields the proxy owns are refused.
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function params(array $c, string $prefix): array
    {
        $p = $c['params'] ?? [];
        if (!is_array($p) || ($p !== [] && array_is_list($p))) {
            throw new \InvalidArgumentException("config: {$prefix}params must be a map of request field => value");
        }
        foreach ($p as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, self::PARAMS_RESERVED, true) || json_encode($v) === false) {
                throw new \InvalidArgumentException("config: {$prefix}params.$k is not allowed (reserved: " . implode(', ', self::PARAMS_RESERVED) . ')');
            }
        }
        return $p;
    }

    /**
     * Refuses a setting this level does not have, naming it and the settings it may have.
     * @param array<mixed> $c
     * @param list<string> $allowed
     */
    private static function known(array $c, array $allowed, string $prefix): void
    {
        foreach (array_keys($c) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw new \InvalidArgumentException("config: {$prefix}$k is not a setting here (a typo?); " . ($prefix === '' ? 'top level' : rtrim($prefix, '.')) . ' may set ' . implode(', ', $allowed));
            }
        }
    }

    /** @param array<string, mixed> $c */
    private static function str(array $c, string $k, string $prefix = ''): string
    {
        if (!isset($c[$k]) || !is_string($c[$k]) || $c[$k] === '') {
            throw new \InvalidArgumentException("config: $prefix$k must be a non-empty string");
        }
        return $c[$k];
    }

    /**
     * An upstream URL: https, or plain http only to this machine (a local gateway, or the tests),
     * so the upstream key never crosses a network in the clear.
     * @param array<string, mixed> $c
     */
    private static function url(array $c, string $k, string $prefix = ''): string
    {
        $u = self::str($c, $k, $prefix);
        $scheme = strtolower((string) parse_url($u, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', '[::1]', 'localhost'], true)))) {
            throw new \InvalidArgumentException("config: $prefix$k must be an https URL (plain http only to 127.0.0.1, ::1 or localhost)");
        }
        return $u;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function streamOptions(array $c, string $k, string $prefix = ''): array
    {
        $opts = $c[$k] ?? ['include_usage' => true];
        if (!is_array($opts) || ($opts['include_usage'] ?? false) !== true) {
            throw new \InvalidArgumentException("config: $prefix$k must include include_usage => true");
        }
        return $opts;
    }

    /**
     * The `caps` block: hour, day and month in plain upstream currency units (5 means 5.00, 0 stops everything),
     * and requests_per_minute across all keys. Every setting is optional; an absent block sets no cap.
     * @return array{spend: array<string, ?int>, requests_per_minute: ?int}
     */
    private static function caps(mixed $c): array
    {
        $c ??= [];
        if (!is_array($c) || array_diff(array_keys($c), [...self::CAP_WINDOWS, 'requests_per_minute']) !== []) {
            throw new \InvalidArgumentException('config: caps may set only ' . implode(', ', self::CAP_WINDOWS) . ' and requests_per_minute');
        }
        $spend = [];
        foreach (self::CAP_WINDOWS as $w) {
            $v = $c[$w] ?? null;
            if ($v !== null && ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < 0 || $v > 1_000_000_000)) {
                throw new \InvalidArgumentException("config: caps.$w must be a number from 0 to 1000000000 (upstream currency units), or unset");
            }
            $spend[$w] = $v === null ? null : (int) round($v * 1_000_000);
        }
        return ['spend' => $spend, 'requests_per_minute' => self::limit($c, 'requests_per_minute', 'caps.')];
    }

    /** @param array<string, mixed> $c */
    private static function limit(array $c, string $k, string $prefix = ''): ?int
    {
        $v = $c[$k] ?? null;
        if ($v !== null && (!is_int($v) || $v < 1 || $v > Ledger::MAX_GRANT)) {
            throw new \InvalidArgumentException("config: $prefix$k must be a positive integer of at most " . Ledger::MAX_GRANT . ' (or unset for no limit)');
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
