<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;
/*****
 * This handles the sub-menu response showing after a user 
 * views their main wallet balance, prompting them if they'd like to deposit cash.
 * 
 */


class MainWalletDirectActionState implements UssdStateHandlerInterface
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
            $utility->setTemplevel($sessionId, "BalanceMenu");
            return "CON Select Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
        }

        if ($lastInput === "1") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MainWalletDepositCapture");
            return "CON Enter Amount to Deposit to Main Wallet:\n00. Back";
        }

        return "CON [Invalid Input!]\n\nReply 1 to Deposit or 00 to go back.";
    }
}