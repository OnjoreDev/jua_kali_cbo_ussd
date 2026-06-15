<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class PromptRegistrationState implements UssdStateHandlerInterface {

    /**
     * This class handles processing user choices submitted from the prompt screen
     * where unregistered users choose to opt-in or cancel registration.
     */
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string {
        
        // 1. The user viewed the text box and pressed "1"
        if ($lastInput === "1") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "CaptureName");
            return "CON Please enter your Full Name:";
        }

        // 2. The user was presented the option but pressed something else, or hit send empty
        return "END Registration cancelled. You must press 1 to register.";
    }
}