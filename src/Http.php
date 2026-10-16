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
        return $body;
    }
}
