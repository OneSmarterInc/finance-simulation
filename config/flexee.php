<?php

return [

    /*
    | Demo tools (walkthrough item 33). The "Import demo students" action on
    | the faculty Firms page is for demonstrations and testing. It is off in
    | production unless FLEXEE_DEMO_TOOLS=true is set deliberately.
    */
    'demo_tools' => (bool) env('FLEXEE_DEMO_TOOLS', env('APP_ENV', 'production') !== 'production'),

    /*
    | Display time zone. Timestamps stay in UTC in the database; deadlines are
    | entered and shown in this zone, with its abbreviation, on every screen.
    */

    /*
    | Paid access (walkthrough item 45, decision N3). When on, a student's firm
    | pages open only after One Smarter marks the enrolment paid in admin.
    | Enrolments that existed before this setting were marked paid by the
    | 2026_10_17 migration so no current student loses access. On by default
    | in production only; set FLEXEE_REQUIRE_PAYMENT to override.
    */
    'display_timezone' => env('FLEXEE_TIMEZONE', 'UTC'),

    'require_payment' => (bool) env('FLEXEE_REQUIRE_PAYMENT', env('APP_ENV', 'production') === 'production'),

    /*
    | Engine version for courses created from now on: 2 since the engine
    | design note's layers shipped (October 2026). Running courses keep the
    | version they started with (ED11). The test suite pins 1 in phpunit.xml so
    | the older tests keep covering the courses that still run version 1; the
    | version 2 tests set it themselves.
    */
    'engine_version_for_new_courses' => (int) env('FLEXEE_ENGINE_VERSION', 2),


    /*
    | Ask Claude on faculty pages (U1, design note AC1 to AC8). Off until the
    | server has ANTHROPIC_API_KEY and can reach api.anthropic.com; then each
    | course has its own switch, on by default (AC6). Students never see it.
    | Prices are per million tokens, for the log and the spending ceiling.
    */
    'ai' => [
        'enabled' => (bool) env('FLEXEE_AI_ENABLED', false),
        'model' => env('FLEXEE_AI_MODEL', 'claude-sonnet-5-5'),
        'questions_per_course_month' => (int) env('FLEXEE_AI_QUESTIONS_PER_MONTH', 100),
        'monthly_spend_ceiling_usd' => (float) env('FLEXEE_AI_MONTHLY_CEILING_USD', 200),
        'price_input_per_mtok' => (float) env('FLEXEE_AI_PRICE_INPUT', 2.0),
        'price_output_per_mtok' => (float) env('FLEXEE_AI_PRICE_OUTPUT', 10.0),
        'max_tokens' => 1500,
        'max_lookup_rounds' => 4,
        'timeout_seconds' => 55,
        'keep_days' => 90,
        // L15: Claude's reading of each closed quarter, written by flexee:readings each minute.
        'readings_per_run' => (int) env('FLEXEE_AI_READINGS_PER_RUN', 6),
    ],

];
