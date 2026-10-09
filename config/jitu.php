<?php

return [

    // Who may create an account: "invite" (a valid invitation code is required, the default),
    // "open" (anyone), or "closed" (no sign-up page at all; the operator creates accounts).
    'registration' => env('REGISTRATION_MODE', 'invite'),

];
