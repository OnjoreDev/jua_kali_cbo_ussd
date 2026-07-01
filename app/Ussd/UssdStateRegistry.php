<?php

declare(strict_types=1);

namespace App\Ussd;

use RuntimeException;

class UssdStateRegistry
{
    /**
     * Centralized map of state level tracking strings 
     * to their respective class implementations.
     */
    private array $stateMap = [
        // -------------------------------------------------------------
        // 1. REGISTRATION LIFECYCLE STATES
        // -------------------------------------------------------------
        'PromptRegistration'     => \App\Ussd\States\PromptRegistrationState::class,
        'CaptureName'            => \App\Ussd\States\CaptureNameState::class,
        'CaptureVocation'        => \App\Ussd\States\CaptureVocationState::class,

        // -------------------------------------------------------------
        // 2. CORE MEMBER DASHBOARD STATE
        // -------------------------------------------------------------
        'MemberMainMenu'         => \App\Ussd\States\MemberMainMenuState::class,

        // -------------------------------------------------------------
        // 3. BALANCE CHECKING STATES
        // -------------------------------------------------------------
        'BalanceMenu'              => \App\Ussd\States\BalanceMenuState::class,
        'MainWalletDirectAction'   => \App\Ussd\States\MainWalletDirectActionState::class,
        'MainWalletDepositCapture' => \App\Ussd\States\MainWalletDepositCaptureState::class,
        'GenericBackRoute'         => \App\Ussd\States\GenericBackRouteState::class,

        // -------------------------------------------------------------
        // 4. WELFARE HUB STATES
        // -------------------------------------------------------------
        'WelfareMenu'            => \App\Ussd\States\WelfareMenuState::class,
        'WelfareDepositCapture'  => \App\Ussd\States\WelfareDepositCaptureState::class,
        'WelfareClaimTypeSelect' => \App\Ussd\States\WelfareClaimTypeSelectState::class,

        // -------------------------------------------------------------
        // 5. CHAMA POINTS HUB STATES
        // -------------------------------------------------------------
        'ChamaPointsMenu'         => \App\Ussd\States\ChamaPointsMenuState::class,
        'ExecutePointsRedemption' => \App\Ussd\States\ExecutePointsRedemptionState::class,

        // -------------------------------------------------------------
        // 6. LOAN REQUEST STATES
        // -------------------------------------------------------------
        'CaptureLoanAmount'       => \App\Ussd\States\CaptureLoanAmountState::class,

        // -------------------------------------------------------------
        // 7. WITHDRAWAL STATES
        // -------------------------------------------------------------
        'CaptureWithdrawalAmount' => \App\Ussd\States\CaptureWithdrawalAmountState::class,
        //--------------------------------------------------------------
        //8. LOGIN STATE
        //----------------------------------------------------------------
        'LoginState' => \App\Ussd\States\LoginState::class,

        //------------------------------------------------------------------
        //9. OTP AND SET PIN
        //------------------------------------------------------------------- 
        'VerifyOtpState' => \App\Ussd\States\VerifyOtpState::class,
        'SetPinState'    => \App\Ussd\States\SetPinState::class,

        // -------------------------------------------------------------
        // 10. AGENT STATES
        // -------------------------------------------------------------
      // -------------------------------------------------------------
        // 10. AGENT STATES
        // -------------------------------------------------------------
        'AgentMenu'                     => \App\Ussd\States\AgentMenuState::class,
        'AgentCapturePhoneDeposit'      => \App\Ussd\States\AgentCapturePhoneDepositState::class,
        'AgentCapturePhoneWithdrawal'     => \App\Ussd\States\AgentCapturePhoneWithdrawalState::class
    ];  
    /**
     * Resolves and returns an instance of the requested state handler class.
     *
     * @param string $stateName The active state key fetched from the DB level tracking
     * @return UssdStateHandlerInterface
     * @throws RuntimeException If the state name isn't mapped inside the registry
     */
    public function getState(string $stateName): UssdStateHandlerInterface
    {
        if (!array_key_exists($stateName, $this->stateMap)) {
            throw new RuntimeException("USSD State Registry Error: State [{$stateName}] is not registered.");
        }

        $stateClass = $this->stateMap[$stateName];
        return new $stateClass();
    }
}
