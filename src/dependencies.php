<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Ussd\UssdStateRegistry;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Twig::class => function () {
            return Twig::create(__DIR__ . '/../resources', [
                'cache' => false,
            ]);
        },
        Logger::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');

            $loggerSettings = $settings['logger'];
            $logger = new Logger($loggerSettings['name']);

            // $processor = new UidProcessor();
            // $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        PDO::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['db'];

            $dsn = "{$settings['driver']}:host={$settings['host']};port={$settings['port']};dbname={$settings['name']};charset={$settings['charset']}";

            $pdo = new PDO($dsn, $settings['user'], $settings['password']);

            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $pdo;
        },
        // Registry for ussd states:
        UssdStateRegistry::class => function (ContainerInterface $c) {
            return new UssdStateRegistry($c);
        },
       
    ]);
   

};
