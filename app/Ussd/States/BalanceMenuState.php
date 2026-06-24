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
        // 1. Get Member ID using the phone number
        $member = $utility->getMemberByPhone($msisdn);
        
        if (!$member) {
            return "END Error: Member account not found.";
        }
        
        // 2. Handle Back navigation
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome to Jua Kali CBO. Select an option:\n1. Check Balance\n2. Welfare\n3. Chama Points\n4. Loan Request\n5. Withdraw Request\n6. Customer Care";
        }

        // 3. Validate Selection (Mapping 1->Main, 2->Welfare, 3->Chama Points)
        if (!in_array($lastInput, ["1", "2", "3"], true)) {
            return "CON [Invalid Selection!]\n\nSelect Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Chama Points\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        
        // Map input to internal naming convention
        $targetName = ($lastInput === "1") ? 'main' : (($lastInput === "2") ? 'welfare' : 'chama points');
        
        // Fetch balances (Expects array with 'wallet_name' and 'balance' keys)
        $wallets = $utility->getMemberBalances($msisdn);
        $selectedWallet = null;

        if (is_array($wallets)) {
            foreach ($wallets as $w) {
                if (strtolower($w['wallet_name']) === $targetName) {
                    $selectedWallet = $w;
                    break;
                }
            }
        }

        // 4. Handle Result
        if ($selectedWallet !== null) {
            $formattedBal = number_format((float)$selectedWallet['balance'], 2);
            $name = ucfirst($selectedWallet['wallet_name']);
            
            // Set currency symbol based on wallet name
            $symbol = ($name === 'Chama points') ? 'Pts' : 'KES';
            
            $message = "Your {$name} balance is {$symbol} {$formattedBal}.";

            if ($lastInput === "1") { // Main Wallet specific flow
                $utility->setTemplevel($sessionId, "MainWalletDirectAction");
                return "CON {$message}\n1. Make Deposit\n00. Back";
            } else {
                $utility->setTemplevel($sessionId, "GenericBackRoute");
                return "CON {$message}\n00. Back";
            }
        }

        $utility->setTemplevel($sessionId, "GenericBackRoute");
        return "CON Account has no data records.\n0. Back";
    }
}