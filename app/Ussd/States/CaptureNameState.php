<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureNameState implements UssdStateHandlerInterface
{
    /**
     * Validates full name and transitions to vocation capture.
     */
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        // Ensure we are not accidentally processing a navigation code if it somehow leaked through
        if ($lastInput === "39" || $lastInput === "00") {
            return "CON [Invalid Input! Use letters for your name.]\n\nPlease enter your Full Name:";
        }

        // Capture the name (lastInput is the most recent element)
        $name = trim($lastInput);

        if (!$this->isValidFullName($name)) {
            return "CON [Invalid Name! Enter First & Last Name, letters only]\n\nPlease enter your Full Name:";
        }

        // Save ONLY the name
        $utility->saveInput($name, $sessionId);

        // Transition
        $utility->setTemplevel($sessionId, "CaptureVocation");
        return "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
    }

    private function isValidFullName(string $name): bool
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        // Name must be between 3 and 50 characters, contain only letters/spaces, 
        // and have at least two parts (First Last).
        if (strlen($name) < 3 || strlen($name) > 50) {
            return false;
        }
        if (!preg_match("/^[a-zA-Z\s]+$/", $name) || count(explode(' ', $name)) < 2) {
            return false;
        }
        return true;
    }
}
