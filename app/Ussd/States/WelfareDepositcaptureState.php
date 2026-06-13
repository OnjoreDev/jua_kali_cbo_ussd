<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

/**
 * WelfareDepositCaptureState Class
 *
 * Validates subscription parameters for the Welfare fund and dispatches an M-Pesa STK prompt 
 * routing asynchronously through the explicit Welfare wallet callback endpoint.
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
        
        // Handle Back navigation explicitly to return to the parent Welfare sub-dashboard options menu
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "WelfareMenuSelect");
            
            return "CON Welfare Hub:\n"
                . "1. Deposit\n"
                . "2. Claim\n"
                . "3. Status\n"
                . "00. Back";
        }

        // Standard Validation: Clean spaces and verify input is an entirely positive numeric string
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            return "CON [Invalid Amount!]\n\nPlease enter a valid amount greater than 0 to deposit to Welfare Fund:\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$amountRaw;

        // Isolate and fetch the explicit Welfare fund callback path from the .env configuration
        $callbackUrl = $_ENV['MPESA_CALLBACK_URL_WELFARE'] ?? '';

        // Fire out the STK Push command via your core Utility Model.
        $isDispatched = $utility->initiateStkPush($msisdn, $amount, "WelfareContribution", $callbackUrl);

        if ($isDispatched) {
            return "END An M-Pesa STK push prompt has been sent to your phone.\n\nPlease enter your M-Pesa PIN on the pop-up screen to complete your contribution of KES " . number_format($amount, 2) . ".";
        } else {
            return "END System technical hitch handling your M-Pesa request. Please try again later.";
        }
    }
}