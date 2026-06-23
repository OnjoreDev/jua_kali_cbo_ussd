<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class VerifyOtpState implements UssdStateHandlerInterface
{
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        // 1. Initial prompt if this is the first entry to the state
        if (trim($lastInput) === "") {
            return "CON Please enter the 4-digit OTP sent to your phone:";
        }

        // 2. Validate OTP format
        if (strlen(trim($lastInput)) !== 4) {
            return "CON Invalid format. Please enter the 4-digit OTP:";
        }

        // 3. Call API to verify OTP
        $response = $utility->callApi('POST', '/auth/verify-otp', [
            'phone' => $msisdn,
            'otp'   => trim($lastInput)
        ]);

        // 4. Handle API Response
        if (isset($response['status']) && $response['status'] === 'success') {
            // Move to PIN Setup
            $utility->setTemplevel($sessionId, "SetPinState");
            return "CON OTP verified! Please set your 4-digit PIN:";
        }

        // 5. Handle Failure
        $errorMessage = $response['message'] ?? 'Invalid OTP. Please try again.';
        return "CON " . $errorMessage . "\n\nPlease enter the correct OTP:";
    }
}