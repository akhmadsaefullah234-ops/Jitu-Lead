<?php

return [
    // Meta Graph API version used for the official WhatsApp Business API.
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    // How long a request to a provider may take before the message counts as failed.
    'timeout' => 15,

    // Gateways are reached at URLs that tenants enter themselves. Refuse private
    // and loopback addresses and plain HTTP unless explicitly allowed (local dev).
    'allow_private_gateway_hosts' => (bool) env('WHATSAPP_ALLOW_PRIVATE_GATEWAY_HOSTS', false),

    // Window lengths from Meta's pricing rules, in hours.
    'service_window_hours' => 24,
    'free_entry_window_hours' => 72,
    'free_entry_reply_deadline_hours' => 24,
];
