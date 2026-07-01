<?php

declare(strict_types=1);

use App\Controllers\UtilityController;
use Slim\App;

return function (App $app) {
    // Standard default home route

    // Unified JuaKali CBO USSD Callback Route
    $app->get('/api/initiate', UtilityController::class)->setName('ussd-callback');

    };