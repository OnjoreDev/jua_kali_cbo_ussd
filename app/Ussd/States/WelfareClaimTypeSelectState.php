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

        //check the selected option 1 is for medical 2 is for bereavement
        // In App\Ussd\States\WelfareClaimTypeSelectState.php

        if ($lastInput === "1" || $lastInput === "2") {
            $claimType = ($lastInput === "1") ? "medical" : "bereavement";

            // Call the updated method
            $response = $utility->createWelfareClaim($msisdn, $claimType);

            if (isset($response['status']) && $response['status'] === 'success') {
                return "END Claim submitted successfully. Tracking: {$response['tracking']}. SMS sent.";
            }

            // Check if it's the "Already active" error
            if (isset($response['message']) && str_contains($response['message'], 'active welfare claim')) {
                return "END You already have an active welfare claim. Please wait for its resolution.";
            }

            // Otherwise, it's a generic system error
            return "END System connection error. Please try again later.";
        }

        return "CON [Invalid Choice!]\n\nSelect Welfare Claim Type:\n1. Medical Benefit\n2. Bereavement Support\n0. Back";
    }
}
