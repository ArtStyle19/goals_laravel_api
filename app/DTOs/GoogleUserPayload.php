<?php

namespace App\DTOs;

/**
 * Immutable DTO representing the user payload extracted from a verified Google ID token.
 *
 * This is a pure data transfer object — no business logic, no dependencies.
 * Created by GoogleAuthService::verifyIdToken() after successful verification.
 */
final readonly class GoogleUserPayload
{
    public function __construct(
        public string $googleId,
        public string $email,
        public string $name,
        public ?string $avatarUrl,
        public bool $emailVerified,
    ) {}
}
