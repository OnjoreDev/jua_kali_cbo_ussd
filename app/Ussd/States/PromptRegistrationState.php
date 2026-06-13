<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class PromptRegistrationState implements UssdStateHandlerInterface{

      /**
      * This class handles the initial prompt screen 
      * where unregistered users are asked to opt-in to the registration flow.
      */
     public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility):string{
       if ($lastInput === "1") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "CaptureName");
            return "CON Please enter your Full Name:";
        }

        return "END Registration cancelled. You must press 1 to register.";
     }
}