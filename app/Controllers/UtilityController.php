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
     * @param ContainerInterface $container PSR-11 Dependency Injection Container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
    }

    /**
     * Normalizes Kenyan MSISDN inputs to an absolute internationalized format.
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
     */
    private function isValidFullName(string $name): bool
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        if (strlen($name) < 3 || strlen($name) > 50) {
            return false;
        }
        if (!preg_match("/^[a-zA-Z\s]+$/", $name) || count(explode(' ', $name)) < 2) {
            return false;
        }
        return true;
    }

    /**
     * Helper to validate vocation fields.
     */
    private function isValidVocation(string $vocation): bool
    {
        $vocation = trim($vocation);
        return (bool) preg_match("/^[a-zA-Z\s\-]{3,30}$/", $vocation);
    }

    /**
     * Renders the root menu options presented to successfully registered CBO members.
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
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();

        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT = rawurldecode($queryParams["INPUT"] ?? '');

        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT);
        $lastInput = trim(end($inputArray));
        $ussdResponse = "";

        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn' => $MSISDN,
            'input' => $INPUT
        ]);

        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error. Session identifiers missing.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            if ($lastInput !== "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            if ($this->utility->isMemberRegistered($MSISDN)) {
                $ussdResponse = $this->renderMainMenu();
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
            } else {
                $ussdResponse = "CON Welcome to Jua Kali CBO. You are not registered.\nReply with 1 to start registration.";
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
            }
        } else {
            $CurrentLevel = $this->utility->getTemplevel($SESSIONID);

            switch ($CurrentLevel) {
                case "PromptRegistration":
                    if ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Please enter your Full Name:";
                        $this->utility->setTemplevel($SESSIONID, "CaptureName");
                    } else {
                        $ussdResponse = "END Registration cancelled. You must press 1 to register.";
                    }
                    break;

                case "CaptureName":
                    if (!$this->isValidFullName($lastInput)) {
                        $ussdResponse = "CON [Invalid Name! Enter First & Last Name, letters only]\n\nPlease enter your Full Name:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
                        $this->utility->setTemplevel($SESSIONID, "CaptureVocation");
                    }
                    break;

                case "CaptureVocation":
                    if (!$this->isValidVocation($lastInput)) {
                        $ussdResponse = "CON [Invalid Vocation! Use letters/dashes only, 3-30 chars]\n\nPlease enter your Vocation:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $totalElements = count($inputArray);
                        $vocation = $lastInput;
                        $fullName = $inputArray[$totalElements - 2] ?? 'Unknown Member';

                        $isRegistered = $this->utility->registerNewMember($fullName, $MSISDN, $vocation);
                        if ($isRegistered) {
                            $ussdResponse = "END Thank you for registering, {$fullName}.\nPlease redial the code to view your menu.";
                        } else {
                            $ussdResponse = "END System error during registration. Please try again later.";
                        }
                    }
                    break;

                case "MemberMainMenu":
                    if ($lastInput === "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Select Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");
                    } elseif ($lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareMenuSelect");
                    } elseif ($lastInput === "3") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $points = 0.0;
                        foreach ($wallets as $w) {
                            if ((int)$w['wallet_type_id'] === 3) $points = (float)$w['balance'];
                        }

                        if ($points <= 0) {
                            $ussdResponse = "CON You have 0 Chama Points. You cannot access the redemption menu.\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                        } else {
                            $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                            $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                        }
                    } elseif ($lastInput === "4") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Loan Amount Request:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "CaptureLoanAmount");
                    } elseif ($lastInput === "5") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Amount to Withdraw from Main Wallet:\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "CaptureWithdrawalAmount");
                    } elseif ($lastInput === "6") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "END Please call +254790727272 for dynamic customer support. Details have been texted to you.";
                        $this->utility->sendCustomerCareAlert($MSISDN);
                    } else {
                        $ussdResponse = "CON [Invalid Choice!]\n" . $this->renderMainMenu();
                    }
                    break;

                case "CaptureLoanAmount":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0) {
                        $ussdResponse = "CON [Invalid Input! Please enter a valid number value]\n\nEnter Loan Amount Request:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $loanAmount = (float)$lastInput;
                        $isSubmitted = $this->utility->createLoanRequest($MSISDN, $loanAmount);
                        if ($isSubmitted) {
                            $ussdResponse = "END Loan request of amount {$loanAmount} has been received and is awaiting approval from the admins.";
                        } else {
                            $ussdResponse = "END System connection error processing loan request. Please retry later.";
                        }
                    }
                    break;

                case "SelectBalanceAccount":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!in_array($lastInput, ["1", "2", "3"], true)) {
                        $ussdResponse = "CON [Invalid Selection! Choose 1, 2, or 3]\n\nSelect Account to Check Balance:\n1. Main Wallet\n2. Welfare Wallet\n3. Loan Wallet\n00. Back";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $targetTypeId = ($lastInput === "1") ? 1 : (($lastInput === "2") ? 2 : 4);
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
                            $this->utility->sendBalancesSms($MSISDN);

                            if ($targetTypeId === 1) {
                                $ussdResponse = "CON Your Main Wallet balance is {$symbol} {$formattedBal}.\n1. Make Deposit\n00. Back";
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
                        $ussdResponse = "CON [Invalid Input!]\n\nReply 1 to Deposit or 00 to go back.";
                    }
                    break;

                case "MainWalletDepositCapture":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
                        $ussdResponse = "CON [Invalid Amount! Enter a numeric value between 1 and 70,000]\n\nEnter Amount to Deposit:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $amount = (float)$lastInput;
                        $this->utility->processSimulatedDeposit($MSISDN, 1, $amount);
                        $ussdResponse = "END KES " . number_format($amount, 2) . " credited to Main Wallet.";
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu"); // optional cleanup
                    }
                    break;

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
                        $ussdResponse = "CON Select Welfare Claim Type:\n1. Medical Benefit\n2. Bereavement Support\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareClaimTypeSelect");
                    } elseif ($lastInput === "3") {
                        $this->utility->saveInput($lastInput, $SESSIONID);

                        $claims = $this->utility->getWelfareClaimsList($MSISDN);
                        $wallets = $this->utility->getMemberBalances($MSISDN);

                        $welfareBal = 0.0;
                        foreach ($wallets as $w) {
                            if ((int)$w['wallet_type_id'] === 2) $welfareBal = (float)$w['balance'];
                        }

                        $ussdResponse = "CON Welfare Hub Standing:\n"
                            . "Fund Balance: KES " . number_format($welfareBal, 2) . "\n\n"
                            . "Recent Claims:\n";

                        if (empty($claims)) {
                            $ussdResponse .= "No logged claims found.\n";
                        } else {
                            foreach ($claims as $c) {
                                $statusLabel = match ($c['status']) {
                                    'pending_docs' => 'Pending Docs',
                                    'reviewing'    => 'In Review',
                                    'approved'     => 'Approved',
                                    'rejected'     => 'Rejected',
                                    'disbursed'    => 'Paid Out',
                                    default        => ucfirst($c['status'])
                                };
                                $ussdResponse .= "- " . strtoupper($c['tracking_number']) . " [{$statusLabel}]\n";
                            }
                        }
                        $ussdResponse .= "\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } else {
                        $ussdResponse = "CON [Invalid Choice!]\n\nWelfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                    }
                    break;

                case "WelfareClaimTypeSelect":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Welfare Hub:\n1. Deposit\n2. Claim\n3. Status\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WelfareMenuSelect");
                    } elseif ($lastInput === "1" || $lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $claimType = ($lastInput === "1") ? "medical" : "bereavement";

                        $isClaimed = $this->utility->createWelfareClaim($MSISDN, $claimType);
                        if ($isClaimed) {
                            $ussdResponse = "CON Claim submitted successfully. Tracking confirmation sent via SMS.\n00. Back";
                        } else {
                            $ussdResponse = "CON System connection error filing claim. Please try later.\n00. Back";
                        }
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } else {
                        $ussdResponse = "CON [Invalid Choice!]\n\nSelect Welfare Claim Type:\n1. Medical Benefit\n2. Bereavement Support\n00. Back";
                    }
                    break;

                case "WelfareDepositCapture":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
                        $ussdResponse = "CON [Invalid Amount! Enter a value between 1 and 70,000]\n\nEnter Amount to Deposit:";
                    } else {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $amount = (float)$lastInput;
                        $this->utility->processSimulatedDeposit($MSISDN, 2, $amount);
                        $ussdResponse = "END KES " . number_format($amount, 2) . " credited to Welfare Wallet.";
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    }
                    break;

                case "GenericBackRoute":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $ussdResponse = $this->renderMainMenu();
                    $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    break;

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

                        // Modified to strictly show points balance without the conversion text
                        $ussdResponse = "CON Balance: {$points} Points \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "GenericBackRoute");
                    } elseif ($lastInput === "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Points to redeem: \n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ExecutePointsRedemption");
                    } else {
                        $ussdResponse = "CON [Invalid Option!]\n\nChama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                    }
                    break;

                case "ExecutePointsRedemption":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                    } elseif (!is_numeric($lastInput) || (float)$lastInput <= 0) {
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

                        if ($pointsToRedeem > $currentPoints) {
                            $ussdResponse = "CON [Redemption failed! You have {$currentPoints} points]\n\n00. Go back";
                        } else {
                            $cashValue = $pointsToRedeem * 100;

                            // 1. Perform database wallet balances update
                            $this->utility->updateWalletBalance($MSISDN, 3, -$pointsToRedeem);
                            $this->utility->updateWalletBalance($MSISDN, 1, $cashValue);

                            // 2. Compute remaining balance mathematically for real-time responsiveness
                            $remainingPoints = $currentPoints - $pointsToRedeem;

                            // Log transaction changes in history
                            $this->utility->logDemoTransaction($MSISDN, "Debit", (float)$pointsToRedeem, $remainingPoints, "Points redemption");
                            $this->utility->logDemoTransaction($MSISDN, "Credit", $cashValue, ($mainBalance + $cashValue), "Cash swap");

                            // 3. TRIGGER CUSTOMIZED SMS WITH REMAINING BALANCE
                            // Since Utility.php doesn't do this automatically for point swaps, we invoke it directly here.
                            $smsMessage = "Confirmed: You have successfully redeemed {$pointsToRedeem} Chama Points for KES " . number_format($cashValue, 2) . ". Your remaining balance is {$remainingPoints} Points.";
                            
                            // Using a reflection or wrapper shortcut inside controller to invoke your sendSMS utility mechanism
                            // Note: Since sendSMS is private inside Utility.php, make sure your model has a public wrapper 
                            // or access point, otherwise change `private function sendSMS` to `public function sendSMS` inside Utility.php
                            $this->utility->sendSMS($MSISDN, $smsMessage);

                            // 4. TERMINATE SESSION WITH "END" AND DISPLAY REMAINING BALANCE
                            $ussdResponse = "END Conversion Successful!\n"
                                . "Added KES " . number_format($cashValue, 2) . " to your Main Wallet.\n"
                                . "Remaining Balance: {$remainingPoints} Points.";
                        }
                    }
                    break;

                case "CaptureWithdrawalAmount":
                    if ($lastInput === "00") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        // CRITICAL FIX 1: Evaluate the Date Constraint FIRST before checking amount properties
                        $currentDay = (int)date('j');
                        $allowedDays = [1, 3, 5, 15];

                        if (!in_array($currentDay, $allowedDays, true)) {
                            // CRITICAL FIX 2: Switched from 'CON' to 'END' to forcefully kill the session 
                            // This blocks any potential logic bypasses or automated redials on restricted days.
                            $ussdResponse = "END Withdrawal Restriction!\n"
                                . "Withdrawals can only be requested on the 1st, 3rd, 5th, or 15th of the month.\n"
                                . "Current Day: " . date('d-M-Y') . ". Access Denied.";
                        }
                        // Date is valid, now sanitize and parse the amount input
                        elseif (!is_numeric($lastInput) || (float)$lastInput <= 0 || (float)$lastInput > 70000) {
                            $ussdResponse = "CON [Invalid Amount! Enter a value between 1 and 70,000]\n\nEnter Amount to Withdraw:";
                        } else {
                            $amount = (float)$lastInput;

                            // Fetch current balances to validate depth
                            $wallets = $this->utility->getMemberBalances($MSISDN);
                            $mainBalance = 0.0;
                            foreach ($wallets as $w) {
                                if ((int)$w['wallet_type_id'] === 1) {
                                    $mainBalance = (float)$w['balance'];
                                }
                            }

                            // STAGE VALIDATION: Ensure withdrawal request does not exceed main wallet holdings
                            if ($amount > $mainBalance) {
                                $formattedBal = number_format($mainBalance, 2);
                                $ussdResponse = "CON [Insufficient Balance!]\n"
                                    . "Your Main Wallet has KES {$formattedBal}.\n"
                                    . "Please enter a lesser amount:\n"
                                    . "00. Back";
                            } else {
                                // Date, Input Format, and Balance levels are completely valid. Process request.
                                $this->utility->saveInput($lastInput, $SESSIONID);
                                $isProcessed = $this->utility->processWithdrawal($MSISDN, $amount);

                                if ($isProcessed) {
                                    $ussdResponse = "END Withdrawal request of KES " . number_format($amount, 2) . " has been received.\nFunds will be sent via M-Pesa shortly.";
                                } else {
                                    $ussdResponse = "END System error processing your withdrawal. Please try again later.";
                                }
                            }
                        }
                    }
                    break;
                default:
                    $ussdResponse = "END Session timeout. Please retry.";
            }
        }

        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
