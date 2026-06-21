<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keila instance
    |--------------------------------------------------------------------------
    |
    | Base URL of your self-hosted Keila instance and an API token. The token
    | identifies the Keila project that contacts are written to. If either is
    | missing, the addon no-ops (it logs one throttled warning and never throws).
    |
    */

    'url' => env('KEILA_URL'),

    'token' => env('KEILA_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('KEILA_HTTP_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Form map
    |--------------------------------------------------------------------------
    |
    | Map Statamic form handles to Keila behaviour. Only forms listed here are
    | ever forwarded. A contact is synced only when the form's `opt_in_field`
    | (a TOGGLE field) passes Laravel's `accepted` rule.
    |
    | field_map is EXPLICIT: every Keila field, including `email`, must be
    | declared. A target of `email` / `first_name` / `last_name` / `external_id`
    | maps to that top-level Keila field; a `data.*` target maps into the
    | contact's nested custom-data object (dot paths may nest).
    |
    |   'newsletter' => [
    |       'opt_in_field' => 'newsletter_opt_in',
    |       'tags'         => ['newsletter', 'kosaktis-website'],
    |       'source'       => 'kosaktis-footer',          // optional -> data.source
    |       'field_map'    => [
    |           'email'         => 'email',
    |           'first_name'    => 'first_name',
    |           'last_name'     => 'last_name',
    |           'room_interest' => 'data.room_interest',
    |       ],
    |   ],
    |
    */

    'forms' => [
        //
    ],

];
