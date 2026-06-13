<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class WelfareMenuState implements UssdStateHandlerInterface
{
    /**
     * Helper to render the clean Main Menu text dashboard layout.
     */
    private function renderMainMenuText(): string
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
        
        // Handle Back navigation cleanly to return to the parent portal dashboard
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "MemberMainMenu");
            return $this->renderMainMenuText();
        }

        switch ($lastInput) {
            case "1":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "WelfareDepositCapture");
                return "CON Enter Amount to Deposit to Welfare Wallet:\n00. Back";

            case "2":
                $utility->saveInput($lastInput, $sessionId);
                $utility->setTemplevel($sessionId, "WelfareClaimTypeSelect");
                return "CON Select Welfare Claim Type:\n1. Medical Benefit\n2. Bereavement Support\n00. Back";

            case "3":
                $utility->saveInput($lastInput, $sessionId);

                // Fetch claims and balances safely
                $claims = $utility->getWelfareClaimsList($msisdn);
                $wallets = $utility->getMemberBalances($msisdn);

                // Enforce array types to prevent foreach loops from crashing on empty/false data
                $claims = is_array($claims) ? $claims : [];
                $wallets = is_array($wallets) ? $wallets : [];

                $welfareBal = 0.0;
                foreach ($wallets as $w) {
                    // Cast to int to make sure comparison matches database strings safely
                    if (isset($w['wallet_type_id']) && (int)$w['wallet_type_id'] === 2) {
                        $welfareBal = (float)$w['balance'];
                        break;
                    }
                }

                $ussdResponse = "CON Welfare Hub Standing:\n"
                    . "Fund Balance: KES " . number_format($welfareBal, 2) . "\n\n"
                    . "Recent Claims:\n";

                if (empty($claims)) {
                    $ussdResponse .= "No logged claims found.\n";
                } else {
                    foreach ($claims as $c) {
                        // Check if key columns exist before using them to prevent Notice/Warning errors
                        $trackingNo = $c['tracking_number'] ?? 'N/A';
                        $statusRaw = $c['status'] ?? 'pending';

                        $statusLabel = match ($statusRaw) {
                            'pending_docs' => 'Pending Docs',
                            'reviewing'    => 'In Review',
                            'approved'     => 'Approved',
                            'rejected'     => 'Rejected',
                            'disbursed'    => 'Paid Out',
                            default        => ucfirst($statusRaw)
                        };
                        $ussdResponse .= "- " . strtoupper($trackingNo) . " [{$statusLabel}]\n";
                    }
                }
                
                $ussdResponse .= "\n00. Back";
                
                // Point to your generic back route state for the NEXT user interaction
                $utility->setTemplevel($sessionId, "GenericBackRoute");
                return $ussdResponse;

            default:
                return "CON [Invalid Choice!]\n\nWelfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
        }
    }
}