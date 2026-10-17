<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/** Small request/response helpers for the front controller. */
final class Http
{
    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    public static function bearer(): string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/', $h, $m)) {
            throw new HttpError(401, 'missing bearer key', 'authentication_error', code: 'invalid_api_key');
        }
        return $m[1];
    }

    /**
     * The key of an Anthropic-format client: from x-api-key, else from Authorization: Bearer (such clients send one or
     * the other).
     */
    public static function apiKey(): string
    {
        $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (is_string($k) && $k !== '') {
            return $k;
        }
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') === '') {
            throw new HttpError(401, 'missing key: send x-api-key or Authorization: Bearer', 'authentication_error', code: 'invalid_api_key');
        }
        return self::bearer();
    }

    /**
     * The key of a Gemini-format client: from x-goog-api-key, else from a key query parameter, else from
     * Authorization: Bearer (such clients send one of the three).
     */
    public static function googleKey(): string
    {
        foreach ([$_SERVER['HTTP_X_GOOG_API_KEY'] ?? '', $_GET['key'] ?? ''] as $k) {
            if (is_string($k) && $k !== '') {
                return $k;
            }
        }
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') === '') {
            throw new HttpError(401, 'missing key: send x-goog-api-key, a key parameter or Authorization: Bearer', 'authentication_error', code: 'invalid_api_key');
        }
        return self::bearer();
    }

    /** @return array<string, mixed> */
    public static function jsonBody(int $maxBytes): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            throw new HttpError(413, 'request body too large', code: 'request_too_large');
        }
        $body = json_decode($raw, true);
        // A JSON array decodes to a PHP list too; only an object is a request ({} decodes to []).
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new HttpError(400, 'request body must be a JSON object', code: 'invalid_json');
        }
        // A number too large for a float (1e999) decodes to INF, which JSON cannot carry: the body could not be
        // forwarded as it was sent, so it is refused here, before anything is reserved.
        if (json_encode($body) === false) {
            throw new HttpError(400, 'request body has a number out of range', code: 'invalid_json');
        }
        return $body;
    }
}
