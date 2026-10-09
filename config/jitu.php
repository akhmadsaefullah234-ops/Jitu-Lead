<?php

return [

    // Who may create an account: "invite" (a valid invitation code is required, the default),
    // "open" (anyone), or "closed" (no sign-up page at all; the operator creates accounts).
    'registration' => env('REGISTRATION_MODE', 'invite'),

    // Where agencies transfer the subscription fee (shown on the Langganan page). Plain text, line breaks allowed.
    'billing_bank_info' => env('BILLING_BANK_INFO'),
    'billing_contact' => env('BILLING_CONTACT'),
];
