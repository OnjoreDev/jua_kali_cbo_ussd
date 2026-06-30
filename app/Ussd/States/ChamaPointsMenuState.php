<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class ChamaPointsMenuState implements UssdStateHandlerInterface
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
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome back to your Community Portal\n1. Balance\n2. Welfare\n3. Chama Points\n4. Loan Requests\n5. Withdraw Cash\n6. Customer Care";
        }

        switch ($lastInput) {
            case "1": 
                // Uses the new utility method which triggers the API -> Controller -> SMS flow
                $points = $utility->getChamaPointsBalance($msisdn);
                
                $utility->setTemplevel($sessionId, "ChamaPointsMenu");
                return "CON Balance: {$points} Points. An SMS with details has been sent to you.\n0. Back";

            case "2": 
                // Moves to the next state to capture redemption amount
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "ExecutePointsRedemption");
                return "CON Enter Points to redeem (Min 10):\n0. Back";

            default:
                return "CON [Invalid Option!]\n\nChama Points Hub:\n1. View Points Balance\n2. Redeem Points\n0. Back";
        }
    }
}