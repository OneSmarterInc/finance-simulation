<?php

return [

    /*
    | Demo tools (walkthrough item 33). The "Import demo students" action on
    | the faculty Firms page is for demonstrations and testing. It is off in
    | production unless FLEXEE_DEMO_TOOLS=true is set deliberately.
    */
    'demo_tools' => (bool) env('FLEXEE_DEMO_TOOLS', env('APP_ENV', 'production') !== 'production'),

];
