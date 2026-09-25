<?php

return [
    'key' => env('TESTING_ACCESS_KEY', env('APP_ENV') === 'local' ? 'demo123' : null),

    'operator' => [
        'name' => env('TESTING_ACCESS_OPERATOR_NAME', 'Operador Demo'),
        'email' => env('TESTING_ACCESS_OPERATOR_EMAIL', 'testing@local.app'),
    ],
];
