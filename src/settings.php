<?php

declare(strict_types=1);


use DI\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Monolog\Logger;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

return function (ContainerBuilder $cb) {
    $cb->addDefinitions([
        'settings' => [
            'displayErrorDetails' => $_ENV['APP_DEBUG'] === "true", // Should be set to false in production
            'logError' => false,
            'logErrorDetails' => false,
            'logger' => [
                'name' => 'app',
                'path' => __DIR__ . '/../storage/logs/app_' . date('Y-m-d') . '.log',
                'level' => Logger::DEBUG,
            ],
            'db' => [
                'driver' => 'mysql',
                'host' => $_ENV['DB_HOST'],
                'port' => $_ENV['DB_PORT'],
                'user' => $_ENV['DB_USER'],
                'password' => $_ENV['DB_PASS'],
                'name' => $_ENV['DB_NAME'],
                'charset' => $_ENV['DB_CHAR'],
            ],
        ],
    ]);
};