<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'groq' => [
        'key' => env('GROQ_KEY'),
        'model' => env('GROQ_MODEL', 'llama3-8b-8192'), // Puedes poner un valor por defecto
    ],

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],

    // Compuerta secreta del panel admin: sin esta llave, /admin/* responde 404.
    'admin' => [
        'gate_key' => env('ADMIN_GATE_KEY'),
    ],

    /*
    | SMS transaccional — códigos de verificación de login, registro,
    | recuperación de clave y login admin (App\Services\Sms\SmsSender).
    | SMS_DRIVER=log no envía nada (local/staging); SMS_DRIVER=twilio envía real.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            // Número Twilio en E.164 (+1...) o un Messaging Service SID (MG...)
            'from' => env('TWILIO_FROM'),
        ],
    ],

    /*
    | Wompi (Bancolombia) — pasarela de pago única del SaaS.
    | Llaves del panel de comercio: https://comercios.wompi.co
    | - public_key  (pub_test_* / pub_prod_*)   : firma del checkout y tokenización
    | - private_key (prv_test_* / prv_prod_*)    : SOLO servidor (transacciones, fuentes de pago)
    | - integrity_secret                         : firma de integridad del Web Checkout
    | - events_secret                            : checksum de validación de webhooks
    */
    'wompi' => [
        'public_key'       => env('WOMPI_PUBLIC_KEY'),
        'private_key'      => env('WOMPI_PRIVATE_KEY'),
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
        'events_secret'    => env('WOMPI_EVENTS_SECRET'),
        'environment'      => env('WOMPI_ENV', 'sandbox'), // sandbox | production
        'redirect_url'     => env('WOMPI_REDIRECT_URL', 'http://localhost:5173/suscripcion/gracias'),
    ],

];
