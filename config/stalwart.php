<?php

return [
    'url' => env('STALWART_URL'),
    'reserved' => array_filter(array_map('trim', explode(',', env('STALWART_RESERVED_MAILBOXES', '')))),
    'domain_id' => env('STALWART_DOMAIN_ID'),
    'username' => env('STALWART_PROVISION_USER'),
    'password' => env('STALWART_PROVISION_PASSWORD'),
];
