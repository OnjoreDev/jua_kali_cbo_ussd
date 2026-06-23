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

            // 3. Success
            if (isset($response['status']) && $response['status'] === 'success') {
                $utility->setTemplevel($sessionId, "MemberMainMenu");
                return "CON Login successful! Welcome back.\n"
                . "1. Check Balance\n"
                . "2. Welfare\n"
                . "3. Chama Points\n"
                . "4. Loan Request\n"
                . "5. Withdraw Request\n"
                . "6. Customer Care";  
            }

            // 4. Failure: Display exact API error
            $errorMessage = $response['message'] ?? 'Access denied. Please check your PIN.';
            return "END " . $errorMessage;
        }

        return "END Invalid format. Please enter a 4-digit PIN.";
    }
}