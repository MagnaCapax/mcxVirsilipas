<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/** A refusal that maps straight to an HTTP status and an OpenAI-style error body. */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $type = 'invalid_request_error')
    {
        parent::__construct($message);
    }

    public function send(): void
    {
        Http::json($this->status, ['error' => ['message' => $this->getMessage(), 'type' => $this->type]]);
    }
}
