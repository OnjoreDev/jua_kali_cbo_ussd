<?php

namespace App\Controllers;

use Monolog\Logger;
use Slim\Http\Interfaces\ResponseInterface;
use Slim\Http\Response;
use Slim\Views\Twig;

class ExampleController {

    public function index(
        Response $response,
        Twig $view,
        Logger $logger
    ): ResponseInterface {
        $logger->info("INFO", [
            'Hello world',
        ]);

        return $view->render($response, 'home.twig', [ 'title' => 'home title']);
    }
}