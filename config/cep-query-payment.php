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

    /*
    |--------------------------------------------------------------------------
    | Node / Puppeteer execution settings
    |--------------------------------------------------------------------------
    |
    | These settings control how the package launches Node to run the
    | Puppeteer script. `node_binary` lets you override the node executable
    | (for example, a full path). `node_cwd` sets the working directory used
    | when running the temporary script so Node can resolve `node_modules`.
    | `node_timeout` is the timeout in seconds for the spawned process.
    |
    */

    'node_binary' => env('CEP_QUERY_NODE_BINARY', env('NODE_BINARY', 'node')),

    // Where Node should resolve node_modules from. Defaults to Laravel base_path() or current working dir.
    'node_cwd' => env('CEP_QUERY_NODE_CWD', null),

    // Timeout in seconds for Node process execution.
    'node_timeout' => env('CEP_QUERY_NODE_TIMEOUT', 120),
];
