<?php

namespace App\Services;

use App\DTOs\GoogleUserPayload;
use App\Exceptions\GoogleEmailNotVerifiedException;
use App\Exceptions\InvalidGoogleTokenException;
use App\Models\User;
use Google_Client;

/**
 * Handles Google ID token verification and user account management.
 *
 * Responsibilities:
 * - Verify Google ID tokens using google/apiclient (local RSA verification).
 * - Find existing users or create new ones from Google credentials.
 * - Link Google accounts to existing email/password users.
 *
 * This service does NOT use Firebase Auth — it works directly with
 * Google's public keys via google/apiclient.
 */
class GoogleAuthService
{
    private readonly Google_Client $googleClient;

    public function __construct()
    {
        $this->googleClient = new Google_Client([
            'client_id' => config('services.google.client_id'),
        ]);
    }

    /**
     * Verifies a Google ID token and extracts the user payload.
     *
     * Uses google/apiclient which:
     * 1. Downloads and caches Google's RSA public keys (~24h cache)
     * 2. Verifies the JWT signature locally (no HTTP call to Google)
     * 3. Validates aud, iss, and exp claims
     *
     * @throws InvalidGoogleTokenException If the token is invalid, expired, or tampered with.
     */
    public function verifyIdToken(string $idToken): GoogleUserPayload
    {
        $payload = $this->googleClient->verifyIdToken($idToken);

        if (!$payload) {
            throw new InvalidGoogleTokenException();
        }

        return new GoogleUserPayload(
            googleId: $payload['sub'],
            email: $payload['email'],
            name: $payload['name'] ?? $payload['email'],
            avatarUrl: $payload['picture'] ?? null,
            emailVerified: $payload['email_verified'] ?? false,
        );
    }

    /**
     * Finds an existing user or creates a new one from Google credentials.
     *
     * Resolution order:
     * 1. Match by google_id (fastest — already linked account)
     * 2. Match by email (account linking — existing email/password user)
     * 3. Create new user (no password needed for Google-only users)
     *
     * @throws GoogleEmailNotVerifiedException If Google reports the email as unverified.
     */
    public function findOrCreateUser(GoogleUserPayload $payload): User
    {
        if (!$payload->emailVerified) {
            throw new GoogleEmailNotVerifiedException(
                "El email {$payload->email} no está verificado por Google."
            );
        }

        // 1. Buscar por google_id (already linked)
        $user = User::where('google_id', $payload->googleId)->first();
        if ($user) {
            return $this->updateGoogleData($user, $payload);
        }

        // 2. Buscar por email (account linking)
        $user = User::where('email', $payload->email)->first();
        if ($user) {
            return $this->updateGoogleData($user, $payload);
        }

        // 3. Crear nuevo usuario (sin password)
        return User::create([
            'name' => $payload->name,
            'email' => $payload->email,
            'google_id' => $payload->googleId,
            'avatar_url' => $payload->avatarUrl,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Updates Google-specific fields on an existing user.
     *
     * Called on every Google login to keep avatar and google_id current.
     * Only saves if there are actual changes (avoids unnecessary DB writes).
     */
    private function updateGoogleData(User $user, GoogleUserPayload $payload): User
    {
        $dirty = false;

        if ($user->google_id !== $payload->googleId) {
            $user->google_id = $payload->googleId;
            $dirty = true;
        }

        if ($user->avatar_url !== $payload->avatarUrl) {
            $user->avatar_url = $payload->avatarUrl;
            $dirty = true;
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }

        return $user;
    }
}
