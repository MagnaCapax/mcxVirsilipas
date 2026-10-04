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
    case 'scenario-split-shift':
        // A repeat of the same total that moves output tokens over to the prompt side: not a detail, a different split.
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'twenty tokens']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]]);
        $event(['choices' => [], 'usage' => ['prompt_tokens' => 29, 'completion_tokens' => 1, 'total_tokens' => 30]]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-cached':
        // 1000 prompt tokens of which 800 were read from the provider's cache, 50 output tokens.
        $u = ['prompt_tokens' => 1000, 'completion_tokens' => 50, 'total_tokens' => 1050, 'estimated_cost' => 0.0001,
            'prompt_tokens_details' => ['cached_tokens' => 800, 'private_account' => 'PRIVATE-DETAIL']];
        if (!empty($req['stream'])) {
            header('Content-Type: text/event-stream');
            // Like real upstreams with running usage: the running reports carry the counts only, and the
            // cached split arrives with the final report, whose counts are the same.
            $event(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'cached hello']]],
                'usage' => array_diff_key($u, ['prompt_tokens_details' => 1])]);
            $event(['choices' => [], 'usage' => $u]);
            // A further repeat without the detail must not take the cached split away again.
            $event(['choices' => [], 'usage' => array_diff_key($u, ['prompt_tokens_details' => 1])]);
            // Nor may a repeat with a different cached count change it.
            $event(['choices' => [], 'usage' => ['prompt_tokens_details' => ['cached_tokens' => 1000]] + $u]);
            echo "data: [DONE]\n\n";
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['id' => 'c', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'cached hello']]], 'usage' => $u]);
        exit;
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
    case 'scenario-equal-usage':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'a']]], 'usage' => $usage(50)]);
        $event(['choices' => [['index' => 0, 'delta' => ['content' => str_repeat('more ', 1000)]]]]);
        $event(['choices' => [], 'usage' => $usage(50)]); // repeats the old count after 5 kB more output
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-negative-total':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => str_repeat('x', 5000)]]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => -1]]);
        exit;
    case 'scenario-empty-choice':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [new stdClass()], 'usage' => $usage(1)]);
        exit;
    case 'scenario-empty-tool-call':
        if (empty($req['stream'])) {
            header('Content-Type: application/json');
            echo '{"choices":[{"message":{"tool_calls":[[]]}}],"usage":' . json_encode($usage(1)) . '}';
            exit;
        }
        header('Content-Type: text/event-stream');
        echo 'data: {"choices":[{"index":0,"delta":{"tool_calls":[[]]}}],"usage":' . json_encode($usage(1)) . "}\n\n";
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-list-message':
        header('Content-Type: application/json');
        echo '{"choices":[{"message":[]},{"index":1,"message":{}}],"usage":' . json_encode($usage(1)) . '}';
        exit;
    case 'scenario-typed-private':
        header('Content-Type: application/json');
        echo json_encode(['id' => ['PRIVATE-DETAIL'], 'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
            'content' => ['private_account' => 'PRIVATE-DETAIL'],
            'tool_calls' => [['index' => 0, 'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => ['note' => 'PRIVATE-DETAIL']]],
                ['index' => 1, 'id' => 'c2', 'type' => 'function', 'function' => ['name' => 'g', 'arguments' => '{}']]]],
            'finish_reason' => 'tool_calls']], 'usage' => $usage(1)]);
        exit;
    case 'scenario-incomplete-message':
        header('Content-Type: application/json');
        // Two choices, neither a usable answer: a partial tool call and null-only content.
        echo '{"choices":[{"index":0,"message":{"tool_calls":[{"index":0}]}},{"index":1,"message":{"role":"assistant","content":null}}],"usage":' . json_encode($usage(1)) . '}';
        exit;
    case 'scenario-blank-tool':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [
            ['index' => 0, 'message' => ['tool_calls' => [['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']]]]],
            ['index' => 1, 'message' => ['tool_calls' => [['id' => 'c', 'type' => 'other', 'function' => ['name' => 'f', 'arguments' => '{}']]]]],
        ], 'usage' => $usage(1)]);
        exit;
    case 'scenario-stream-message':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hi']]], 'usage' => $usage(1)]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-invalid-prelude':
        header('Content-Type: text/event-stream');
        $event(['choices' => ['a' => ['index' => 0, 'delta' => ['content' => str_repeat('X', 1000)]]]]); // not a list: dropped
        $event(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant']]]]);
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'ok']]]]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-done-hang':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'ok']]], 'usage' => $usage(1)]);
        echo "data: [DONE]\n\n";
        flush();
        sleep(3); // an upstream that keeps the connection open after its terminal marker
        exit;
    case 'scenario-stream-message-bytes':
        header('Content-Type: text/event-stream');
        // A role delta plus 4 kB in a message object, and no usage: the relayed message text must count.
        $event(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'message' => ['content' => str_repeat('M', 4000)]]]]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-private-bytes':
        header('Content-Type: application/json');
        echo json_encode(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok', 'provider_trace' => str_repeat('P', 4000)]]]]);
        exit;
    case 'scenario-over-batch':
        header('Content-Type: text/event-stream');
        // One write: an event reporting usage past the reservation, then more output.
        echo 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'a']]], 'usage' => $usage(400)]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => str_repeat('W', 4000)]]]]) . "\n\n"
            . "data: [DONE]\n\n";
        exit;
    case 'scenario-error-after-start':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'partial']]], 'usage' => $usage(1)]);
        usleep(300000);
        $event(['error' => ['message' => 'PRIVATE-DETAIL: upstream failed mid-stream']]);
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-named-error':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'partial']]], 'usage' => $usage(1)]);
        usleep(300000);
        echo "event: error\ndata: {\"message\":\"PRIVATE-DETAIL\"}\n\n";
        echo "data: [DONE]\n\n";
        exit;
    case 'scenario-error-batched':
        header('Content-Type: text/event-stream');
        echo 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => str_repeat('V', 4000)]]]]) . "\n\n"
            . 'data: ' . json_encode(['error' => ['message' => 'PRIVATE-DETAIL']]) . "\n\n" . "data: [DONE]\n\n";
        exit;
    case 'scenario-done-first':
        header('Content-Type: text/event-stream');
        echo "data: [DONE]\n\n" . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'late']]], 'usage' => $usage(1)]) . "\n\n";
        exit;
    case 'scenario-after-done':
        header('Content-Type: text/event-stream');
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'first']]], 'usage' => $usage(1)]);
        echo "data: [DONE]\n\n";
        $event(['choices' => [['index' => 0, 'delta' => ['content' => 'AFTER-DONE']]], 'usage' => $usage(2)]);
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
        // Counts only: the total this upstream has generated so far, for the hang-up test.
        file_put_contents($dir . '/generated-total.txt', (string) $c['usage']['total_tokens']);
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
