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
 * directly with the Utility Model with explicit form and data validation rules.
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
     * @param ContainerInterface $container PSR-11 Dependency Injection Container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
    }

    /**
     * Normalizes Kenyan MSISDN inputs to an absolute internationalized format.
     * Converts phone strings starting with local prefixes '07' or '01' to '254...'.
     * @param string $phone Raw phone string parameter from the request
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
     * Helper to validate that an input contains a valid first and last name.
     * Requirements: 3-50 chars total, alphabetic characters and spaces only, minimum 2 distinct words.
     */
    private function isValidFullName(string $name): bool
    {
        $name = preg_replace('/\s+/', ' ', trim($name)); // Normalize internal white spaces
        if (strlen($name) < 3 || strlen($name) > 50) {
            return false;
        }
        // Letters and spaces only, split check ensures at least two name parts are supplied
        if (!preg_match("/^[a-zA-Z\s]+$/", $name) || count(explode(' ', $name)) < 2) {
            return false;
        }
        return true;
    }

    /**
     * Helper to validate vocation fields.
     * Requirements: 3-30 chars total, alphabetic characters, dashes, and single spaces only.
     */
    private function isValidVocation(string $vocation): bool
    {
        $vocation = trim($vocation);
        return (bool) preg_match("/^[a-zA-Z\s\-]{3,30}$/", $vocation);
    }

    /**
     * Renders the root menu options presented to successfully registered CBO members.
     * Preceded by the 'CON' flag to signal the telecom gateway that the session is ongoing.
     * @return string Multi-line string showing available service operations
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
        $lastInput = trim(end($inputArray)); // Capture the isolated current user choice response
        $ussdResponse = "";

        // Log the structural raw parameters for telemetry verification and auditing
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn' => $MSISDN,
            'input' => $INPUT
        ]);

        // Input Integrity Guard: Protect application core against critical parameter loss
        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error. Session identifiers missing.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

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
                    if ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Please enter your Full Name:";
                        $this->utility->setTemplevel($SESSIONID, "CaptureName");
                    } else {
                        // Form Validation: User input an option other than "1" to proceed with signup
                        $ussdResponse = "END Registration cancelled. You must press 1 to register.";
                    }
                    break;

                // State: Capturing and validating the text name input string
                case "CaptureName":
                    if (!$this->isValidFullName($lastInput)) {
                        // Form Validation: Input failed string layout guidelines. State layer is NOT advanced.
                        $ussdResponse = "CON [Invalid Name! Enter First & Last Name, letters only]\n\nPlease enter your Full Name:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
                        $this->utility->setTemplevel($SESSIONID, "CaptureVocation");
                    }
                    break;

                // State: Finalizing registration and writing profile metrics to database
                case "CaptureVocation":
                    if (!$this->isValidVocation($lastInput)) {
                        // Form Validation: Vocation failed length or naming boundaries. State remains.
                        $ussdResponse = "CON [Invalid Vocation! Use letters/dashes only, 3-30 chars]\n\nPlease enter your Vocation:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        
                        $totalElements = count($inputArray);
                        $vocation = $lastInput;
                        
                        // Traverse backwards safely within the array to fetch the previously verified name component
                        $fullName = $inputArray[$totalElements - 2] ?? 'Unknown Member';
                        
                        // Commit to storage layer and dispatch transactional onboarding text via Celcom Africa
                        $isRegistered = $this->utility->registerNewMember($fullName, $MSISDN, $vocation);
                        
                        if ($isRegistered) {
                            $ussdResponse = "END Thank you for registering, {$fullName}.\nPlease redial the code to view your menu.";
                        } else {
                            $ussdResponse = "END System error during registration. Please try again later.";
                        }
                    }
                    break;

                // State: Routing root menu options chosen by standard members
                case "MemberMainMenu":
                    if ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Select Account to Check Balance:\n"
                                     . "1. Main Wallet\n"
                                     . "2. Welfare Wallet\n"
                                     . "3. Loan Wallet\n"
                                     . "00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");

                    } elseif ($lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareMenuSelect");

                    } elseif ($lastInput === "3") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        
                        // Intercept step: check points balance before rendering the sub-menu layout options
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $points = 0.0;
                        foreach ($wallets as $w) { 
                            if ((int)$w['wallet_type_id'] === 3) {
                                $points = (float)$w['balance']; 
                            }
                        }

                        if ($points <= 0) {
                            // Guard Condition: Reject traversal when active balances do not exist
                            $ussdResponse = "CON You have 0 Chama Points. You cannot access the redemption menu.\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        } else {
                            // Standard operational path when positive points exist
                            $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                        }

                    } elseif ($lastInput === "4") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Loan Amount Request:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "CaptureLoanAmount");

                    } elseif ($lastInput === "5") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "END Your withdraw request has been received. A confirmation message has been sent to your phone.";
                        $this->utility->sendWithdrawalRequestAlert($MSISDN);

                    } elseif ($lastInput === "6") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "END Please call +254790727272 for dynamic customer support. Details have been texted to you.";
                        $this->utility->sendCustomerCareAlert($MSISDN);

                    } else {
                        // Form Validation: Choice fell out of bound menu options (1-6)
                        $ussdResponse = "CON [Invalid Choice!]\n" . $this->renderMainMenu();
                    }
                    break;

                // State: Intercepting, validating, and committing dynamic loan request parameters
                case "CaptureLoanAmount":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0) {
                        // Data Guard: Input must be a positive numeric value
                        $ussdResponse = "CON [Invalid Input! Please enter a valid number value]\n\nEnter Loan Amount Request:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $loanAmount = (float)$lastInput;
                        
                        // Execute processing logic inside model engine
                        $isSubmitted = $this->utility->createLoanRequest($MSISDN, $loanAmount);
                        
                        if ($isSubmitted) {
                            $ussdResponse = "END Loan request of amount {$loanAmount} has been received and is awaiting approval from the admins.";
                        } else {
                            $ussdResponse = "END System connection error processing loan request. Please retry later.";
                        }
                    }
                    break;

                // State: Checking sub-balances across diverse structural database records
                case "SelectBalanceAccount":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!in_array($lastInput, ["1", "2", "3"], true)) {
                        // Form Validation: User selected an option out of structural keypad options
                        $ussdResponse = "CON [Invalid Selection! Choose 1, 2, or 3]\n\n"
                                     . "Select Account to Check Balance:\n"
                                     . "1. Main Wallet\n"
                                     . "2. Welfare Wallet\n"
                                     . "3. Loan Wallet\n"
                                     . "00. Back";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        // Map user input option strings into exact structural database wallet layout identifiers
                        $targetTypeId = ($lastInput === "1") ? 1 : (($lastInput === "2") ? 2 : 4);

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
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Select Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");
                    } elseif ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Amount to Deposit to Main Wallet:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "MainWalletDepositCapture");
                    } else {
                        // Form Validation: Catch unauthorized options on main action shortcut
                        $ussdResponse = "CON [Invalid Input!]\n\nReply 1 to Deposit or 00 to go back.";
                    }
                    break;

                // State: Evaluating input numeric value for Main Wallet credits
                case "MainWalletDepositCapture":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
                        // Form Validation: Ensure valid numeric formats between KES 1 and 70,000 max single limit
                        $ussdResponse = "CON [Invalid Amount! Enter a numeric value between 1 and 70,000]\n\nEnter Amount to Deposit:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $amount = (float)$lastInput;
                        
                        // Triggers automated credit operations, ledger mutations, and dynamic loyalty point reward steps
                        $this->utility->processSimulatedDeposit($MSISDN, 1, $amount);
                        $ussdResponse = "CON KES " . number_format($amount, 2) . " credited to Main Wallet. \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    }
                    break;

                // State: Viewing options inside the secondary Welfare module
                case "WelfareMenuSelect":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Amount to Deposit to Welfare Wallet:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareDepositCapture");
                    } elseif ($lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Claim made successfully.\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } elseif ($lastInput === "3") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Welfare active.\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } else {
                        // Form Validation: Wrap around invalid menu indexing
                        $ussdResponse = "CON [Invalid Choice!]\n\nWelfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                    }
                    break;

                // State: Processing input parameter figures targeting the Welfare funding account
                case "WelfareDepositCapture":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
                        // Form Validation: Enforce clean numerical ranges inside Welfare funding state
                        $ussdResponse = "CON [Invalid Amount! Enter a value between 1 and 70,000]\n\nEnter Amount to Deposit:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $amount = (float)$lastInput;
                        $this->utility->processSimulatedDeposit($MSISDN, 2, $amount);
                        $ussdResponse = "CON KES " . number_format($amount, 2) . " credited to Welfare Wallet. \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
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
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $points = 0.0;
                        foreach ($wallets as $w) { 
                            if ((int)$w['wallet_type_id'] === 3) $points = (float)$w['balance']; 
                        }
                        // Render standard monetary conversions (Rule structure: 1 Point = KES 100)
                        $cashValue = $points * 100 ;
                        $ussdResponse = "CON Balance: {$points} Points (KES " . number_format($cashValue, 2) . ") \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } elseif ($lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Points to redeem: \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ExecutePointsRedemption");
                    } else {
                        // Form Validation: Handle out of boundary inputs on the Chama Hub branch
                        $ussdResponse = "CON [Invalid Option!]\n\nChama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                    }
                    break;

                // State: Validating boundaries and committing points-to-cash swap mutations
                case "ExecutePointsRedemption":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0) {
                        // Form Validation: Ensure number inputs are strictly numerical elements
                        $ussdResponse = "CON [Invalid Input! Please specify a positive number of points]\n\nEnter Points to redeem:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $pointsToRedeem = floor((float)$lastInput); 
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        
                        $currentPoints = 0.0;
                        $mainBalance = 0.0;
                        foreach ($wallets as $w) { 
                            if ((int)$w['wallet_type_id'] === 3) $currentPoints = (float)$w['balance']; 
                            if ((int)$w['wallet_type_id'] === 1) $mainBalance = (float)$w['balance']; 
                        }

                        // Form & Core Business Validation: Protect financial ledger from over-draft mutations or invalid inputs
                        if ($pointsToRedeem > $currentPoints) {
                            $ussdResponse = "CON [Redemption failed! You  have {$currentPoints} points]\n\n 00 Go back:";
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