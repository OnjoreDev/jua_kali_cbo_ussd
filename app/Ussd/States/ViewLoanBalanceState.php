<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class ViewLoanBalanceState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        // Fetch ledger balances using your corrected model function
        $walletData = $utility->getMemberBalances($msisdn);

        if (!$walletData) {
            return "END Failed to retrieve your loan records. Please try again later.";
        }

        // Format and return the outstanding balance string
        $loanBalance = number_format((float)($walletData['loan_balance'] ?? 0), 2);
        return "END Your Outstanding Loan Balance is KES {$loanBalance}.";
    }
}