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
        if ($lastInput === "0" || $lastInput === "00") {
            $utility->saveInput($lastInput, $sessionId);
            $utility->setTemplevel($sessionId, "ChamaPointsMenu");
            return "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n0. Back";
        }

        // Validate numeric inputs
        if (!is_numeric($lastInput) || (float)$lastInput <= 0) {
            return "CON [Invalid Input! Please specify a positive number of points]\n\nEnter Points to redeem:";
        }

        $utility->saveInput($lastInput, $sessionId);
        $pointsToRedeem = floor((float)$lastInput);
        $wallets = $utility->getMemberBalances($msisdn);

        $currentPoints = 0.0;
        if (is_array($wallets)) {
            foreach ($wallets as $w) {
                if ((int)$w['wallet_type_id'] === 3) {
                    $currentPoints = (float)$w['balance'];
                }
            }
        }

        // Balance Check Validation
        if ($pointsToRedeem > $currentPoints) {
            return "CON [Redemption failed! You have {$currentPoints} points]\n\n0. Go back";
        }

        // 1 Point = KES 100 conversion rule
        $cashValue = $pointsToRedeem * 100;

        // Generate tracking reference code
        $sharedRef = strtoupper(bin2hex(random_bytes(4)));

        // Execute ledger entries in database via model methods
        $utility->logDisbursement($msisdn, 3, $pointsToRedeem, "Points redemption swap", "DSB-PTS" . $sharedRef);
        $utility->logReceipt($msisdn, 1, $cashValue, "Cash swap from points conversion", "RCP-PTS" . $sharedRef);

        $remainingPoints = $currentPoints - $pointsToRedeem;

        // Dispatch outbound confirmation SMS
        $smsMessage = "Confirmed: You have successfully redeemed {$pointsToRedeem} Chama Points for KES " . number_format($cashValue, 2) . ". Your remaining balance is {$remainingPoints} Points.";
        $utility->sendSMS($msisdn, $smsMessage);

        return "END Conversion Successful!\n"
            . "Added KES " . number_format($cashValue, 2) . " to your Main Wallet.\n"
            . "Remaining Balance: {$remainingPoints} Points.";
    }
}