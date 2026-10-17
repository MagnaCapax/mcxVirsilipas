<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * A refusal that maps straight to an HTTP status and an OpenAI-style error body:
 * {"error": {"message", "type", "param", "code"}}, or on the Anthropic Messages routes that format's
 * {"type": "error", "error": {"type", "message"}}, or on the Gemini routes {"error": {"code", "message", "status"}}.
 * Every message and code is one of the proxy's own
 * fixed strings; nothing an upstream said is ever put in one.
 */
final class HttpError extends \RuntimeException
{
    /** The Anthropic error type for each status the proxy answers; any other is api_error. */
    private const ANTHROPIC_TYPES = [400 => 'invalid_request_error', 401 => 'authentication_error', 402 => 'billing_error', 403 => 'permission_error',
        404 => 'not_found_error', 413 => 'request_too_large', 429 => 'rate_limit_error', 503 => 'overloaded_error', 504 => 'timeout_error'];
    /** The Gemini API error status for each status the proxy answers; any other is UNKNOWN. */
    private const GEMINI_STATUSES = [400 => 'INVALID_ARGUMENT', 401 => 'UNAUTHENTICATED', 402 => 'FAILED_PRECONDITION', 403 => 'PERMISSION_DENIED',
        404 => 'NOT_FOUND', 413 => 'INVALID_ARGUMENT', 429 => 'RESOURCE_EXHAUSTED', 500 => 'INTERNAL', 502 => 'UNAVAILABLE', 503 => 'UNAVAILABLE',
        504 => 'DEADLINE_EXCEEDED'];

    /** A stable machine-readable reason, e.g. model_not_found or context_length_exceeded (Exception::$code is the int one). */
    public readonly ?string $errorCode;

    /**
     * @param ?int $retryAfter seconds for a Retry-After header (rate limits and a busy or unavailable upstream)
     * @param ?string $code the stable reason sent as error.code
     * @param ?string $param the request field the error is about, when there is one
     */
    public function __construct(public readonly int $status, string $message, public readonly string $type = 'invalid_request_error',
        public readonly ?int $retryAfter = null, ?string $code = null, public readonly ?string $param = null)
    {
        parent::__construct($message);
        $this->errorCode = $code;
    }

    public function send(): void
    {
        if ($this->retryAfter !== null) {
            header('Retry-After: ' . $this->retryAfter);
        }
        Http::json($this->status, ['error' => ['message' => $this->getMessage(), 'type' => $this->type, 'param' => $this->param, 'code' => $this->errorCode]]);
    }

    /** The same refusal in the Anthropic Messages error format: the same status, message and Retry-After. */
    public function sendAnthropic(): void
    {
        if ($this->retryAfter !== null) {
            header('Retry-After: ' . $this->retryAfter);
        }
        Http::json($this->status, ['type' => 'error', 'error' => ['type' => self::ANTHROPIC_TYPES[$this->status] ?? 'api_error', 'message' => $this->getMessage()]]);
    }

    /** The same refusal in the Gemini API error format, {"error": {"code", "message", "status"}}: the same status, message and Retry-After. */
    public function sendGemini(): void
    {
        if ($this->retryAfter !== null) {
            header('Retry-After: ' . $this->retryAfter);
        }
        Http::json($this->status, ['error' => ['code' => $this->status, 'message' => $this->getMessage(), 'status' => self::GEMINI_STATUSES[$this->status] ?? 'UNKNOWN']]);
    }

    /** The refusal in the client's format: chat (OpenAI's shape), messages or gemini. */
    public function sendAs(string $format): void
    {
        match ($format) {
            'messages' => $this->sendAnthropic(),
            'gemini' => $this->sendGemini(),
            default => $this->send(),
        };
    }
}
