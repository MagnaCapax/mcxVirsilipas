<?php

declare(strict_types=1);

// A stand-in OpenAI-compatible upstream for the integration tests (run with php -S).
// The upstream model id picks the scenario. Usage objects carry a private extra field and a
// nested private detail, which the proxy must not pass on.

while (ob_get_level() > 0) {
    ob_end_flush(); // php.ini output_buffering would otherwise hold the whole stream until the end
}

$req = json_decode((string) file_get_contents('php://input'), true) ?: [];
$dir = getenv('FAKE_UPSTREAM_DIR') ?: sys_get_temp_dir();
// Only the fields the tests assert on; never the messages.
$seen = array_intersect_key($req, array_flip(['model', 'n', 'best_of', 'store', 'modalities', 'stream', 'stream_options', 'max_tokens']));
file_put_contents($dir . '/last-request.json', json_encode($seen));

$model = (string) ($req['model'] ?? '');
$chunks = 10;
$prompt = 11;
$usage = static fn (int $completion): array => [
    'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion,
    'estimated_cost' => 0.0001, 'prompt_tokens_details' => ['cached_tokens' => 0, 'private_account' => 'PRIVATE-DETAIL'],
];
$event = static function (array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
};

switch ($model) {
    case 'scenario-429':
        http_response_code(429);
        header('Content-Type: application/json');
        echo '{"error":{"message":"private upstream detail: capacity exceeded for account 42"}}';
        exit;
    case 'scenario-redirect':
        http_response_code(302);
        header('Location: /elsewhere');
        exit;
    case 'scenario-json-error':
        header('Content-Type: application/json');
        echo '{"error":{"message":"private upstream detail: refused"}}';
        exit;
    case 'scenario-empty-choices':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [], 'usage' => $usage(0)]);
        exit;
    case 'scenario-top-private':
        header('Content-Type: application/json');
        echo json_encode(['id' => 'x', 'provider' => 'PRIVATE-DETAIL', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hi', 'provider_note' => 'PRIVATE-DETAIL']]], 'usage' => $usage(1)]);
        exit;
    case 'scenario-done-only':
        header('Content-Type: text/event-stream');
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-stale-usage':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'first']]], 'usage' => $usage(1)]);
        $event(['choices' => [['index' => 0, 'delta' => ['content' => str_repeat('more ', 1000)]]]]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-null-choice':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [null], 'usage' => $usage(1)]);
        exit;
    case 'scenario-usage-drop':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'a']]], 'usage' => $usage(50)]);
        $event(['choices' => [], 'usage' => $usage(3)]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-truncated':
        header('Content-Type: application/json');
        header('Content-Length: 100000');
        echo '{"choices":[{"message":{"content":"partial';
        exit;
}

if (empty($req['stream'])) {
    header('Content-Type: application/json');
    $body = [
        'id' => 'x', 'object' => 'chat.completion', 'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hello world'], 'finish_reason' => 'stop']],
        'usage' => $model === 'scenario-empty-usage' ? [] : $usage(7),
    ];
    echo json_encode($body);
    exit;
}

header('Content-Type: text/event-stream');
$runaway = $model === 'scenario-runaway';
$continuous = ($req['stream_options']['continuous_usage_stats'] ?? false) === true
    && !in_array($model, ['scenario-final-usage', 'scenario-big-delta', 'scenario-empty-usage'], true);
$delayUs = in_array($model, ['scenario-slow', 'scenario-big-delta'], true) ? 200000 : 20000;
for ($i = 1; $i <= $chunks; $i++) {
    $text = $model === 'scenario-big-delta' && $i === 1 ? str_repeat('word ', 2000) : "t$i ";
    $c = ['id' => 'x', 'object' => 'chat.completion.chunk', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => $text]]]];
    if ($continuous) {
        $c['usage'] = $usage($runaway ? 50 * $i : $i);
    }
    $event($c);
    if ($model === 'scenario-no-done') {
        exit; // one event, then the connection closes without usage or [DONE]
    }
    usleep($delayUs);
}
$event(['id' => 'x', 'object' => 'chat.completion.chunk', 'model' => $model, 'choices' => [],
    'usage' => $model === 'scenario-empty-usage' ? [] : $usage($chunks)]);
echo "data: [DONE]\n\n";
