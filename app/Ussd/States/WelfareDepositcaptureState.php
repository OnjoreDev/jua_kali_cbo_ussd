<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class WelfareDepositCaptureState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "WelfareMenu");
            return "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n0. Back";
        }

        // Validate range 1 to 70,000 precisely as originally declared
        if (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
            return "CON [Invalid Amount! Enter a value between 1 and 70,000]\n\nEnter Amount to Deposit:";
        }

        $utility->saveInput($lastInput, $sessionId);
        $amount = (float)$lastInput;
        
        // wallet_type_id = 2 represents Welfare
        $utility->processSimulatedDeposit($msisdn, 2, $amount);

        return "END KES " . number_format($amount, 2) . " credited to Welfare Wallet.";
    }
}