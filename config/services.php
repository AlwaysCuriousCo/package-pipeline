<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'github' => [
        // Last-resort token, used only for packages with neither a connected
        // source nor a token of their own.
        'token' => env('GITHUB_TOKEN'),

        // The registered GitHub App that sources install onto an organisation
        // to authenticate without a long-lived token. See docs/github-app.md.
        'app' => [
            'id' => env('GITHUB_APP_ID'),
            // The generated .pem, given either as a file path or as the key
            // itself with its newlines escaped as "\n".
            'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
            // Read from GitHub when empty; only worth setting to skip that
            // lookup, or if the app is renamed before the cache expires.
            'slug' => env('GITHUB_APP_SLUG'),
            // Overridden only on GitHub Enterprise.
            'api_url' => env('GITHUB_APP_API_URL', 'https://api.github.com'),

            // The secret set on the app's own webhook, which delivers events
            // for every repository in every installation. Setting it is what
            // turns auto-sync on for App-connected sources; see docs/webhooks.md.
            'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
        ],
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'publishable' => env('STRIPE_PUBLISHABLE'),

        // The signing secret of the app's webhook endpoint at Stripe. Setting
        // it is what turns the /billing/stripe/webhook route on; without it
        // every delivery is refused unverified.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Whether checkout asks Stripe Tax to compute and collect tax.
        // Requires Stripe Tax to be enabled on the Stripe account first, or
        // every checkout session will fail to create.
        'tax_enabled' => (bool) env('STRIPE_TAX_ENABLED', false),
    ],

];
