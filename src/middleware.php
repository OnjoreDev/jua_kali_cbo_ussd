<?php

declare(strict_types=1);

use Slim\App;

return function (App $app) {
    $c = $app->getContainer();
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $displayErrorDetails = $c->get('settings')['displayErrorDetails'];
    $app->addErrorMiddleware($displayErrorDetails, true, true);
};