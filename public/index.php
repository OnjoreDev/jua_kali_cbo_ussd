<?php
ini_set('display_errors', '0');

use DI\ContainerBuilder;
use DI\Bridge\Slim\Bridge;
use Slim\Routing\RouteParser;

date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/../vendor/autoload.php';

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Set up settings
$settings = require __DIR__ . '/../src/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/../src/dependencies.php';
$dependencies($containerBuilder);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
$app = Bridge::create($container);
$container->set(RouteParser::class, $app->getRouteCollector()->getRouteParser());

// Register middleware
$middleware = require __DIR__ . '/../src/middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

// Run App & Emit Response
$app->run();