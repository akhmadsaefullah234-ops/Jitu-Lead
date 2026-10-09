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

    // Optional gateway run by the platform operator. When set, agencies connect a number by scanning
    // a QR code and never see an address or key. Without it, agencies enter their own gateway details.
    'gateway' => [
        'url' => env('WHATSAPP_GATEWAY_URL'),
        'api_key' => env('WHATSAPP_GATEWAY_API_KEY'),
        'signing_secret' => env('WHATSAPP_GATEWAY_SIGNING_SECRET'),
    ],

    // The AI steps back while an agent is working a chat. Typing keeps pushing the pause forward;
    // once the agent stops, the AI takes over again after this many seconds. Merely opening a chat pauses nothing.
    'ai_typing_pause_seconds' => (int) env('AI_TYPING_PAUSE_SECONDS', 60),
    // After an agent sends a message by hand, the client's answer is likely meant for that agent.
    'ai_after_send_pause_seconds' => (int) env('AI_AFTER_SEND_PAUSE_SECONDS', 300),

    // Window lengths from Meta's pricing rules, in hours.
    'service_window_hours' => 24,
    'free_entry_window_hours' => 72,
    'free_entry_reply_deadline_hours' => 24,
];
