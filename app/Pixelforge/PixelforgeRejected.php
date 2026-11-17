<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * A definite answer from Pixelforge: per its docs, nothing was created or billed
 * (400, 401, 404, 409, 422, 429, 503).
 */
class PixelforgeRejected extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfter = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Pixelforge rejected the request: {$errorCode} ({$httpStatus}).");
    }

}
