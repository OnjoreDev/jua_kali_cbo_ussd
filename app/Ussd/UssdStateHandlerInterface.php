<?php

declare(strict_types=1);

namespace App\Ussd;

use App\Models\Utility;

interface UssdStateHandlerInterface{
    /**
     * Executes logic for a specific menu state screen.
     * * @param string $sessionId  The USSD Session ID
     * @param string $msisdn     The customer phone number (automatically captured)
     * @param string $lastInput  The most recent button input pressed by the user
     * @param array $inputArray  The entire trail of inputs broken down into an array
     * @param Utility $utility   The shared data access model instance
     * @return string            The raw output response (starting with CON or END)
     */

    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray,Utility $utility):string;

}