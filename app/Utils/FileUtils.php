<?php

namespace App\Utils;


use function unlink;
use function exec;
use function shell_exec;
use function escapeshellarg;
class FileUtils
{
    public const PATH_LOGS = __DIR__ . '/../../storage/logs/';
}