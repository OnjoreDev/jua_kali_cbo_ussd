<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class SetPinState implements UssdStateHandlerInterface
{
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        // 1. Validate PIN format (must be 4 digits)
        if (!preg_match('/^\d{4}$/', trim($lastInput))) {
            return "CON Invalid PIN. Please enter a 4-digit numeric PIN:";
        }

        // 2. Call API to set PIN
        $response = $utility->callApi('POST', '/auth/set-pin', [
            'phone' => $msisdn,
            'pin'   => trim($lastInput)
        ]);

        // 3. Handle API Response
        if (isset($response['status']) && $response['status'] === 'success') {
            // Registration complete: Move to Main Menu
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Registration successful! Welcome to Jua Kali CBO.\n"
                . "1. Check Balance\n"
                . "2. Welfare\n"
                . "3. Chama Points\n"
                . "4. Loan Request\n"
                . "5. Withdraw Request\n"
                . "6. Customer Care"; 
        }

        // 4. Handle Failure
        return "END Registration failed. Please try again later.";
    }
}