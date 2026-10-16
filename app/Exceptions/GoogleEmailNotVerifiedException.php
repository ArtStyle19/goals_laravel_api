<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when Google reports the user's email as not verified.
 *
 * Security measure: accepting unverified emails could allow an attacker
 * to register a Google account with someone else's email and gain access
 * to their data through account linking.
 */
class GoogleEmailNotVerifiedException extends RuntimeException
{
    public function __construct(string $message = 'El email de Google no está verificado.')
    {
        parent::__construct($message);
    }
}
