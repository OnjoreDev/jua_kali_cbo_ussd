<?php

namespace App\Controllers;


use Monolog\Logger;
use Psr\Container\ContainerInterface;

class Controller
{

    protected Logger $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(Logger::class);
    }
}