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
        // Handle Back navigation to return to the parent portal dashboard
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            
            return "CON Welcome back to your Community Portal\n1. Balance\n2. Welfare\n3. Chama Points\n4. Loan Requests\n5. Withdraw Cash\n6. Customer Care";
        }

        // Validate that the input value is a positive numeric figure
        if (!is_numeric($lastInput) || (float)$lastInput <= 0) {
            return "CON [Invalid Input! Please enter a valid number value]\n\nEnter Loan Amount Request:\n0. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $loanAmount = (float)$lastInput;
        
        // Execute the loan entry process in the data layer
        $isSubmitted = $utility->createLoanRequest($msisdn, $loanAmount);
        
        if ($isSubmitted) {
            return "END Loan request of amount KES " . number_format($loanAmount, 2) . " has been received and is awaiting approval from the admins.";
        } 
        
        return "END System connection error processing loan request. Please retry later.";
    }
}