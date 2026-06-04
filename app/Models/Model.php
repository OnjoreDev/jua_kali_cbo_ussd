<?php

namespace App\Models;


use PDO;
use Monolog\Logger;

class Model
{
    public $logger;

    protected $pdo;


    public function __construct(PDO $pdo, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }
}