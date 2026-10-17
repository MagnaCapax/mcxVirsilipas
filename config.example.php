<?php

// Copy to config.php (or point VIRSILIPAS_CONFIG at your copy) and keep it out of version control.
return [
    // SQLite database file. The directory must be writable by the PHP process (WAL needs it).
    'db_path' => '/var/lib/virsilipas/ledger.sqlite',

    // The upstreams: name => any OpenAI-compatible chat completions endpoint (https, or plain http only to 127.0.0.1,
    // ::1 or localhost) and the file holding only its API key (readable by the PHP process, by nobody else). Each
    // upstream's key goes to that upstream only. 'stream_options' may be set per upstream (default: the one below).
    'upstreams' => [
        'main' => ['url' => 'https://llm.example.com/v1/chat/completions', 'key_file' => '/etc/virsilipas/main.key'],
        'spare' => ['url' => 'https://other.example.com/v1/chat/completions', 'key_file' => '/etc/virsilipas/spare.key'],
    ],

    // Seconds one request may spend upstream: every attempt, retry and failover together.
    'upstream_timeout' => 600,

    // Extra attempts on the same upstream (0 to 2) after a failure that cost nothing there: the connection failed, or
    // the upstream answered 408, 409, 429, 502, 503 or 504 before doing any work. The wait between them is the
    // upstream's Retry-After (up to 60 s), else 0.5 s, then 1 s. Nothing is retried once output may have been generated.
    'retries' => 1,

    // The models customers may use: alias they send => its rates in credits per million tokens (fresh prompt tokens,
    // prompt tokens served from the upstream's cache, at most the input rate, and output tokens, reasoning included;
    // integers, the values below are only an example) and its 'route': the upstreams that serve it, in failover order,
    // each with the model id sent there. The route's length is the failover depth. A request moves to the next entry
    // when an upstream fails before answering (its retries used up), is down, or rejects its own key (401, 403) or
    // the model id (404); never once an answer has started, and never for a request every upstream would reject.
    // The customer pays the alias's rates whichever upstream answers.
    // Optional per model: 'description' and 'context_length' (shown in /v1/models), and 'params': request fields
    // always sent for this model, overriding the client (for example a fixed reasoning level). A route entry may add
    // 'params' of its own on top, for upstreams that switch reasoning differently. The proxy's own fields (model,
    // messages, max_tokens, max_completion_tokens, stream, stream_options, n) cannot be set this way.
    // 'params' are yours alone: they may hold any JSON value the upstream accepts, objects included, and a client can
    // neither send nor override them (only the request fields listed in docs/API.md are forwarded, and 'params' win).
    'models' => [
        'default' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000, 'description' => 'fast, everyday tasks',
            'route' => [['upstream' => 'main', 'model' => 'example-org/example-model'],
                // 'cost': this upstream's own prices in millionths of its currency unit per million tokens (0.30 = 300000),
                // recorded as the usage row's upstream cost when the upstream reports none itself. Never shown to clients.
                ['upstream' => 'spare', 'model' => 'example-org/example-model', 'cost' => ['input' => 300000, 'cached_input' => 30000, 'output' => 1200000]]]],
        // 'default-think' => ['input' => 1000000, 'cached_input' => 250000, 'output' => 4000000, 'params' => ['reasoning_effort' => 'high'],
        //     'route' => [['upstream' => 'main', 'model' => 'example-org/example-model']]],
    ],

    // Older single-upstream form, still accepted: a global 'upstream_url' and 'upstream_key_file', and models naming
    // only 'model' (or their own 'url' with their own 'key_file'). Such a model has a one-entry route.
    // 'upstream_url' => 'https://llm.example.com/v1/chat/completions',
    // 'upstream_key_file' => '/etc/virsilipas/upstream.key',

    // Upper bound on max_tokens per request (at most 10^9). Requests are also clamped to the remaining balance.
    'max_tokens_cap' => 16384,

    // Default per-key limits; POST /admin/keys/limits (or bin/virsilipas limit) sets a key's own values.
    // In-flight requests allowed per key. Keeps one key from using up a shared upstream limit.
    'max_concurrent_per_key' => 4,
    // Requests and credits per key over the last 60 seconds. Unset means no limit.
    // 'requests_per_minute' => 60,
    // 'credits_per_minute' => 2000000,

    // Optional soft throttling (see README, Priority and throttling). Leave out to turn it off. It never refuses what
    // the limits above allow, except busy shedding: a 503 with Retry-After for low-weight keys while the server is busy.
    // 'throttle' => [
    //     // Credits charged across all keys over the last 24 hours. Past pace_start of it, requests wait before they
    //     // start: base_delay_ms x pressure x 100 / weight, at most max_delay_ms. Unset means no pacing.
    //     'daily_budget' => 50000000,
    //     'pace_start' => 0.8,
    //     'base_delay_ms' => 1000,
    //     'max_delay_ms' => 3000,
    //     // Requests in flight per source at which keys below busy_min_weight are told to retry (keep below each
    //     // source's php-fpm pm.max_children). The web server sets the source: fastcgi_param VIRSILIPAS_SOURCE internal.
    //     'busy' => ['internal' => 36, 'external' => 24],
    //     'busy_min_weight' => 100,
    //     // Weight multiplier for internal callers, and the credits that count as one unit of money in the weight.
    //     'internal_factor' => 2,
    //     'credits_per_unit' => 1000000,
    // ],
    // Optional hard caps for the whole server (see README, Caps). Leave out for none. Spend is the upstream's cost in its
    // currency unit (1 = 1.00) per UTC hour, day and calendar month; 0 stops every request. With any spend cap set, every
    // route entry must carry a 'cost'. requests_per_minute counts requests started across all keys in the last 60 seconds.
    // 'caps' => [
    //     'hour' => 1,
    //     'day' => 5,
    //     'month' => 50,
    //     'requests_per_minute' => 600,
    // ],
    // How long a reservation survives if its worker dies. Must exceed upstream_timeout.
    'lease_seconds' => 900,

    // Merged into every streaming request. include_usage is required; add provider-specific
    // options here, e.g. 'continuous_usage_stats' => true on upstreams that support it, so a
    // client that hangs up is charged by the upstream's own running count, not an upper bound.
    'stream_options' => ['include_usage' => true],

    // Operational log: one JSONL file per UTC day (YYYYMMDD.jsonl) in this directory, private to the PHP user.
    // Leave unset to send log lines to PHP's error_log instead. Records hold counts, statuses and billing
    // references only, never request or response bodies, keys or upstream error text.
    // 'log_dir' => '/var/log/virsilipas',
    // error | warn (upstream failures and billing anomalies) | info (+ every request and admin action) | debug
    'log_level' => 'warn',

    // sha256 hex of the admin token used by billing: printf %s "$TOKEN" | sha256sum
    'admin_token_sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
];
