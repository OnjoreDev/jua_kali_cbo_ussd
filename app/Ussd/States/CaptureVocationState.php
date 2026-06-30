<?php

declare(strict_types=1);

namespace App\Ussd\States;

use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class CaptureVocationState implements UssdStateHandlerInterface
{
    /**
     * Captures vocation, registers the member, and ends the session for SMS OTP receipt.
     */
    public function handle(
        string $sessionId,
        string $msisdn,
        string $lastInput,
        array $inputArray,
        Utility $utility
    ): string {
        // 1. Validate the input
        if (!$this->isValidVocation($lastInput)) {
            return "CON [Invalid Vocation! Use letters/dashes only, 3-30 chars]\n\nPlease enter your Vocation:";
        }

        // 2. Extract the Name dynamically from the DB history trail
        $allInputs = $utility->getSessionInputArray($sessionId); 
        $fullName = !empty($allInputs) ? end($allInputs) : 'Unknown Member';
        $vocation = trim($lastInput);

        // 3. Save the vocation input to the DB trail
        $utility->saveInput($vocation, $sessionId);

        // 4. Call the registration API endpoint securely using callApi
        $response = $utility->callApi('POST', '/auth/register', [
            'name'     => $fullName,
            'phone'    => $msisdn,
            'vocation' => $vocation
        ]);

        // 5. Check if the API registration succeeded
        if (isset($response['status']) && $response['status'] === 'success') {
            // Clear or park the state safely to InitialGateway so the next dial starts fresh
            $utility->setTemplevel($sessionId, "InitialGateway");
            
            return "END Registration initiated successfully! An OTP has been sent via SMS. Please redial the code once received to complete setup.";
        }

        // 6. Handle backend validation or duplicate phone failure gracefully
        $errorMessage = $response['message'] ?? 'System error during registration.';
        return "END " . $errorMessage;
    }

    private function isValidVocation(string $vocation): bool
    {
        $vocation = trim($vocation);
        if (strlen($vocation) < 3 || strlen($vocation) > 30) {
            return false;
        }
        return (bool)preg_match('/^[a-zA-Z\s\-]+$/', $vocation);
    }
}