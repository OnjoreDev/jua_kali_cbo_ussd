<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class WelfareClaimTypeSelectState implements UssdStateHandlerInterface
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

        if ($lastInput === "1" || $lastInput === "2") {
            $utility->saveInput($lastInput, $sessionId);
            $claimType = ($lastInput === "1") ? "medical" : "bereavement";

            $isClaimed = $utility->createWelfareClaim($msisdn, $claimType);
            
            if ($isClaimed) {
                return "END Claim submitted successfully. Tracking confirmation sent via SMS.";
            } else {
                return "END System connection error filing claim. Please try later.";
            }
        }

        return "CON [Invalid Choice!]\n\nSelect Welfare Claim Type:\n1. Medical Benefit\n2. Bereavement Support\n0. Back";
    }
}