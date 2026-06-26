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

        // 3. Validate Selection
        if (!in_array($lastInput, ["1", "2", "3"], true)) {
            return "CON [Invalid Selection!]\n\nSelect Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Chama Points\n00. Back";
        }

        $utility->saveInput($lastInput, $sessionId);
        
        // Map input to internal naming convention
        $targetName = ($lastInput === "1") ? 'main' : (($lastInput === "2") ? 'welfare' : 'chama points');
        
        // Fetch balances
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

        // 4. Handle Result with fallback for missing records
        // If $selectedWallet is null, we treat the balance as 0.00 to allow deposits
        $balance = ($selectedWallet !== null) ? (float)$selectedWallet['balance'] : 0.0;
        $formattedBal = number_format($balance, 2);
        $name = ucfirst($targetName);
        
        // Set currency symbol based on wallet type
        $symbol = ($name === 'Chama points') ? 'Pts' : 'KES';
        
        $message = "Your {$name} balance is {$symbol} {$formattedBal}.";

        // Main Wallet (ID 1) allows deposits
        if ($lastInput === "1") { 
            $utility->setTemplevel($sessionId, "MainWalletDirectAction");
            return "CON {$message}\n1. Make Deposit\n00. Back";
        } 
        
        // Other wallets currently display balance only
        $utility->setTemplevel($sessionId, "GenericBackRoute");
        return "CON {$message}\n00. Back";
    }
}