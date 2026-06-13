<?php

declare(declare_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class MemberMainMenuState implements UssdStateHandlerInterface
{
    /**
     * Renders the base dashboard menu string.
     */
    private function renderMenuText(): string
    {
        return "CON Welcome to Jua Kali CBO. Select an option:\n"
            . "1. Check Balance\n"
            . "2. Welfare\n"
            . "3. Chama Points\n"
            . "4. Loan Request\n"
            . "5. Withdraw Request\n"
            . "6. Customer Care";
    }

    public function handle(
        string $sessionId, 
        string $msisdn, 
        string $lastInput, 
        array $inputArray, 
        Utility $utility
    ): string {
        
        // FIX: If this is a fresh session dial or entry reset, bypass validation 
        // and instantly render the clean main menu dashboard.
        if ($lastInput === "" || $lastInput === "39" || $lastInput === "00") {
            return $this->renderMenuText();
        }

        switch ($lastInput) {
            case "1":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "BalanceMenu");
                return "CON View Balance for:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Balance\n0. Back";

            case "2":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "WelfareMenu");
                return "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n0. Back";

            case "3":
                $utility->saveInput($lastInput, $sessionId);
                $wallets = $utility->getMemberBalances($msisdn);
                
                $points = 0.0;
                if (is_array($wallets)) {
                    foreach ($wallets as $w) {
                        if ((int)$w['wallet_type_id'] === 3) {
                            $points = (float)$w['balance'];
                        }
                    }
                }

                if ($points <= 0) {
                    $utility->setTemplevel($sessionId, "MemberMainMenu");
                    return "CON You have 0 Chama Points. You cannot access the redemption menu.\n0. Back";
                }

                $utility->setTemplevel($sessionId, "ChamaPointsMenu");
                return "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n0. Back";

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
                $utility->sendCustomerCareAlert($msisdn);
                return "END Please call +254790727272 for dynamic customer support. Details have been texted to you.";

            default:
                // Prepend error warning prefix only if they actually input something invalid
                return "CON [Invalid Choice!]\n" . $this->renderMenuText();
        }
    }
}