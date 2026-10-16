<?php

declare(strict_types=1);

// The smallest client: one question, one answer, and what it cost. Needs ext-curl and ext-json.
//   VIRSILIPAS_URL=https://proxy.example VIRSILIPAS_KEY=... php hello-world.php "Say hello"
// VIRSILIPAS_MODEL picks another alias from /v1/models (default: default).

$url = rtrim((string) getenv('VIRSILIPAS_URL'), '/') . '/v1/chat/completions';
if (!preg_match('#^(https://|http://(127\.0\.0\.1|localhost)[:/])#', $url)) {
    fwrite(STDERR, "error: VIRSILIPAS_URL must be https (the key travels with the request)\n");
    exit(2);
}
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
echo preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $reply['choices'][0]['message']['content']), "\n"; // no terminal control codes
echo 'credits: ', $reply['usage']['credits'] ?? '?', "\n";
