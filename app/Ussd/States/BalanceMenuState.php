<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class BalanceMenuState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        // Handle Back navigation cleanly to Main Menu dashboard
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome to Jua Kali CBO. Select an option:\n1. Check Balance\n2. Welfare\n3. Chama Points\n4. Loan Request\n5. Withdraw Request\n6. Customer Care";
        }

        if (!in_array($lastInput, ["1", "2", "3"], true)) {
            return "CON [Invalid Selection! Choose 1, 2, or 3]\n\nSelect Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        
        // Match the legacy controller logic: Option 1 -> ID 1, Option 2 -> ID 2, Option 3 -> ID 4
        $targetTypeId = ($lastInput === "1") ? 1 : (($lastInput === "2") ? 2 : 4);
        $wallets = $utility->getMemberBalances($msisdn);
        $selectedWallet = null;

        if (is_array($wallets)) {
            foreach ($wallets as $w) {
                if ((int)$w['wallet_type_id'] === $targetTypeId) {
                    $selectedWallet = $w;
                    break;
                }
            }
        }

        if ($selectedWallet !== null) {
            $symbol = (strtolower($selectedWallet['currency']) === 'ksh') ? 'KES' : 'Pts';
            $formattedBal = number_format((float)$selectedWallet['balance'], 2);
            $utility->sendBalancesSms($msisdn);

            if ($targetTypeId === 1) {
                // Route to the new Direct Action branch for Main Wallet deposit field
                $utility->setTemplevel($sessionId, "MainWalletDirectAction");
                return "CON Your Main Wallet balance is {$symbol} {$formattedBal}.\n1. Make Deposit\n00. Back";
            } else {
                $utility->setTemplevel($sessionId, "GenericBackRoute");
                return "CON Your " . ucfirst($selectedWallet['wallet_name']) . " balance is {$symbol} {$formattedBal}.\n00. Back";
            }
        }

        $utility->setTemplevel($sessionId, "GenericBackRoute");
        return "CON Account has no data records.\n00. Back";
    }
}