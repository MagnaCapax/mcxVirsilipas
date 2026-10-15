<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * A refusal that maps straight to an HTTP status and an OpenAI-style error body:
 * {"error": {"message", "type", "param", "code"}}. Every message and code is one of the proxy's own
 * fixed strings; nothing an upstream said is ever put in one.
 */
final class HttpError extends \RuntimeException
{
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
}
