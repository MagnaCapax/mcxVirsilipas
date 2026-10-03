<?php

declare(strict_types=1);

// A stand-in OpenAI-compatible upstream for the integration tests (run with php -S).
// The upstream model id picks the scenario; the last request body is saved for assertions.

while (ob_get_level() > 0) {
    ob_end_flush(); // php.ini output_buffering would otherwise hold the whole stream until the end
}

$req = json_decode((string) file_get_contents('php://input'), true) ?: [];
$dir = getenv('FAKE_UPSTREAM_DIR') ?: sys_get_temp_dir();
file_put_contents($dir . '/last-request.json', json_encode($req));

$model = (string) ($req['model'] ?? '');
$chunks = 10;
$prompt = 11;

if ($model === 'scenario-429') {
    http_response_code(429);
    header('Content-Type: application/json');
    echo '{"error":{"message":"SecretVendor capacity exceeded for account 42"}}';
    exit;
}

if (empty($req['stream'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'x', 'object' => 'chat.completion', 'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hello world'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => 7, 'total_tokens' => $prompt + 7, 'estimated_cost' => 0.0001],
    ]);
    exit;
}

header('Content-Type: text/event-stream');
$continuous = ($req['stream_options']['continuous_usage_stats'] ?? false) === true && $model !== 'scenario-final-usage';
$delayUs = $model === 'scenario-slow' ? 200000 : 20000;
for ($i = 1; $i <= $chunks; $i++) {
    $c = ['id' => 'x', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => "t$i "]]]];
    if ($continuous) {
        $c['usage'] = ['prompt_tokens' => $prompt, 'completion_tokens' => $i, 'total_tokens' => $prompt + $i];
    }
    echo 'data: ' . json_encode($c) . "\n\n";
    flush();
    usleep($delayUs);
}
echo 'data: ' . json_encode(['id' => 'x', 'object' => 'chat.completion.chunk', 'choices' => [],
    'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $chunks, 'total_tokens' => $prompt + $chunks, 'estimated_cost' => 0.0001]]) . "\n\n";
echo "data: [DONE]\n\n";
