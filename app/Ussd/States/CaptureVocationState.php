<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureVocationState implements UssdStateHandlerInterface
{
    /**
     * Captures vocation, registers the member, and transitions to VerifyOtpState.
     */
    public function handle(
        string $sessionId,
        string $msisdn,
        string $lastInput,
        array $inputArray,
        Utility $utility
    ): string {
        // 1. Validate the input using the existing helper
        if (!$this->isValidVocation($lastInput)) {
            return "CON [Invalid Vocation! Use letters/dashes only, 3-30 chars]\n\nPlease enter your Vocation:";
        }

        // 2. Extract the Name dynamically
        // Since inputArray contains the full history (e.g., "*265#", "1", "Name"),
        // the name is the last captured input *before* this current vocation input.
        $allInputs = $utility->getSessionInputArray($sessionId); 
        
        // Ensure we clean the inputs to get just the name
        // Assuming your flow saves the name at the step before vocation
        $fullName = !empty($allInputs) ? end($allInputs) : 'Unknown Member';
        $vocation = trim($lastInput);

        // 3. Save the vocation input to the DB trail
        $utility->saveInput($vocation, $sessionId);

        // 4. Call the registration API
        // Ensure this returns true. Check your API error logs if this fails.
        $isRegistered = $utility->registerNewMember($fullName, $msisdn, $vocation);

        if ($isRegistered) {
            $utility->setTemplevel($sessionId, "VerifyOtpState");
            return "CON Registration initiated. An OTP has been sent. Please enter the OTP:";
        }

        return "END System error during registration. Please check logs for API failure.";
    }

    private function isValidVocation(string $vocation): bool
    {
        $vocation = trim($vocation);
        return (bool) preg_match('/^[a-zA-Z\s\-]{3,30}$/', $vocation);
    }
}