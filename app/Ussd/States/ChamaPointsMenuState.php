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
        // Handle Back Navigation cleanly to return to the parent portal dashboard
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return "CON Welcome back to your Community Portal\n1. Balance\n2. Welfare\n3. Chama Points\n4. Loan Requests\n5. Withdraw Cash\n6. Customer Care";
        }

        switch ($lastInput) {
            case "1": 
                // DO NOT save input 1 here to match original controller behavior
                $wallets = $utility->getMemberBalances($msisdn);
                
                $points = 0.0;
                if (is_array($wallets)) {
                    foreach ($wallets as $w) {
                        if ((int)$w['wallet_type_id'] === 3) {
                            $points = (float)$w['balance'];
                        }
                    }
                }
                
                // Keep them inside this state context so pressing '0' functions as a back button
                $utility->setTemplevel($sessionId, "ChamaPointsMenu");
                return "CON Balance: {$points} Points \n0. Back";

            case "2": 
                // Option 2 saves the choice string to step into input capture
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "ExecutePointsRedemption");
                return "CON Enter Points to redeem:\n0. Back";

            default:
                return "CON [Invalid Option!]\n\nChama Points Hub:\n1. View Points Balance\n2. Redeem Points\n0. Back";
        }
    }
}