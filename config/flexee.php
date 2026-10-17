<?php

return [

    /*
    | Demo tools (walkthrough item 33). The "Import demo students" action on
    | the faculty Firms page is for demonstrations and testing. It is off in
    | production unless FLEXEE_DEMO_TOOLS=true is set deliberately.
    */
    'demo_tools' => (bool) env('FLEXEE_DEMO_TOOLS', env('APP_ENV', 'production') !== 'production'),

    /*
    | Paid access (walkthrough item 45, decision N3). When on, a student's firm
    | pages open only after One Smarter marks the enrolment paid in admin.
    | Enrolments that existed before this setting were marked paid by the
    | 2026_10_17 migration so no current student loses access.
    */
    'require_payment' => (bool) env('FLEXEE_REQUIRE_PAYMENT', true),

];
