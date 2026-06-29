<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class MainWalletDepositCaptureState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        
        // 1. Handle Navigation Back to Main Menu Select Screen
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome to Jua Kali CBO. Select an option:\n1. Check Balance\n2. Welfare\n3. Chama Points\n4. Loan Request\n5. Withdraw Request\n6. Customer Care";
        }

        // 2. Validate Amount Input (using the existing 70k constraint)
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 70000) {
            return "CON [Invalid Amount! Enter 1 - 70,000]\n\nEnter Amount to Deposit:\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$amountRaw;

        // 3. Resolve Member Context bindings explicitly by active MSISDN string identity.
        // We read from the root level to match your API schema direct object dump.
        $memberLookup = $utility->getMemberByPhone($msisdn); 
        
        if (empty($memberLookup['id'])) {
            return "END System Error: Your membership record could not be verified automatically. Please contact support.";
        }

        $memberId = (int) $memberLookup['id'];

        // 4. Perform Deposit Integration. Dispatch the async push thread context forward.
        $isStkDispatched = $utility->depositToMain($msisdn, $amount, $memberId);
        
        if ($isStkDispatched) {
            return "END Request received successfully! An M-Pesa PIN prompt for KES " . number_format($amount, 2) . " has been sent to your device. Please complete the prompt to credit your Main account.";
        }

        return "END System busy. We could not trigger the payment gateway at this moment. Please try again shortly.";
    }
}