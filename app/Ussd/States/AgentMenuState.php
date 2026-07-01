<?php
declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class AgentMenuState implements UssdStateHandlerInterface
{
    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        if (empty($lastInput)) {
            return "CON M-Pesa Agent Hub:\n1. Deposit (2 Pts)\n2. Withdraw (15 Pts)\n0. Back";
        }

        switch ($lastInput) {
            case '1':
                $utility->setTemplevel($sessionId, 'AgentCapturePhoneDeposit');
                return "CON Enter Customer Phone Number for Deposit:";
            case '2':
                $utility->setTemplevel($sessionId, 'AgentCapturePhoneWithdrawal');
                return "CON Enter Customer Phone Number for Withdrawal:";
            case '0':
                $utility->setTemplevel($sessionId, 'MemberMainMenu');
                return "CON Back to Main Menu...";
            default:
                return "CON Invalid. M-Pesa Agent Hub:\n1. Deposit\n2. Withdraw\n0. Back";
        }
    }
}