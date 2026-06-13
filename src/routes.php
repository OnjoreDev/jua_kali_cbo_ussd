<?php

declare(strict_types=1);

use App\Controllers\ExampleController;
use App\Controllers\UtilityController;
use App\Controllers\MpesaCallbackController;
use Slim\App;

return function (App $app) {
    // Standard default home route
    $app->get('/', [ExampleController::class, 'index']);

    // Unified JuaKali CBO USSD Callback Route
    $app->get('/api/initiate', UtilityController::class)->setName('ussd-callback');

    // Asynchronous Safaricom Daraja M-Pesa Callback Webhook Route
   $app->post('/api/v1/mpesa/callback/main', MpesaCallbackController::class);    // Wallet Type 1
    $app->post('/api/v1/mpesa/callback/welfare', MpesaCallbackController::class); // Wallet Type 2
};