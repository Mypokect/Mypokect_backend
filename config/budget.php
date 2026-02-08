<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Budget Plan Types
    |--------------------------------------------------------------------------
    |
    | Available budget plan types that the system can classify.
    |
    */

    'plan_types' => [
        'travel',
        'event',
        'party',
        'purchase',
        'project',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Modes
    |--------------------------------------------------------------------------
    |
    | Modes available for budget creation.
    |
    */

    'modes' => [
        'manual',
        'ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Statuses
    |--------------------------------------------------------------------------
    |
    | Status options for budgets.
    |
    */

    'statuses' => [
        'draft',
        'active',
        'archived',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | Languages supported by the budget system.
    |
    */

    'languages' => [
        'es' => 'Spanish',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for AI-powered budget generation.
    |
    */

    'ai' => [
        /*
         * Groq AI models to use for budget generation.
         * The system will try them in order until one succeeds.
         */
        'models' => [
            'llama-3.1-8b-instant',
            'gemma2-9b-it',
            'llama3-8b-8192',
        ],

        /*
         * Temperature for AI responses (0.0 - 1.0).
         * Lower = more precise, Higher = more creative.
         */
        'temperature' => 0.45,

        /*
         * Maximum number of categories to generate.
         */
        'max_categories' => 7,

        /*
         * Minimum number of categories to generate.
         */
        'min_categories' => 3,

        /*
         * Tolerance for sum validation (as percentage).
         * If AI generated sum is within this %, it will be auto-corrected.
         */
        'sum_tolerance_percentage' => 5,

        /*
         * Precision for decimal rounding.
         */
        'decimal_precision' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Budget validation settings.
    |
    */

    'validation' => [
        /*
         * Maximum allowed difference between category sum and total amount.
         */
        'max_sum_difference' => 0.01,

        /*
         * Maximum title length.
         */
        'max_title_length' => 255,

        /*
         * Maximum description length.
         */
        'max_description_length' => 2000,

        /*
         * Maximum category name length.
         */
        'max_category_name_length' => 255,

        /*
         * Maximum category reason length.
         */
        'max_category_reason_length' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting settings for budget endpoints.
    |
    */

    'rate_limiting' => [
        /*
         * Maximum requests per minute for AI budget generation.
         */
        'ai_generation' => 10,

        /*
         * Maximum requests per minute for budget creation.
         */
        'budget_creation' => 30,
    ],
];
