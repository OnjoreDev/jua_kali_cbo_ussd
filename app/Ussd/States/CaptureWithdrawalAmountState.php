<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureWithdrawalAmountState implements UssdStateHandlerInterface
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

        // 1. Enforce date restrictions exactly as handled in your controller
        $currentDay = (int)date('j');
        $allowedDays = [1, 3, 5, 15];

        if (!in_array($currentDay, $allowedDays, true)) {
            return "END Withdrawal Restriction!\n"
                . "Withdrawals can only be requested on the 1st, 3rd, 5th, or 15th of the month.\n"
                . "Current Day: " . date('d-M-Y') . ". Access Denied.";
        }

        // 2. Validate numeric input range (1 to 70,000)
        if (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
            return "CON [Invalid Amount! Enter a value between 1 and 70,000]\n\nEnter Amount to Withdraw:\n0. Back";
        }

        $amount = (float)$lastInput;
        $wallets = $utility->getMemberBalances($msisdn);
        
        $mainBalance = 0.0;
        if (is_array($wallets)) {
            foreach ($wallets as $w) {
                if ((int)$w['wallet_type_id'] === 1) {
                    $mainBalance = (float)$w['balance'];
                }
            }
        }

        // 3. Validate against available Main Wallet funds
        if ($amount > $mainBalance) {
            $formattedBal = number_format($mainBalance, 2);
            return "CON [Insufficient Balance!]\n"
                . "Your Main Wallet has KES {$formattedBal}.\n"
                . "Please enter a lesser amount:\n"
                . "0. Back";
        }

        // 4. Process the validated withdrawal request
        $utility->saveInput($lastInput, $sessionId);
        $isProcessed = $utility->processWithdrawal($msisdn, $amount);

        if ($isProcessed) {
            return "END Withdrawal request of KES " . number_format($amount, 2) . " has been received.\nFunds will be sent via M-Pesa shortly.";
        } 
        
        return "END System error processing your withdrawal. Please try again later.";
    }
}