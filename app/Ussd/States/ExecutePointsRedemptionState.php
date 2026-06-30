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

        // Validate numeric input
        $pointsToRedeem = (int)$lastInput;
        if ($pointsToRedeem < 10) {
            return "CON [Invalid Input!]\nMinimum redemption is 10 points.\n\nEnter points to redeem:\n0. Back";
        }

        // Call the new Utility method which hits the API
        $result = $utility->redeemChamaPoints($msisdn, $pointsToRedeem);

        if ($result['success']) {
            // Success response
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "END " . $result['message'] . ". You will receive an SMS confirmation shortly.";
        } else {
            // Failure response (e.g., insufficient points)
            return "CON Error: " . $result['message'] . "\nEnter a valid number of points:\n0. Back";
        }
    }
}