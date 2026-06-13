<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

/**
 * This processes the numeric payload input for simulated cash loading requests.
 * **/

class MainWalletDepositCaptureState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        if ($lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome to Jua Kali CBO. Select an option:\n1. Check Balance\n2. Welfare\n3. Chama Points\n4. Loan Request\n5. Withdraw Request\n6. Customer Care";
        }

        if (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
            return "CON [Invalid Amount! Enter a numeric value between 1 and 70,000]\n\nEnter Amount to Deposit:";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$lastInput;
        
        // Wallet ID 1 is Main Account
        $utility->processSimulatedDeposit($msisdn, 1, $amount);
        
        return "END KES " . number_format($amount, 2) . " credited to Main Wallet.";
    }
}