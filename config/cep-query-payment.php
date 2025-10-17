<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Banxico CEP Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Banxico CEP URL and timeout for web scraping
    | SPEI payment status queries.
    |
    */

    'url' => env('BANXICO_CEP_URL', 'https://www.banxico.org.mx/cep/'),

    'timeout' => env('BANXICO_CEP_TIMEOUT', 30000), // milliseconds
];
