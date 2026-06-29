<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

/**
 * WelfareDepositCaptureState Class
 * Manages input validation and initiates async M-Pesa STK push for welfare contributions
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
        
        // Handle Back navigation to the primary Welfare Menu
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "WelfareMenuSelect");
            
            return "CON Welfare Hub:\n"
                . "1. Deposit\n"
                . "2. Claim\n"
                . "3. Status\n"
                . "0. Back";
        }

        // 1. Validate the user amount input
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            return "CON [Invalid Amount!]\n\nPlease enter a valid amount greater than 0 to deposit to Welfare Fund:\n0. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$amountRaw;

        // 2. Look up the member profile records by their active MSISDN string
        $memberLookup = $utility->callApi('GET', '/member/find-by-phone/' . $msisdn);
        
        // FIX: Read 'id' directly from the root of the response array to match your API schema
        if (empty($memberLookup['id'])) {
            return "END System Error: Your membership record could not be verified automatically (Phone: " . $msisdn . "). Please contact support.";
        }

        // Safely extract the matching database primary key ID from the root level
        $memberId = (int) $memberLookup['id'];

        // 3. Dispatch the request to the backend to initialize the M-Pesa STK Push sequence
        $isStkDispatched = $utility->depositToWelfare($msisdn, $amount, $memberId);

        if ($isStkDispatched) {
            return "END Request received successfully! An M-Pesa PIN prompt for KES " . number_format($amount, 2) . " has been sent to your device. Please complete the prompt to credit your Welfare account.";
        }

        return "END System busy. We could not trigger the payment gateway at this moment. Please try again shortly.";
    }
}