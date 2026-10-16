<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google ID token fails verification.
 *
 * Possible causes: expired token, invalid signature, wrong audience,
 * tampered payload, or malformed JWT.
 */
class InvalidGoogleTokenException extends RuntimeException
{
    public function __construct(string $message = 'El token de Google es inválido o expiró.')
    {
        parent::__construct($message);
    }
}
