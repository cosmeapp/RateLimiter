<?php

return [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default'
    ],
    'default' => [
        'limit_level' => 'user', // user | device | ip | api
        'udid_name' => 'mid',
        'get' => [1, 10], // 1秒请求10次
        'post' => [2, 1], // 2秒请求1次
    ],
    'api_gateway' => false,
    'api_limit' => [
        '/api/center/demo_api_demo1' => [1, 20],
        '/api/center/demo_api_demo2' => [
            'limit_level' => 'api',
            'rate' => [1, 20]
        ],
    ]
];
