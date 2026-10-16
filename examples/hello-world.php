<?php

declare(strict_types=1);

// The smallest client: one question, one answer, and what it cost. Needs ext-curl and ext-json.
//   VIRSILIPAS_URL=https://proxy.example VIRSILIPAS_KEY=... php hello-world.php "Say hello"
// VIRSILIPAS_MODEL picks another alias from /v1/models (default: default).

$url = rtrim((string) getenv('VIRSILIPAS_URL'), '/') . '/v1/chat/completions';
$body = ['model' => getenv('VIRSILIPAS_MODEL') ?: 'default', 'messages' => [['role' => 'user', 'content' => $argv[1] ?? 'Say hello in one sentence.']]];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . getenv('VIRSILIPAS_KEY')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
]);
$reply = json_decode((string) curl_exec($ch), true);

if (!isset($reply['choices'][0]['message']['content'])) {
    fwrite(STDERR, 'error: ' . ($reply['error']['message'] ?? curl_error($ch) ?: 'no answer') . "\n");
    exit(1);
}
echo $reply['choices'][0]['message']['content'], "\n";
echo 'credits: ', $reply['usage']['credits'] ?? '?', "\n";
