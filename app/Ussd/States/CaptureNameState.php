<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureNameState implements UssdStateHandlerInterface
{
   /**This class validates that the input is a valid 
    * full name (letters only, at least two names) 
    *before moving the user forward. 
    */

    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        if (!$this->isValidFullName($lastInput)) {
            return "CON [Invalid Name! Enter First & Last Name, letters only]\n\nPlease enter your Full Name:";
        }

        $utility->saveInput($lastInput, $sessionId);
        $utility->setTemplevel($sessionId, "CaptureVocation");
        return "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
    }

    private function isValidFullName(string $name): bool
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        if (strlen($name) < 3 || strlen($name) > 50) {
            return false;
        }
        if (!preg_match("/^[a-zA-Z\s]+$/", $name) || count(explode(' ', $name)) < 2) {
            return false;
        }
        return true;
    }
}