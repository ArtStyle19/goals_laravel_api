<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Project Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Firebase project settings. The credentials
    | path should point to your service account JSON file.
    |
    */

    'credentials' => [
        'file' => storage_path('firebase/firebase-service-account.json'),
    ],

    'project_id' => env('FIREBASE_PROJECT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | FCM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Firebase Cloud Messaging.
    |
    */

    'fcm' => [
        'default_ttl' => 3600, // 1 hour
        'priority' => 'high',
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, detailed logging information will be output.
    |
    */

    'debug' => env('FIREBASE_DEBUG', false),
];
