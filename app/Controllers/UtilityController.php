<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * UtilityController Class
 * Manages incoming HTTP telecommunication requests from the USSD Gateway.
 * Controls multi-step state menus, handles user interaction routing, and interfaces
 * directly with the Utility Model for transactional storage and data retrieval.
 */
class UtilityController extends Controller
{
    /**
     * Instance of the Utility model to encapsulate database interactions and business logic
     */
    private Utility $utility;

    /**
     * Dependency Injection Constructor
     * Inherits the logging infrastructure from the base controller and resolves the model layer.
     * * @param ContainerInterface $container PSR-11 Dependency Injection Container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
    }

    /**
     * Normalizes Kenyan MSISDN inputs to an absolute internationalized format.
     * Converts phone strings starting with local prefixes '07' or '01' to '254...'.
     * * @param string $phone Raw phone string parameter from the request
     * @return string Normalized country code standard phone number
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
     * Renders the root menu options presented to successfully registered CBO members.
     * Preceded by the 'CON' flag to signal the telecom gateway that the session is ongoing.
     * * @return string Multi-line string showing available service operations
     */
    private function renderMainMenu(): string
    {
        return "CON Welcome to Jua Kali CBO. Select an option:\n"
             . "1. Check Balance\n"
             . "2. Welfare\n"
             . "3. Chama Points\n"
             . "4. Loan Request\n"
             . "5. Withdraw Request\n"
             . "6. Customer Care";
    }

    /**
     * PSR-7 Single-Action invokable execution loop.
     * Processes state inputs, handles navigation switches, and returns raw plain text parameters.
     */
    public function __invoke(Request $request, Response $response): Response
    {
        // Extract incoming query parameter strings sent by the telecom provider callback
        $queryParams = $request->getQueryParams();
        
        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT = rawurldecode($queryParams["INPUT"] ?? '');

        // Parse continuous asterisk-concatenated inputs (e.g., "1*2*100") into an accessible traversal map
        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT); 
        $lastInput = end($inputArray); // Capture the isolated current user choice response
        $ussdResponse = "";

        // Log the structural raw parameters for telemetry verification and auditing
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn' => $MSISDN,
            'input' => $INPUT
        ]);

        // Check if this is a fresh session initialization, a back navigation request ('00'), or a main menu shortcut ('39')
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            if ($lastInput !== "00") {
                // Initialize an entry mapping inside the database inbox tracker for new loops
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                // Save the back command input string into the tracking trace array
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            // Route user depending on registration profile status
            if ($this->utility->isMemberRegistered($MSISDN)) {
                $ussdResponse = $this->renderMainMenu();
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
            } else {
                // Direct unregistered numbers to the dynamic sign-up workflow
                $ussdResponse = "CON Welcome to Jua Kali CBO. You are not registered.\nReply with 1 to start registration.";
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
            }
        } else {
            // Retrieve current menu position context tracking variable from database records
            $CurrentLevel = $this->utility->getTemplevel($SESSIONID);

            // Execute switch matching block based on active session step states
            switch ($CurrentLevel) {
                
                // State: Checking if the user consented to begin profiling
                case "PromptRegistration":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "1") {
                        $ussdResponse = "CON Please enter your Full Name:";
                        $this->utility->setTemplevel($SESSIONID, "CaptureName");
                    } else {
                        // END prefix instantly breaks the network handset call connection loop
                        $ussdResponse = "END Registration cancelled.";
                    }
                    break;

                // State: Capturing the text name input string
                case "CaptureName":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $ussdResponse = "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
                    $this->utility->setTemplevel($SESSIONID, "CaptureVocation");
                    break;

                // State: Finalizing registration and writing profile metrics to database
                case "CaptureVocation":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    
                    $totalElements = count($inputArray);
                    $vocation = $lastInput;
                    
                    // Traverse backwards safely within the array to fetch the previously submitted name component
                    $fullName = $inputArray[$totalElements - 2] ?? 'Unknown';
                    
                    // Commit to storage layer and dispatch transactional onboarding text via Celcom Africa
                    $isRegistered = $this->utility->registerNewMember($fullName, $MSISDN, $vocation);
                    
                    if ($isRegistered) {
                        $ussdResponse = "END Thank you for registering, {$fullName}.\nPlease redial the code to view your menu.";
                    } else {
                        $ussdResponse = "END System error during registration. Please try again later.";
                    }
                    break;

                // State: Routing root menu options chosen by standard members
                case "MemberMainMenu":
                    $this->utility->saveInput($lastInput, $SESSIONID);

                    if ($lastInput === "1") {
                        $ussdResponse = "CON Select Account to Check Balance:\n"
                                     . "1. Main Wallet\n"
                                     . "2. Welfare Wallet\n"
                                     . "3. Loan Wallet\n"
                                     . "00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");

                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareMenuSelect");

                    } elseif ($lastInput === "3") {
                        $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");

                    } elseif ($lastInput === "4") {
                        $ussdResponse = "END Your loan request has been received. A confirmation message has been sent to your phone.";
                        // Async alerts trigger direct SMS workflows then exit session connection immediately
                        $this->utility->sendLoanRequestAlert($MSISDN);

                    } elseif ($lastInput === "5") {
                        $ussdResponse = "END Your withdraw request has been received. A confirmation message has been sent to your phone.";
                        $this->utility->sendWithdrawalRequestAlert($MSISDN);

                    } elseif ($lastInput === "6") {
                        $ussdResponse = "END Please call +254790727272 for dynamic customer support. Details have been texted to you.";
                        $this->utility->sendCustomerCareAlert($MSISDN);

                    } else {
                        $ussdResponse = "CON Invalid choice.\n" . $this->renderMainMenu();
                    }
                    break;

                // State: Checking sub-balances across diverse structural database records
                case "SelectBalanceAccount":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        // Map user input option strings into exact structural database wallet layout identifiers
                        $targetTypeId = 0;
                        if ($lastInput === "1") $targetTypeId = 1; // Main Account type references
                        if ($lastInput === "2") $targetTypeId = 2; // Welfare Account type references
                        if ($lastInput === "3") $targetTypeId = 4; // Loan Account type references

                        // Extract aggregate ledger items array returned cleanly by the non-duplicated database join filters
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $selectedWallet = null;

                        foreach ($wallets as $w) {
                            if ((int)$w['wallet_type_id'] === $targetTypeId) {
                                $selectedWallet = $w;
                                break;
                            }
                        }

                        if ($selectedWallet !== null) {
                            $symbol = (strtolower($selectedWallet['currency']) === 'ksh') ? 'KES' : 'Pts';
                            $formattedBal = number_format((float)$selectedWallet['balance'], 2);
                            
                            // Send full multiline balance text statement asynchronously via SMS API
                            $this->utility->sendBalancesSms($MSISDN);

                            // Provide secondary interactive shortcut action paths exclusively for the main cash wallet
                            if ($targetTypeId === 1) {
                                $ussdResponse = "CON Your Main Wallet balance is {$symbol} {$formattedBal}.\n"
                                             . "1. Make Deposit\n"
                                             . "00. Back";
                                $this->utility->setTemplevel($SESSIONID, "MainWalletDirectAction");
                            } else {
                                $ussdResponse = "CON Your " . ucfirst($selectedWallet['wallet_name']) . " balance is {$symbol} {$formattedBal}.\n00. Back";
                                $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                            }
                        } else {
                            $ussdResponse = "CON Account has no data records.\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        }
                    }
                    break;

                // State: Quick shortcuts nested directly inside the balance viewport menu options
                case "MainWalletDirectAction":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = "CON Select Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");
                    } elseif ($lastInput === "1") {
                        $ussdResponse = "CON Enter Amount to Deposit to Main Wallet:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "MainWalletDepositCapture");
                    } else {
                        $ussdResponse = "CON Invalid option. Reply 00 to go back.";
                    }
                    break;

                // State: Evaluating input numeric value for Main Wallet credits
                case "MainWalletDepositCapture":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        $amount = (float)$lastInput;
                        if ($amount <= 0) {
                            $ussdResponse = "END Deposit amount must be greater than 0.";
                        } else {
                            // Triggers automated credit operations, ledger mutations, and dynamic loyalty point points reward steps
                            $this->utility->processSimulatedDeposit($MSISDN, 1, $amount);
                            $ussdResponse = "CON KES " . number_format($amount, 2) . " credited to Main Wallet. \n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        }
                    }
                    break;

                // State: Viewing options inside the secondary Welfare module
                case "WelfareMenuSelect":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1") {
                        $ussdResponse = "CON Enter Amount to Deposit to Welfare Wallet:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareDepositCapture");
                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Claim made successfully.\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } elseif ($lastInput === "3") {
                        $ussdResponse = "CON Welfare active.\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } else {
                        $ussdResponse = "CON Invalid choice.\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    }
                    break;

                // State: Processing input parameter figures targeting the Welfare funding account
                case "WelfareDepositCapture":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        $amount = (float)$lastInput;
                        if ($amount <= 0) {
                            $ussdResponse = "END Deposit amount must be greater than 0.";
                        } else {
                            $this->utility->processSimulatedDeposit($MSISDN, 2, $amount);
                            $ussdResponse = "CON KES " . number_format($amount, 2) . " credited to Welfare Wallet. \n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        }
                    }
                    break;

                // State: Unified navigation landing pattern step to handle fallback options cleanly
                case "GenericBackRoute":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $ussdResponse = $this->renderMainMenu();
                    $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    break;

                // State: Viewing active Chama parameters and point metrics
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
                        // Render standard monetary conversions (Rule structure: 1 Point = KES 0.50)
                        $cashValue = $points * 0.50;
                        $ussdResponse = "CON Balance: {$points} Points (KES " . number_format($cashValue, 2) . ") \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } elseif ($lastInput === "2") {
                        $ussdResponse = "CON Enter Points to redeem: \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ExecutePointsRedemption");
                    }
                    break;

                // State: Validating boundaries and committing points-to-cash swap mutations
                case "ExecutePointsRedemption":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    if ($lastInput === "00") {
                        $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                    } else {
                        $pointsToRedeem = floor((float)$lastInput); 
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        
                        $currentPoints = 0.0;
                        $mainBalance = 0.0;
                        foreach ($wallets as $w) { 
                            if ((int)$w['wallet_type_id'] === 3) $currentPoints = (float)$w['balance']; 
                            if ((int)$w['wallet_type_id'] === 1) $mainBalance = (float)$w['balance']; 
                        }

                        // Protect financial ledger from over-draft mutations or invalid inputs
                        if ($pointsToRedeem > $currentPoints || $pointsToRedeem <= 0) {
                            $ussdResponse = "CON Redemption failed. Insufficient points balance.\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        } else {
                            $cashValue = $pointsToRedeem * 0.50; 
                            
                            // Execute parallel adjustments: reduction of points wallet and incrementation of cash wallet
                            $this->utility->updateWalletBalance($MSISDN, 3, -$pointsToRedeem);
                            $this->utility->updateWalletBalance($MSISDN, 1, $cashValue);
                            
                            // Maintain systemic tracing balance logs within data transaction history indices
                            $this->utility->logDemoTransaction($MSISDN, "Debit", (float)$pointsToRedeem, ($currentPoints - $pointsToRedeem), "Points redemption");
                            $this->utility->logDemoTransaction($MSISDN, "Credit", $cashValue, ($mainBalance + $cashValue), "Cash swap");
                            
                            $ussdResponse = "CON Conversion Successful! Added KES " . number_format($cashValue, 2) . " to your Main Wallet.\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        }
                    }
                    break;

                // Fallback catch-all boundary block if a session unexpected exception trips
                default:
                    $ussdResponse = "END Session timeout. Please retry.";
            }
        }

        // Render plain text payload data output to HTTP response stream pipeline
        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}