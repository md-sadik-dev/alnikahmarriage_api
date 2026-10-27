<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Project ID & Service Account Credentials
    |--------------------------------------------------------------------------
    | Authoritative server-side FCM HTTP v1 dispatch credentials.
    */
    'project_id' => env('FIREBASE_PROJECT_ID', 'alnikah-marriage-notifications'),

    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', storage_path('credentials/firebase-service-account.json')),

    'vapid_key' => env('FIREBASE_VAPID_KEY', 'BGhiBLgzmxPCHTwptm78vKJcuWBjMIXomgsdzQQA2nUseXXhyd9-e_djlvSwW6pVnRcFliVLKY4-cULWhGc1fcc'),

    /*
    |--------------------------------------------------------------------------
    | Firebase Web App Client Configuration
    |--------------------------------------------------------------------------
    | Public configuration used by the browser FCM SDK client.
    */
    'web_config' => [
        'apiKey' => env('FIREBASE_WEB_API_KEY', 'AIzaSyAaQynEvObXsLHrwceW7Mw-4eZrknz8Z8s'),
        'authDomain' => env('FIREBASE_WEB_AUTH_DOMAIN', 'alnikah-marriage-notifications.firebaseapp.com'),
        'projectId' => env('FIREBASE_PROJECT_ID', 'alnikah-marriage-notifications'),
        'storageBucket' => env('FIREBASE_WEB_STORAGE_BUCKET', 'alnikah-marriage-notifications.firebasestorage.app'),
        'messagingSenderId' => env('FIREBASE_WEB_MESSAGING_SENDER_ID', '311971068214'),
        'appId' => env('FIREBASE_WEB_APP_ID', '1:311971068214:web:d22c4a772685ff9b551b97'),
        'measurementId' => env('FIREBASE_WEB_MEASUREMENT_ID', 'G-V9ETQ15J68'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Push Notification Assets
    |--------------------------------------------------------------------------
    */
    'default_icon' => 'images/logo.webp',
    'default_badge' => 'images/logo.webp',
];
