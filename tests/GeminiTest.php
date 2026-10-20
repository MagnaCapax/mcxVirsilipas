<?php

declare(strict_types=1);

use MagnaCapax\Virsilipas\Gemini;
use MagnaCapax\Virsilipas\Proxy;

// POST /v1beta/models/{model}:generateContent, :streamGenerateContent and :countTokens: the Gemini API format,
// translated to chat completions on the alias's route and back, metered exactly like chat.

function gemBody(array $extra = []): array
{
    return $extra + ['contents' => [['role' => 'user', 'parts' => [['text' => 'Say hello.']]]]];
}

/** A Gemini-format request; the key goes as x-goog-api-key unless $headers say otherwise. */
function gem(string $model, string $method, ?array $body, ?string $key, array $headers = [], string $query = ''): array
{
    if ($key !== null) {
        $headers[] = "x-goog-api-key: $key";
    }
    return http('POST', "/v1beta/models/$model:$method$query", $body, null, 0, $headers);
}

/** The error object of a Gemini-format error answer, after checking the shape. */
function gemError(array $r): array
{
    $j = json_decode($r['body'], true);
    check(is_array($j) && array_keys($j) === ['error'] && array_keys($j['error']) === ['code', 'message', 'status']
        && $j['error']['code'] === $r['status'] && is_string($j['error']['message']) && is_string($j['error']['status']), 'Gemini error shape: ' . $r['body']);
    return $j['error'];
}

/** The data objects of a Gemini SSE stream. */
function gemChunks(string $body): array
{
    $out = [];
    foreach (preg_split('/\r\n\r\n/', trim($body)) ?: [] as $chunk) {
        check(str_starts_with($chunk, 'data: '), "SSE chunk: $chunk");
        $out[] = json_decode(substr($chunk, 6), true);
    }
    return $out;
}

/** The function declarations and the conversation of one function call round trip. */
function gemToolBody(array $extra = []): array
{
    return $extra + ['systemInstruction' => ['role' => 'user', 'parts' => [['text' => 'You are a CLI agent.']]],
        'tools' => [['functionDeclarations' => [['name' => 'list_directory', 'description' => 'Lists a directory',
            'parametersJsonSchema' => ['type' => 'object', 'properties' => ['dir_path' => ['type' => 'string']], 'required' => ['dir_path']]]]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => 'List the files.']]],
            ['role' => 'model', 'parts' => [['functionCall' => ['id' => 'list_directory_1', 'name' => 'list_directory', 'args' => ['dir_path' => '.']], 'thoughtSignature' => 'sig']]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['id' => 'list_directory_1', 'name' => 'list_directory', 'response' => ['output' => 'a.txt']]]]]],
        'generationConfig' => ['temperature' => 1, 'topP' => 0.95, 'topK' => 64, 'thinkingConfig' => ['includeThoughts' => true, 'thinkingLevel' => 'HIGH']]];
}

function testGeminiNonStreamIsMeteredExactlyLikeChat(): void
{
    [$ref, $key] = fundedKey(100000);
    $r = gem('weighted', 'generateContent', gemBody(), $key);
    same(200, $r['status'], $r['body']);
    checkNoUpstreamDetails($r['body']);
    same(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'cached hello']]], 'finishReason' => 'STOP', 'index' => 0]],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 50, 'totalTokenCount' => 1050, 'cachedContentTokenCount' => 800, 'thoughtsTokenCount' => 0],
        'modelVersion' => 'weighted', 'responseId' => 'c'], json_decode($r['body'], true));
    // 200 fresh x 2 + 800 cached x 0.5 + 50 output x 8 credits: what the same answer costs on chat.
    $gemini = 100000 - settled($ref)['balance'];
    same(1200, $gemini);
    $u = lastUsage($ref);
    same([1000, 800, 50, 1200, 0, 0, 1, 'weighted'], [$u['prompt_tokens'], $u['cached_tokens'], $u['completion_tokens'], $u['charged'], $u['estimated'], $u['stream'], $u['attempts'], $u['model']]);
    same('scenario-cached', lastUpstreamRequest()['model'], 'the alias\'s own route');
    same(200, http('POST', '/v1/chat/completions', msg('weighted', false), $key)['status']);
    same($gemini, 100000 - $gemini - settled($ref)['balance'], 'the same charge as the chat request');
}

function testGeminiStreamIsRelayedAndMeteredExactly(): void
{
    [$ref, $key] = fundedKey(10000);
    $r = gem('ok', 'streamGenerateContent', gemBody(), $key, [], '?alt=sse');
    same(200, $r['status'], $r['body']);
    checkNoUpstreamDetails($r['body']);
    $chunks = gemChunks($r['body']);
    $text = implode('', array_map(static fn (array $c): string => implode('', array_column($c['candidates'][0]['content']['parts'], 'text')), $chunks));
    same('t1 t2 t3 t4 t5 t6 t7 t8 t9 t10 ', $text);
    $last = array_pop($chunks);
    same(['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP', 'index' => 0]],
        'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 10, 'totalTokenCount' => 21, 'cachedContentTokenCount' => 0, 'thoughtsTokenCount' => 0],
        'modelVersion' => 'ok', 'responseId' => 'x'], $last, 'the last chunk: the finish reason and the charged usage');
    foreach ($chunks as $c) {
        check(!isset($c['candidates'][0]['finishReason']) && !isset($c['usageMetadata']) && $c['candidates'][0]['content']['parts'] !== [], 'text chunks carry text only');
    }
    same(10000 - 21, settled($ref)['balance']);
    $u = lastUsage($ref);
    same([11, 10, 21, 0, 0, 1], [$u['prompt_tokens'], $u['completion_tokens'], $u['charged'], $u['estimated'], $u['aborted'], $u['stream']]);
    check($u['first_byte_ms'] !== null, 'time to the first relayed byte recorded');
    same(200, http('POST', '/v1/chat/completions', msg('ok', true), $key)['status']);
    same(10000 - 2 * 21, settled($ref)['balance'], 'the same charge as the chat stream');
    $missing = gem('ok', 'streamGenerateContent', gemBody(), $key);
    same([400, 'INVALID_ARGUMENT', 'streamGenerateContent needs alt=sse'], [$missing['status'], gemError($missing)['status'], gemError($missing)['message']]);
}

function testGeminiFunctionCallRoundTrip(): void
{
    [$ref, $key] = fundedKey(100000);
    $r = gem('tool', 'generateContent', gemToolBody(), $key);
    same(200, $r['status'], $r['body']);
    same([['functionCall' => ['id' => 'call_1', 'name' => 'list_directory', 'args' => ['dir_path' => '.']]]],
        json_decode($r['body'], true)['candidates'][0]['content']['parts'], 'the chat tool call as a function call with object args');
    same('STOP', json_decode($r['body'], true)['candidates'][0]['finishReason']);
    $up = lastUpstreamRequest();
    same([['system', [], null], ['user', [], null], ['assistant', ['list_directory_1'], null], ['tool', [], 'list_directory_1']], $up['message_shape'],
        'system instruction, user text, the model\'s call and its result as chat messages, ids kept');
    same([['list_directory'], false], [$up['tool_names'], in_array('topK', $up['keys'], true)], 'the declaration forwarded, unknown settings dropped');
    same(100000 - 14, settled($ref)['balance'], 'reported usage: 11 + 3');
    // Streamed: the fragments come back as one whole call in the last chunk, metered exactly (each fragment's event
    // carried a report covering it).
    $s = gem('tool', 'streamGenerateContent', gemToolBody(), $key, [], '?alt=sse');
    same(200, $s['status'], $s['body']);
    $chunks = gemChunks($s['body']);
    same(1, count($chunks), 'nothing but the call to send');
    same(['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['id' => 'call_1', 'name' => 'list_directory', 'args' => ['dir_path' => '.']]]]],
        'finishReason' => 'STOP', 'index' => 0]],
        'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 3, 'totalTokenCount' => 14, 'cachedContentTokenCount' => 0, 'thoughtsTokenCount' => 0],
        'modelVersion' => 'tool', 'responseId' => 'tc'], $chunks[0]);
    same(100000 - 28, settled($ref)['balance']);
    same([14, 0], [lastUsage($ref)['charged'], lastUsage($ref)['estimated']]);
}

function testGeminiAStreamCutOffBeforeItsCallIsSentChargesOnlyTheTextDelivered(): void
{
    [$ref, $key] = fundedKey(100000);
    $r = gem('tool-cut', 'streamGenerateContent', gemBody(), $key, [], '?alt=sse');
    same(200, $r['status'], $r['body']);
    $chunks = gemChunks($r['body']);
    same([[['text' => 'let me look']], ['code' => 502, 'message' => 'upstream stream ended early', 'status' => 'UNAVAILABLE']],
        [$chunks[0]['candidates'][0]['content']['parts'], $chunks[1]['error']], 'the text, then the in-band error; the unfinished call is never sent');
    same(2, count($chunks));
    [$chat] = Gemini::request(gemBody());
    $chat['messages'] = Proxy::normalizeMessages($chat['messages']);
    $u = lastUsage($ref);
    // No usage report: the upper bound, of which the output side is the 11 bytes of text the client got, not the fragment.
    same([1, Proxy::promptBound($chat), 11, Proxy::promptBound($chat) + 11], [$u['estimated'], $u['prompt_tokens'], $u['completion_tokens'], $u['charged']]);
    same(100000 - $u['charged'], settled($ref)['balance']);
}

function testGeminiReasoningIsAThoughtPart(): void
{
    [, $key] = fundedKey(10000);
    $r = gem('reasoning-field', 'generateContent', gemBody(), $key);
    same(200, $r['status'], $r['body']);
    $c = json_decode($r['body'], true)['candidates'][0];
    same([[['text' => 'thinking it over', 'thought' => true]], 'MAX_TOKENS'], [$c['content']['parts'], $c['finishReason']], 'an empty content makes no part');
}

function testGeminiRefusesWhatCannotBeBounded(): void
{
    [$ref, $key] = fundedKey(10000);
    foreach ([
        gemBody(['contents' => [['role' => 'user', 'parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']]]]]]),
        gemBody(['contents' => [['role' => 'user', 'parts' => [['text' => 'look', 'inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']]]]]]),
        gemBody(['contents' => [['role' => 'user', 'parts' => [['fileData' => ['fileUri' => 'https://example.com/a.pdf']]]]]]),
        gemBody(['contents' => [['role' => 'user', 'parts' => [['functionCall' => ['name' => 'f']]]]]]),
        gemBody(['contents' => [['role' => 'model', 'parts' => [['functionCall' => ['name' => 'f', 'args' => [1, 2]]]]]]]),
        gemBody(['tools' => [['googleSearch' => new stdClass()]]]),
        gemBody(['tools' => [['functionDeclarations' => [['name' => 'f']], 'urlContext' => new stdClass()]]]),
        gemBody(['tools' => [['functionDeclarations' => [['description' => 'no name']]]]]),
        gemBody(['systemInstruction' => ['parts' => [['inlineData' => ['data' => 'AAAA']]]]]),
        gemBody(['generationConfig' => ['maxOutputTokens' => 0]]),
        ['contents' => []], ['contents' => [['role' => 'system', 'parts' => [['text' => 'x']]]]], ['contents' => [['role' => 'user', 'parts' => []]]],
    ] as $bad) {
        foreach (['generateContent', 'countTokens'] as $method) {
            $r = gem('ok', $method, $bad, $key);
            same([400, 'INVALID_ARGUMENT'], [$r['status'], gemError($r)['status']], "$method " . json_encode($bad));
        }
    }
    same(10000, settled($ref)['balance'], 'nothing reserved or charged');
}

function testGeminiUnknownModelOrPathIsAGemini404(): void
{
    [$ref, $key] = fundedKey(10000);
    foreach (['generateContent', 'streamGenerateContent?alt=sse', 'countTokens'] as $method) {
        $r = gem('no-such-alias', $method, gemBody(), $key);
        same([404, 'NOT_FOUND', 'unknown model; see /v1/models'], [$r['status'], gemError($r)['status'], gemError($r)['message']], $method);
    }
    foreach ([['POST', '/v1beta/models/ok:embedContent'], ['GET', '/v1beta/models/ok:generateContent'], ['GET', '/v1beta/models']] as [$m, $path]) {
        $r = http($m, $path, null, $key);
        same([404, 'NOT_FOUND'], [$r['status'], gemError($r)['status']], "$m $path");
    }
    same(10000, settled($ref)['balance']);
}

function testGeminiAcceptsTheKeyAsHeaderQueryOrBearer(): void
{
    [$ref, $key] = fundedKey(10000);
    same(200, gem('ok', 'generateContent', gemBody(), $key)['status'], 'x-goog-api-key');
    same(200, gem('ok', 'generateContent', gemBody(), null, [], "?key=$key")['status'], 'key parameter');
    same(200, http('POST', '/v1beta/models/ok:generateContent', gemBody(), $key)['status'], 'Authorization: Bearer');
    same(10000 - 3 * 18, settled($ref)['balance'], 'three answers of 11 + 7 tokens');
    $missing = gem('ok', 'generateContent', gemBody(), null);
    same([401, 'UNAUTHENTICATED', 'missing key: send x-goog-api-key, a key parameter or Authorization: Bearer'],
        [$missing['status'], gemError($missing)['status'], gemError($missing)['message']]);
    $wrong = gem('ok', 'generateContent', gemBody(), 'vsk-wrong');
    same([401, 'UNAUTHENTICATED'], [$wrong['status'], gemError($wrong)['status']]);
    same(401, gem('ok', 'countTokens', gemBody(), null)['status'], 'countTokens needs a key too');
}

function testGeminiFailuresAreGeminiErrorsAndFree(): void
{
    foreach ([false, true] as $stream) {
        [$ref, $key] = fundedKey(10000);
        $r = gem('all-down', $stream ? 'streamGenerateContent' : 'generateContent', gemBody(), $key, [], $stream ? '?alt=sse' : '');
        same([503, 'UNAVAILABLE'], [$r['status'], gemError($r)['status']]);
        checkNoUpstreamDetails($r['body']);
        same(10000, settled($ref)['balance'], 'nothing charged');
    }
    [$ref, $key] = fundedKey(5);
    $r = gem('ok', 'generateContent', gemBody(), $key);
    same([402, 'FAILED_PRECONDITION', 'credit balance too low for this request'], [$r['status'], gemError($r)['status'], gemError($r)['message']]);
    same(5, settled($ref)['balance']);
}

function testGeminiCountTokensIsAFreeConservativeEstimate(): void
{
    [$ref, $key] = fundedKey(1000);
    $r = gem('ok', 'countTokens', gemToolBody(), $key);
    same(200, $r['status'], $r['body']);
    [$chat] = Gemini::request(gemToolBody());
    $chat['messages'] = Proxy::normalizeMessages($chat['messages']);
    same(['totalTokens' => Proxy::promptBound($chat)], json_decode($r['body'], true), 'the prompt bound the request would reserve for');
    same($r['body'], gem('ok', 'countTokens', ['generateContentRequest' => gemToolBody(['model' => 'models/ok'])], $key)['body'], 'the wrapped form');
    same(1000, settled($ref)['balance'], 'nothing reserved or charged');
    $db = new \PDO('sqlite:' . Harness::get()['dir'] . '/ledger.sqlite');
    $st = $db->prepare('SELECT COUNT(*) FROM usage u JOIN keys k ON k.id = u.key_id WHERE k.ref = ?');
    $st->execute([$ref]);
    same(0, (int) $st->fetchColumn(), 'no usage row');
}

function testGeminiRequestTranslation(): void
{
    [$chat, $max] = Gemini::request([
        'systemInstruction' => ['parts' => [['text' => 'one'], ['text' => 'two']]],
        'contents' => [
            ['parts' => [['text' => 'hi'], ['text' => 'there']]],
            ['role' => 'model', 'parts' => [['text' => 'pondering', 'thought' => true], ['text' => 'calling'],
                ['functionCall' => ['name' => 'f', 'args' => ['a' => 1]]], ['functionCall' => ['name' => 'f']], ['functionCall' => ['id' => 'g1', 'name' => 'g', 'args' => ['b' => []]]]]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'f', 'response' => ['output' => 'x']]], ['functionResponse' => ['id' => 'g1', 'name' => 'g']],
                ['functionResponse' => ['name' => 'f', 'response' => ['output' => 'y']]], ['text' => 'thanks']]],
        ],
        'tools' => [['functionDeclarations' => [['name' => 'f', 'parameters' => ['type' => 'OBJECT', 'properties' => ['a' => ['type' => 'INTEGER', 'enum' => ['OBJECT']], 'type' => ['type' => 'STRING']]]],
            ['name' => 'g', 'description' => 'd', 'parametersJsonSchema' => ['type' => ['object', 'NULL'], 'properties' => [], 'required' => []]]]], []],
        'toolConfig' => ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => ['f']]],
        'generationConfig' => ['temperature' => 0.2, 'topP' => 0.9, 'topK' => 5, 'stopSequences' => ['END'], 'seed' => 7, 'presencePenalty' => 0.1, 'frequencyPenalty' => 0.3,
            'candidateCount' => 3, 'maxOutputTokens' => 99, 'responseMimeType' => 'application/json', 'responseJsonSchema' => ['type' => 'OBJECT'],
            'thinkingConfig' => ['thinkingLevel' => 'HIGH']],
        'safetySettings' => [['category' => 'x', 'threshold' => 'y']], 'cachedContent' => 'cachedContents/1',
    ]);
    same(['max_tokens' => 99], $max);
    same(json_encode([
        'messages' => [
            ['role' => 'system', 'content' => "one\ntwo"],
            ['role' => 'user', 'content' => "hi\nthere"],
            ['role' => 'assistant', 'content' => 'calling', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{"a":1}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}']],
                ['id' => 'g1', 'type' => 'function', 'function' => ['name' => 'g', 'arguments' => '{"b":[]}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"output":"x"}'],
            ['role' => 'tool', 'tool_call_id' => 'g1', 'content' => '{}'],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"output":"y"}'],
            ['role' => 'user', 'content' => 'thanks'],
        ],
        'tools' => [
            ['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer', 'enum' => ['OBJECT']], 'type' => ['type' => 'string']]]]],
            ['type' => 'function', 'function' => ['name' => 'g', 'description' => 'd', 'parameters' => ['type' => ['object', 'null'], 'properties' => new stdClass(), 'required' => []]]],
        ],
        'tool_choice' => ['type' => 'function', 'function' => ['name' => 'f']],
        'temperature' => 0.2, 'top_p' => 0.9, 'stop' => ['END'], 'seed' => 7, 'presence_penalty' => 0.1, 'frequency_penalty' => 0.3,
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'schema' => ['type' => 'object']]],
    ]), Proxy::encode($chat), 'one chat request: ids for idless calls, responses paired by name in order, schemas as JSON Schema');
    same('none', Gemini::request(gemBody(['toolConfig' => ['functionCallingConfig' => ['mode' => 'NONE']]]))[0]['tool_choice']);
    same('required', Gemini::request(gemBody(['toolConfig' => ['functionCallingConfig' => ['mode' => 'ANY']]]))[0]['tool_choice']);
    same(['type' => 'json_object'], Gemini::request(gemBody(['generationConfig' => ['responseMimeType' => 'application/json']]))[0]['response_format']);
    same(['/v1beta/models/a:b:countTokens' => ['a:b', 'countTokens'], '/v1beta/models/my%20alias:generateContent' => ['my alias', 'generateContent'],
        '/v1beta/models/ok:embedContent' => null, '/v1/models/ok:generateContent' => null, '/v1beta/models/x/y:generateContent' => null],
        array_map(Gemini::target(...), ['/v1beta/models/a:b:countTokens' => '/v1beta/models/a:b:countTokens', '/v1beta/models/my%20alias:generateContent' => '/v1beta/models/my%20alias:generateContent',
            '/v1beta/models/ok:embedContent' => '/v1beta/models/ok:embedContent', '/v1/models/ok:generateContent' => '/v1/models/ok:generateContent',
            '/v1beta/models/x/y:generateContent' => '/v1beta/models/x/y:generateContent']));
}

function testGeminiEmptySchemasThroughTheDecoderStayObjects(): void
{
    // The request decoder keeps an empty JSON object as an object; a function with no parameters and an empty
    // response schema must still be translated, as {} and never dropped or sent as [].
    $raw = '{"contents":[{"parts":[{"text":"hi"}]}],"tools":[{"functionDeclarations":[{"name":"f","parametersJsonSchema":{}},'
        . '{"name":"g","parameters":{"type":"OBJECT","properties":{}}}]}],"generationConfig":{"responseMimeType":"application/json","responseJsonSchema":{}}}';
    [$chat] = Gemini::request(MagnaCapax\Virsilipas\Http::decode($raw));
    $out = json_encode($chat);
    check(str_contains($out, '{"type":"function","function":{"name":"f","parameters":{}}}'), 'empty parameters kept as {}: ' . $out);
    check(str_contains($out, '"parameters":{"type":"object","properties":{}}'), 'nested empty properties kept as {}');
    check(str_contains($out, '"schema":{}'), 'empty response schema kept as {}');
}
