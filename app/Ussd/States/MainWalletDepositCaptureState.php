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
        
        // 1. Navigation
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome to Jua Kali CBO. Select an option:\n1. Check Balance\n2. Welfare\n3. Chama Points\n4. Loan Request\n5. Withdraw Request\n6. Customer Care";
        }

        // 2. Resolve Member
        $member = $utility->getMemberByPhone($msisdn);
        if (!$member || !isset($member['id'])) {
            return "END System error: Member account not recognized.";
        }

        // 3. Validate Amount Input
        $amountRaw = trim($lastInput);
        if (!is_numeric($amountRaw) || (int)$amountRaw <= 0 || (int)$amountRaw > 70000) {
            return "CON [Invalid Amount! Enter 1 - 70,000]\n\nEnter Amount to Deposit:\n00. Back";
        }

        // 4. Perform Deposit
        $success = $utility->depositToMain((int)$member['id'], (int)$amountRaw);
        
        return $success ? "END Success! KES " . number_format((float)$amountRaw, 2) . " deposited." : "END Deposit failed.";
    }
}