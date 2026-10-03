<?php

// Copy to config.php (or point VIRSILIPAS_CONFIG at your copy) and keep it out of version control.
return [
    // SQLite database file. The directory must be writable by the PHP process (WAL needs it).
    'db_path' => '/var/lib/virsilipas/ledger.sqlite',

    // Any OpenAI-compatible chat completions endpoint.
    'upstream_url' => 'https://llm.example.com/v1/chat/completions',

    // File holding only the upstream API key. Readable by the PHP process, by nobody else.
    'upstream_key_file' => '/etc/virsilipas/upstream.key',

    // Seconds before an upstream request is cut off.
    'upstream_timeout' => 600,

    // The models customers may use: alias they send => model id sent upstream.
    'models' => [
        'default' => 'example-org/example-model',
    ],

    // Upper bound on max_tokens per request. Requests are also clamped to the remaining balance.
    'max_tokens_cap' => 16384,

    // In-flight requests allowed per key. Keeps one key from using up a shared upstream limit.
    'max_concurrent_per_key' => 4,

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
