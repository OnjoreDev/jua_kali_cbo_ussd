<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class AgentMenuState implements UssdStateHandlerInterface
{
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        // Handle initial entry or a menu navigation reset
        if ($lastInput === "" || $lastInput === "00") {
            return "CON M-Pesa Agent Hub:\n1. Deposit (2 Pts)\n2. Withdraw (15 Pts)\n0. Back";
        }

        switch ($lastInput) {
            case '1':
                // Advance the session track state to the phone collection layout
                $utility->setTemplevel($sessionId, 'AgentCapturePhoneDeposit');
                return "CON Enter Customer Phone Number for Deposit:\n0. Back";

            case '2':
                // Advance the session track state to the phone collection layout
                $utility->setTemplevel($sessionId, 'AgentCapturePhoneWithdrawal');
                return "CON Enter Customer Phone Number for Withdrawal:\n0. Back";

            case '0':
                // Return tracking status back to the main dashboard layer
                $utility->setTemplevel($sessionId, 'MemberMainMenu');
                
                // Instantly resolve and display the member's core menu
                return (new MemberMainMenuState())->handle($sessionId, $msisdn, "", [], $utility);

            default:
                return "CON Invalid Choice!\nM-Pesa Agent Hub:\n1. Deposit (2 Pts)\n2. Withdraw (15 Pts)\n0. Back";
        }
    }
}