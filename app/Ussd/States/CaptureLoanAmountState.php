<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureLoanAmountState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        // 1. Back Navigation
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            
            return "CON Welcome back to your Community Portal\n1. Balance\n2. Welfare\n3. Chama Points\n4. Loan Requests\n5. Withdraw Cash\n6. Customer Care";
        }

        // 2. Input Validation (Sanitize for numeric only)
        if (!is_numeric($lastInput) || (float)$lastInput <= 0) {
            return "CON [Invalid Input! Please enter a valid number value]\n\nEnter Loan Amount Request:\n0. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $loanAmount = (float)$lastInput;

        // 3. API Execution via Utility Layer
        // We call the function we just added to Utility.php
        $isSubmitted = $utility->createLoanRequest($msisdn, $loanAmount);
        
        // 4. Response Logic
        if ($isSubmitted) {
            return "END Loan request of KES " . number_format($loanAmount, 2) . " has been received and is being processed by our admins. You will receive an SMS shortly.";
        } 
        
        // If the API call returns false, we tell the user there is an error
        return "END Request failed. Please ensure you do not have an existing pending loan and try again later.";
    }
}