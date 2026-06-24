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
        // You need to ensure getMemberByPhone exists in your Utility model
        $member = $utility->getMemberByPhone($msisdn);
        
        if (!$member) {
            return "END Error: Member account not found.";
        }
        
        $memberId = (int)$member['id'];

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
        
        // Map input to wallet_type_id (Based on your logic: 1->1, 2->2, 3->4)
        $targetTypeId = ($lastInput === "1") ? 1 : (($lastInput === "2") ? 2 : 4);
        
        // Fetch balances using the member ID
        $wallets = $utility->getMemberBalances($memberId);
        $selectedWallet = null;

        if (is_array($wallets)) {
            foreach ($wallets as $w) {
                if ((int)$w['wallet_type_id'] === $targetTypeId) {
                    $selectedWallet = $w;
                    break;
                }
            }
        }

        // 4. Handle Result
        if ($selectedWallet !== null) {
            $symbol = (strtolower($selectedWallet['currency'] ?? '') === 'ksh') ? 'KES' : 'Pts';
            $formattedBal = number_format((float)$selectedWallet['balance'], 2);
            
            // Generate the specific message
            $message = "Your " . ucfirst($selectedWallet['wallet_name']) . " balance is {$symbol} {$formattedBal}.";
            
            // Send SMS for THIS specific wallet only
            // Ensure you have a sendSms method in your Utility class
            //$utility->sendSms($msisdn, $message);

            if ($targetTypeId === 1) {
                $utility->setTemplevel($sessionId, "MainWalletDirectAction");
                return "CON {$message}\n1. Make Deposit\n00. Back";
            } else {
                $utility->setTemplevel($sessionId, "GenericBackRoute");
                return "CON {$message}\n00. Back";
            }
        }

        $utility->setTemplevel($sessionId, "GenericBackRoute");
        return "CON Account has no data records.\n00. Back";
    }
}