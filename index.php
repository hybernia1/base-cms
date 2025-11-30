<?php
declare(strict_types=1);

// Composer autoload
require __DIR__ . '/vendor/autoload.php';

// bootstrap inicializuje RedBean, Twig, router,
// načte routy a spustí je
require __DIR__ . '/src/App/bootstrap.php';
