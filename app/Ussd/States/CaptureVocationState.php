<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureVocationState implements UssdStateHandlerInterface
{
    /**This class captures the vocation, safely parses the name from the input trail array, 
     * commits the registration transaction into your database, 
     * and sends out the welcome text message via Celcom Africa 
     * */
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        if (!$this->isValidVocation($lastInput)) {
            return "CON [Invalid Vocation! Use letters/dashes only, 3-30 chars]\n\nPlease enter your Vocation:";
        }

        $utility->saveInput($lastInput, $sessionId);
        
        // Grab the name from the previous step position in the array index
        $totalElements = count($inputArray);
        $fullName = $inputArray[$totalElements - 2] ?? 'Unknown Member';
        $vocation = $lastInput;

        $isRegistered = $utility->registerNewMember($fullName, $msisdn, $vocation);
        
        if ($isRegistered) {
            return "END Thank you for registering, {$fullName}.\nPlease redial the code to view your menu.";
        }

        return "END System error during registration. Please try again later.";
    }

    private function isValidVocation(string $vocation): bool
    {
        $vocation = trim($vocation);
        return (bool) preg_match("/^[a-zA-Z\s\-]{3,30}$/", $vocation);
    }
}