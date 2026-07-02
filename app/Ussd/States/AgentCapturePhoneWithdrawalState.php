<?php
declare(strict_types=1);

namespace App\Ussd\States; 
use App\Ussd\UssdStateHandlerInterface;
use App\Models\Utility;

class AgentCapturePhoneWithdrawalState implements UssdStateHandlerInterface
{
    private function normalizePhone(string $phone): string {
        $phone = trim($phone);
        return (preg_match('/^0[71][0-9]{8}$/', $phone)) ? '254' . substr($phone, 1) : $phone;
    }

    public function handle(string $sessionId, string $msisdn, string $lastInput, array $inputArray, Utility $utility): string
    {
        if (empty($lastInput)) return "CON Enter Customer Phone (e.g. 0712345678): \n0. Back";

        $formattedPhone = $this->normalizePhone($lastInput);
        if (!$utility->isMemberRegistered($formattedPhone)) {
            return "CON Member not found. Try again:\n0. Back";
        }

        // Save phone and trigger 15-point credit
        $utility->saveInput($sessionId, $formattedPhone);
        $utility->setTemplevel($sessionId, 'AgentProcessWithdrawalPoints');
        
        $result = $utility->addChamaPoints($formattedPhone, 15);
        return $result['success'] ? "END Success: 15 points added to {$formattedPhone}." : "END Error: " . $result['message'];
    }
}