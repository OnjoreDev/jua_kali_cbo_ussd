<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;
/****
 * This acts as a clean handler for any basic informational or 
 * fallback screen where any keyboard input takes them directly
 * back to the primary main dashboard menu.
 * 
 * 
 */


class GenericBackRouteState implements UssdStateHandlerInterface
{
    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        $utility->saveInput($lastInput, $sessionId);
        $utility->setTemplevel($sessionId, "MemberMainMenu");
        
        return "CON Welcome to Jua Kali CBO. Select an option:\n"
            . "1. Check Balance\n"
            . "2. Welfare\n"
            . "3. Chama Points\n"
            . "4. Loan Request\n"
            . "5. Withdraw Request\n"
            . "6. Customer Care";
    }
}