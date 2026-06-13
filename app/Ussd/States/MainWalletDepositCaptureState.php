<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

/**
 * MainWalletDepositCaptureState Class
 *
 * Validates transaction limits for the primary account and pushes an M-Pesa STK prompt 
 * routing asynchronously through the explicit Main wallet callback endpoint.
 */
class MainWalletDepositCaptureState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        
        // Handle Back navigation explicitly to return to the core Main Menu
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            
            return "CON Welcome to Jua Kali CBO. Select an option:\n"
                . "1. Check Balance\n"
                . "2. Welfare\n"
                . "3. Chama Points\n"
                . "4. Loan Request\n"
                . "5. Withdraw Request\n"
                . "6. Customer Care";
        }

        // Clean white spaces and validate boundaries (1 to 70,000 KES structural limits)
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 70000) {
            return "CON [Invalid Amount! Enter a numeric value between 1 and 70,000]\n\nEnter Amount to Deposit:\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$amountRaw;
        
        // Isolate and fetch the explicit Main account callback path from the .env configuration
        $callbackUrl = $_ENV['MPESA_CALLBACK_URL_MAIN'] ?? '';
        
        // Dispatch the STK push request out to Safaricom Daraja
        $isDispatched = $utility->initiateStkPush($msisdn, $amount, "MainSavings", $callbackUrl);
        
        if ($isDispatched) {
            return "END An M-Pesa STK push prompt has been sent to your phone.\n\nPlease enter your M-Pesa PIN on the pop-up to authorize your Main Savings Wallet deposit of KES " . number_format($amount, 2) . ".";
        } else {
            return "END System technical hitch handling your M-Pesa request. Please try again later.";
        }
    }
}