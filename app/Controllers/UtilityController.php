<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * UtilityController handles incoming USSD requests for Jua Kali CBO.
 * It manages session states, registrations, deposits, withdrawals, and points systems.
 */
class UtilityController extends Controller
{
    // Instance of the Utility model to handle database/business logic operations
    private Utility $utility;

    /**
     * Inherits Logger from parent Controller and pulls Utility model from Container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
    }

    /**
     * Sanitizes and normalizes Kenyan MSISDNs
     * Converts local numbers starting with 07 or 01 to the international format (254...)
     */
    private function normalizePhoneNumber(string $phone): string
    {
        $phone = trim($phone);
        if (str_starts_with($phone, '07') || str_starts_with($phone, '01')) {
            $phone = '254' . substr($phone, 1);
        }
        return $phone;
    }

    /**
     * Sends a welcome SMS notification upon successful registration
     */
    private function sendSmsNotification(string $phone, string $message): void
    {
        try {
            $this->logger->info("Sending Registration SMS to {$phone}", ['message' => $message]);
            
            // TODO: Integrate your SMS Gateway here (e.g., Africa's Talking or Celcom Africa)
            // Example:
            // $smsGateway->send($phone, $message);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to send registration SMS to {$phone}: " . $e->getMessage());
        }
    }

    /**
     * Main Menu UI Block
     * Returns the plain text menu string prefixed with 'CON' to keep the session alive
     */
    private function renderMainMenu(): string
    {
        return "CON Welcome back to Jua Kali CBO. Select an option:\n"
             . "1. Check Balances\n"
             . "2. Deposit Money\n"
             . "3. Welfare \n" 
             . "4. Withdraw / Contribute\n"
             . "5. Chama Points\n"
             . "6. Loan Request\n"
             . "7. Customer Care";
    }

    /**
     * Slim 4 Single Action Controller
     * Processes incoming USSD HTTP requests, manages states, and returns text/plain responses.
     */
    public function __invoke(Request $request, Response $response): Response
    {
        // Fetch all GET query parameters sent by the USSD gateway (e.g., Africa's Talking)
        $queryParams = $request->getQueryParams();
        
        // Extract individual elements, applying fallback empty strings if missing
        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT = rawurldecode($queryParams["INPUT"] ?? '');

        // Parse the raw USSD string. Multiple navigation choices are separated by asterisks (*)
        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT); 
        $lastInput = end($inputArray); // Isolate the most recent menu choice made by the user
        $ussdResponse = "";

        // Log transaction meta-data for auditing and debugging state movements
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn' => $MSISDN,
            'input' => $INPUT
        ]);

        /**
         * INITIAL SESSION OR LANDING LEVEL DEFINITION
         * Triggered if the input is empty (fresh dial), or if the user hits menu anchors (39 or 00)
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            // "00" is universally treated as a "Back" button, meaning the session is already created
            if ($lastInput !== "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            // Route registered members directly to the main menu; otherwise, initiate registration
            if ($this->utility->isMemberRegistered($MSISDN)) {
                $ussdResponse = $this->renderMainMenu();
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
            } else {
                $ussdResponse = "CON Welcome to Jua Kali CBO. You are not registered.\nReply with 1 to start registration.";
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
            }
        } else {
            /**
             * SUB-MENU / ACTIVE SESSION STATE MACHINE
             * Fetches the current temporary process tier from the session storage to determine context
             */
            $CurrentLevel = $this->utility->getTemplevel($SESSIONID);

            switch ($CurrentLevel) {
                
                // State: User is prompted to start registration
                case "PromptRegistration":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "1") {
                        $ussdResponse = "CON Please enter your Full Name:";
                        $this->utility->setTemplevel($SESSIONID, "CaptureName");
                    } else {
                        $ussdResponse = "END Registration cancelled."; // 'END' terminates the USSD session
                    }
                    break;

                // State: Capturing user's full name
                case "CaptureName":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    // Bypassing phone input prompt completely: directly requesting trade/vocation
                    $ussdResponse = "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
                    $this->utility->setTemplevel($SESSIONID, "CaptureVocation");
                    break;

                // State: Capturing trade/vocation and saving final profile matrix
                case "CaptureVocation":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    
                    /**
                     * SAFE DYNAMIC ARRAY PARSING:
                     * String sequence: 1 * [Full Name] * [Vocation]
                     * Relative indices from end:
                     * $lastInput (end) = Vocation
                     * total - 2 = Full Name
                     */
                    $totalElements = count($inputArray);
                    $vocation = $lastInput;
                    $fullName = $inputArray[$totalElements - 2] ?? 'Unknown';
                    
                    // Automatically assign the number that dialed into the USSD session
                    $phoneNumber = $MSISDN;
                    
                    // Proceed with persistent system registration via Model
                    $isRegistered = $this->utility->registerNewMember($fullName, $phoneNumber, $vocation);
                    
                    if ($isRegistered) {
                        // SMS Alert Integration
                        $smsText = "Welcome to Jua Kali CBO, {$fullName}! Your profile has been successfully set up as a {$vocation} using phone number {$phoneNumber}. Please dial our USSD code to access your portal balances.";
                        $this->sendSmsNotification($phoneNumber, $smsText);
                        
                        $ussdResponse = "END Thank you for registering, {$fullName}.\nPlease redial the code to log into your portal.";
                    } else {
                        $ussdResponse = "END System error during registration. Please try again later.";
                    }
                    
                    $this->utility->setTemplevel($SESSIONID, "RegistrationComplete");
                    break;

                // State: Processing choices from the Registered Members' Main Menu
                case "MemberMainMenu":
                    $this->utility->saveInput($lastInput, $SESSIONID);

                    // Option 1: Balance Inquiry
                    if ($lastInput === "1") {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        
                        // Check if total aggregated structural balance across all accounts is absolute zero
                        $totalBalanceAcrossWallets = 0.0;
                        foreach ($wallets as $wallet) {
                            $totalBalanceAcrossWallets += (float)$wallet['balance'];
                        }

                        // NEW DYNAMIC REQUIREMENT: Prompt users with empty accounts to deposit first
                        if ($totalBalanceAcrossWallets <= 0) {
                            $ussdResponse = "END You have no active balances. Please make an M-Pesa deposit first to view your wallets.";
                            break;
                        }

                        // Display list of dynamic wallets registered to the member
                        $ussdResponse = "CON Select Account to Check:\n";
                        foreach ($wallets as $index => $wallet) {
                            $ussdResponse .= ($index + 1) . ". " . ucfirst($wallet['wallet_name']) . " Wallet\n";
                        }
                        $ussdResponse .= "00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");

                    // Option 2: Navigate to Wallet Selection for Deposits
                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Select Wallet to Deposit into:\n1. Main Wallet\n2. Welfare Wallet\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "DepositWalletSelect");

                    // Option 3: Withdrawals or Welfare Sweeps
                    } elseif ($lastInput === "3") {
                        $ussdResponse = "CON Select Transaction Type:\n1. Withdraw from Main\n2. Make Welfare Contribution\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WithdrawMenuSelect");

                    // Option 4: Points Dashboard access
                    } elseif ($lastInput === "4") {
                        $ussdResponse = "CON Select Options:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");

                    // Option 5: Static feedback form for loan requests
                    } elseif ($lastInput === "5") {
                        $ussdResponse = "END Your loan request has been received.";

                    // Option 6: Static support prompt
                    } elseif ($lastInput === "6") {
                        $ussdResponse = "END Dial +254790727272 for support.";

                    // Fallback for unexpected inputs outside the structural 1-6 range
                    } else {
                        $ussdResponse = "CON Invalid choice.\n" . $this->renderMainMenu();
                    }
                    break;

                // State: Individual Balance Check Execution
                case "SelectBalanceAccount":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $selectedIndex = (int)$lastInput - 1; // Map human-readable input (1, 2) to 0-indexed array
                        
                        if (isset($wallets[$selectedIndex])) {
                            $wallet = $wallets[$selectedIndex];
                            $symbol = (strtolower($wallet['currency']) === 'ksh') ? 'KES' : 'Points';
                            $formattedBal = number_format((float)$wallet['balance'], 2);
                            $ussdResponse = "CON Your " . ucfirst($wallet['wallet_name']) . " balance is {$symbol} {$formattedBal}. \n00. Back";
                        } else {
                            $ussdResponse = "END Selection failed.";
                        }
                    }
                    break;

                // State: Selecting target account/wallet for deposit
                case "DepositWalletSelect":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1" || $lastInput === "2") {
                        $targetName = ($lastInput === "1") ? "Main Wallet" : "Welfare Wallet";
                        $ussdResponse = "CON Enter Amount to Deposit to {$targetName}: \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "DepositAmountCapture");
                    } else {
                        $ussdResponse = "END Option unavailable.";
                    }
                    break;

                // State: Parsing input amounts and executing mock M-Pesa deposit crediting
                case "DepositAmountCapture":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $amount = (float)$lastInput;
                    if ($amount <= 0) {
                        $ussdResponse = "END Deposit must be > 0.";
                        break;
                    }

                    // Look backward into the history array to locate which wallet index was previously selected
                    $targetWalletId = 1; 
                    if (count($inputArray) >= 2) {
                        $previousSelection = $inputArray[count($inputArray) - 2];
                        if ($previousSelection === "2") {
                            $targetWalletId = 2; 
                        }
                    }

                    $walletLabel = ($targetWalletId === 1) ? "Main Wallet" : "Welfare Wallet";
                    $this->utility->processSimulatedDeposit($MSISDN, $targetWalletId, $amount);
                    $ussdResponse = "CON KES " . number_format($amount, 2) . " credited to {$walletLabel}. \n00. Back";
                    break;

                // State: Directing user to withdrawal logic paths or structural sweeps
                case "WithdrawMenuSelect":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1") {
                        $ussdResponse = "CON Enter Amount to Withdraw (Main Wallet):";
                        $this->utility->setTemplevel($SESSIONID, "ProcessMainWithdrawal");
                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Enter Welfare Contribution Amount:";
                        $this->utility->setTemplevel($SESSIONID, "ProcessWelfareContribution");
                    }
                    break;

                // State: Validating balances and processing standard cash payouts
                case "ProcessMainWithdrawal":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $requestedAmount = (float)$lastInput;
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    
                    $mainBalance = 0.0;
                    foreach ($wallets as $w) { 
                        if ((int)$w['wallet_type_id'] === 1) {
                            $mainBalance = (float)$w['balance'];
                        }
                    }

                    // Check bounds constraints to prevent overdrafts or zero entries
                    if ($requestedAmount > $mainBalance || $requestedAmount <= 0) {
                        $ussdResponse = "END Insufficient funds.";
                    } else {
                        $newBalance = $mainBalance - $requestedAmount;
                        // Deduct amount (indicated by negative sign) and append to auditing tables
                        $this->utility->updateWalletBalance($MSISDN, 1, -$requestedAmount);
                        $this->utility->logDemoTransaction($MSISDN, "Debit", $requestedAmount, $newBalance, "M-Pesa Payout Simulation");
                        $ussdResponse = "END Payout Transferred!";
                    }
                    break;

                // State: Moving funds from Main Wallet (Type 1) to Welfare Wallet (Type 2)
                case "ProcessWelfareContribution":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $contributionAmount = (float)$lastInput;
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    
                    $mainBalance = 0.0;
                    $welfareBalance = 0.0;
                    foreach ($wallets as $w) { 
                        if ((int)$w['wallet_type_id'] === 1) $mainBalance = (float)$w['balance']; 
                        if ((int)$w['wallet_type_id'] === 2) $welfareBalance = (float)$w['balance']; 
                    }

                    if ($contributionAmount > $mainBalance || $contributionAmount <= 0) {
                        $ussdResponse = "END Insufficient funds.";
                    } else {
                        // Double-entry balancing: subtract from main wallet, add to welfare wallet
                        $this->utility->updateWalletBalance($MSISDN, 1, -$contributionAmount);
                        $this->utility->updateWalletBalance($MSISDN, 2, $contributionAmount);
                        
                        // Log tracking entries for both ends of the internal ledger movement
                        $this->utility->logDemoTransaction($MSISDN, "Debit", $contributionAmount, ($mainBalance - $contributionAmount), "Welfare sweep");
                        $this->utility->logDemoTransaction($MSISDN, "Credit", $contributionAmount, ($welfareBalance + $contributionAmount), "Welfare allocation");
                        $ussdResponse = "END Contribution Cleared!";
                    }
                    break;

                // State: Points system interaction summary
                case "ChamaPointsHub":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1") {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $points = 0.0;
                        foreach ($wallets as $w) { 
                            if ((int)$w['wallet_type_id'] === 3) $points = (float)$w['balance']; 
                        }
                        // Valuation conversion formula: 1 Point = 0.50 KES
                        $cashValue = $points * 0.50;
                        $ussdResponse = "CON Balance: {$points} Points (KES " . number_format($cashValue, 2) . ") \n00. Back";
                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Enter Points to redeem: \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ExecutePointsRedemption");
                    }
                    break;

                // State: Redeeming accumulated loyalty points directly into cash balances
                case "ExecutePointsRedemption":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $pointsToRedeem = floor((float)$lastInput); // Truncate partial points to enforce integer-like redemptions
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    
                    $currentPoints = 0.0;
                    $mainBalance = 0.0;
                    foreach ($wallets as $w) { 
                        if ((int)$w['wallet_type_id'] === 3) $currentPoints = (float)$w['balance']; 
                        if ((int)$w['wallet_type_id'] === 1) $mainBalance = (float)$w['balance']; 
                    }

                    if ($pointsToRedeem > $currentPoints || $pointsToRedeem <= 0) {
                        $ussdResponse = "END Redemption failed.";
                    } else {
                        $cashValue = $pointsToRedeem * 0.50; // Convert points to KES monetary equivalent
                        
                        // Adjust point counts down and main cash wallets up
                        $this->utility->updateWalletBalance($MSISDN, 3, -$pointsToRedeem);
                        $this->utility->updateWalletBalance($MSISDN, 1, $cashValue);
                        
                        // Ledger entries for point reductions and equivalent cash deposits
                        $this->utility->logDemoTransaction($MSISDN, "Debit", (float)$pointsToRedeem, ($currentPoints - $pointsToRedeem), "Points redemption");
                        $this->utility->logDemoTransaction($MSISDN, "Credit", $cashValue, ($mainBalance + $cashValue), "Cash swap");
                        $ussdResponse = "END Conversion Successful!";
                    }
                    break;

                // Catch-all safety boundary for session expiration issues or anomalous inputs
                default:
                    $ussdResponse = "END Session timeout. Please retry.";
            }
        }

        // Render plain text payload to the HTTP output response pipeline
        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}