<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/** A refusal that maps straight to an HTTP status and an OpenAI-style error body. */
final class HttpError extends \RuntimeException
{
    /** @param ?int $retryAfter seconds for a Retry-After header (rate limits and a busy upstream) */
    public function __construct(public readonly int $status, string $message, public readonly string $type = 'invalid_request_error', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    public function send(): void
    {
        if ($this->retryAfter !== null) {
            header('Retry-After: ' . $this->retryAfter);
        }
        Http::json($this->status, ['error' => ['message' => $this->getMessage(), 'type' => $this->type]]);
    }
}
