<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

/**
 * WelfareDepositCaptureState Class
 */
class WelfareDepositCaptureState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        
        // Handle Back navigation
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "WelfareMenuSelect");
            
            return "CON Welfare Hub:\n"
                . "1. Deposit\n"
                . "2. Claim\n"
                . "3. Status\n"
                . "0. Back";
        }

        // Standard Validation
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            return "CON [Invalid Amount!]\n\nPlease enter a valid amount greater than 0 to deposit to Welfare Fund:\n0. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$amountRaw;

        // Perform the direct deposit via the API
        $isDeposited = $utility->depositToWelfare($msisdn, $amount);

        if ($isDeposited) {
            return "END Success! KES " . number_format($amount, 2) . " has been deposited to your welfare wallet.";
        } 
        
        return "END System error: Could not complete your deposit at this time. Please try again later.";
    }
}