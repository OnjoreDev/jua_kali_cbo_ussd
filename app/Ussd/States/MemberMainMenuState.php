<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class MemberMainMenuState implements UssdStateHandlerInterface
{
    /**
     * Renders the menu. Now dynamically checks if the member is an agent.
     */
    private function renderMenuText(bool $isAgent): string
    {
        $menu = "CON Welcome to Jua Kali CBO. Select an option:\n"
            . "1. Check Balance\n"
            . "2. Welfare\n"
            . "3. Chama Points\n"
            . "4. Loan Request\n"
            . "5. Withdraw Request\n"
            . "6. Customer Care";
        
        if ($isAgent) {
            $menu .= "\n7. Agent Hub"; // Added option for agents
        }

        return $menu;
    }

    public function handle(
        string $sessionId,
        string $msisdn,
        string $lastInput,
        array $inputArray,
        Utility $utility
    ): string {
        // 1. Fetch role status via Utility (which calls the API)
        $isAgent = $utility->hasRole($msisdn, 'agent');

        // Handle session entry or navigation reset
        if ($lastInput === "" || $lastInput === "39" || $lastInput === "00") {
            return $this->renderMenuText($isAgent);
        }

        switch ($lastInput) {
            case "1":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "BalanceMenu");
                return "CON View Balance for:\n1. Main Wallet\n2. Welfare Wallet\n3. Chama Points Balance\n0. Back";

            case "2":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "WelfareMenu");
                return "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n0. Back";

            case "3":
                $points = $utility->getChamaPointsBalance($msisdn);
                if ($points <= 0) {
                    return "CON You have 0 Chama Points. You cannot redeem at this time.\n0. Back";
                }
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "ChamaPointsMenu");
                return "CON Chama Points Hub (Bal: {$points}):\n1. View Points Balance\n2. Redeem Points\n0. Back";

            case "4":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "CaptureLoanAmount");
                return "CON Enter Loan Amount Request:\n0. Back";

            case "5":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "CaptureWithdrawalAmount");
                return "CON Enter Amount to Withdraw from Main Wallet:\n0. Back";

            case "6":
                $utility->saveInput($lastInput, $sessionId);
                if (!$utility->isMemberRegistered($msisdn)) {
                    return "END You are not a registered member. Please register to access Customer Care.";
                }
                $success = $utility->requestCustomerCareSms($msisdn);
                return $success 
                    ? "END Support details have been sent to your phone. Thank you." 
                    : "END Sorry, we could not process your request at this time.";

            case "7":
                if ($isAgent) {
                    $utility->setTemplevel($sessionId, "AgentMenu");
                    return "CON M-Pesa Agent Hub:\n1. Deposit Chama Points\n2. Withdraw Chama Points\n0. Back";
                }
                return "CON [Invalid Choice!]\n" . $this->renderMenuText($isAgent);

            case "0":
                return $this->renderMenuText($isAgent);
                        
            default:
                return "CON [Invalid Choice!]\n" . $this->renderMenuText($isAgent);
        }
    }
}