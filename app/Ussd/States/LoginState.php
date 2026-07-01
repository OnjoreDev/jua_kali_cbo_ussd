<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class LoginState implements UssdStateHandlerInterface
{
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        // 1. Initial Prompt
        if ($lastInput === "" || $lastInput === "00") {
            return "CON Enter your 4-digit PIN:";
        }

        // 2. PIN Validation
        if (strlen($lastInput) === 4) {
            $response = $utility->callApi('POST', '/auth/login', [
                'phone' => $msisdn,
                'pin'   => $lastInput
            ]);

            // 3. Success: Delegate menu rendering to the Main Menu State
            if (isset($response['status']) && $response['status'] === 'success') {
                $utility->setTemplevel($sessionId, "MemberMainMenu");
                
                // Instead of hardcoding the menu string here, we call the MainMenuState
                // which already contains the dynamic logic to display '7. Agent Hub' 
                // if the member has the 'agent' role.
                return (new MemberMainMenuState())->handle($sessionId, $msisdn, "", [], $utility);
            }

            // 4. Failure
            $errorMessage = $response['message'] ?? 'Access denied. Please check your PIN.';
            return "END " . $errorMessage;
        }

        return "END Invalid format. Please enter a 4-digit PIN.";
    }
}