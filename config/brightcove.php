<?php
return [
    'default'  => env('BRIGHTCOVE_ACCOUNT', 'default'),
    'accounts' => [
        'default' => [
            'client_id'     => env("BRIGHTCOVE_CLIENT_ID"),
            'client_secret' => env("BRIGHTCOVE_SECRET"),
            'account_id'    => env("BRIGHTCOVE_ACCOUNT_ID"),
            'live_key'    => env("BRIGHTCOVE_LIVE_API_KEY"),
        ]
    ]
];
