<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Focus NFe API returns an unexpected or error response.
 *
 * Separating this from generic \Exception allows callers to catch
 * Focus-specific failures independently from infrastructure errors (DB, etc.).
 */
class FocusApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
