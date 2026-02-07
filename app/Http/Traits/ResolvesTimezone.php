<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;

/**
 * Resolves the user's timezone from the X-Timezone request header.
 *
 * The client (Flutter) sends an IANA timezone identifier (e.g. "America/Mexico_City")
 * on every authenticated request. This is the standard approach used by
 * Google Calendar, Todoist, and most production apps.
 *
 * Falls back to 'UTC' if the header is missing or contains an invalid value.
 */
trait ResolvesTimezone
{
    /**
     * Extract and validate the timezone from the X-Timezone header.
     *
     * @return string A valid IANA timezone identifier (e.g. "America/Mexico_City")
     */
    protected function resolveTimezone(Request $request): string
    {
        $tz = $request->header('X-Timezone', 'UTC');

        try {
            new \DateTimeZone($tz);
            return $tz;
        } catch (\Exception) {
            return 'UTC';
        }
    }
}
