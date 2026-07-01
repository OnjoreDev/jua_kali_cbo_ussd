<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class ExecutePointsRedemptionState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        // Handle Back Navigation
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->setTemplevel($sessionId, "ChamaPointsMenu");
            return "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n0. Back";
        }

        // 1. Sanitize: Ensure input is always a positive integer
        $pointsToRedeem = abs((int)$lastInput);
        
        // 2. Validate: Enforce database minimum value of 200
        if ($pointsToRedeem < 200) {
            return "CON [Invalid Input!]\nMinimum redemption is 200 points.\n\nEnter points to redeem:\n0. Back";
        }

        // 3. Process: Call the updated Utility method
        $result = $utility->redeemChamaPoints($msisdn, $pointsToRedeem);

        if ($result['success']) {
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "END " . $result['message'] . ". You will receive an SMS confirmation shortly.";
        } else {
            return "END Error: " . $result['message'];
        }
    }
}