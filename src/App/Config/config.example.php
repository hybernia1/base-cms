<?php

return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=dbname;charset=utf8mb4',
        'user' => 'dbuser',
        'pass' => 'dbpass',
    ],
    'mail' => [
        'transport' => 'mail',
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'user@example.com',
        'password' => 'secret',
        'from_email' => 'no-reply@example.com',
        'from_name'  => 'Moje CMS',
        'encryption' => 'tls', // nebo 'ssl' / ''
    ],
    'env' => 'prod',
    'installed' => false,
];
