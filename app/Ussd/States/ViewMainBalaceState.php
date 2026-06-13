<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class ViewMainBalanceState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        $walletData = $utility->getMemberBalances($msisdn);

        if (!$walletData) {
            return "END Failed to retrieve account records. Please contact support.";
        }

        $balance = number_format((float)($walletData['main_balance'] ?? 0), 2);
        return "END Your Main Wallet Balance is KES {$balance}.";
    }
}