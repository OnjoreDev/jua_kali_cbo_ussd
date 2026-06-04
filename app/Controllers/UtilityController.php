<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UtilityController extends Controller
{
    private Utility $utility;

    /**
     * Inherits Logger from parent Controller and pulls Utility model from Container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        // Pull the Utility model through the DI container
        $this->utility = $container->get(Utility::class);
    }

    /**
     * Sanitizes and normalizes Kenyan MSISDNs
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
     * Main Menu UI Block
     */
    private function renderMainMenu(): string
    {
        return "CON Welcome back to Jua Kali CBO. Select an option:\n"
             . "1. Check Balances\n"
             . "2. Deposit Money\n"
             . "3. Withdraw / Contribute\n"
             . "4. Chama Points\n"
             . "5. Loan Request\n"
             . "6. Customer Care";
    }

    /**
     * Slim 4 Single Action Controller
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT = rawurldecode($queryParams["INPUT"] ?? '');

        $inputArray = explode("*", $INPUT); 
        $lastInput = end($inputArray); 
        $ussdResponse = "";

        // Log the incoming request using the inherited Logger
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn' => $MSISDN,
            'input' => $INPUT
        ]);

        if ($lastInput == "39" || $lastInput == "00") {
            if ($lastInput != "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
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
                    if ($lastInput == "1") {
                        $ussdResponse = "CON Please enter your Full Name:";
                        $this->utility->setTemplevel($SESSIONID, "CaptureName");
                    } else {
                        $ussdResponse = "END Registration cancelled.";
                    }
                    break;

                case "CaptureName":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $ussdResponse = "CON Please enter your Phone Number (e.g. 0712345678):";
                    $this->utility->setTemplevel($SESSIONID, "CapturePhone");
                    break;

                case "CapturePhone":
                    $typedPhone = $this->normalizePhoneNumber($lastInput);
                    $this->utility->saveInput($typedPhone, $SESSIONID);
                    $ussdResponse = "CON Please enter your Vocation (e.g., Carpenter, Tailor):";
                    $this->utility->setTemplevel($SESSIONID, "CaptureVocation");
                    break;

                case "CaptureVocation":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $fullName = $inputArray[2] ?? 'Unknown';
                    $phoneNumber = $this->normalizePhoneNumber($inputArray[3] ?? $MSISDN);
                    $vocation = $lastInput; 
                    
                    $this->utility->registerNewMember($fullName, $phoneNumber, $vocation);
                    $ussdResponse = "END Thank you for registering, $fullName!\nPlease redial the code to log into your portal.";
                    $this->utility->setTemplevel($SESSIONID, "RegistrationComplete");
                    break;

                case "MemberMainMenu":
                    $this->utility->saveInput($lastInput, $SESSIONID);

                    if ($lastInput == "1") {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $ussdResponse = "CON Select Account to Check:\n";
                        foreach ($wallets as $index => $wallet) {
                            $ussdResponse .= ($index + 1) . ". " . ucfirst($wallet['wallet_name']) . " Wallet\n";
                        }
                        $ussdResponse .= "00. Back";
                        $this->utility->setTemplevel($SESSIONID, "SelectBalanceAccount");
                    } elseif ($lastInput == "2") {
                        $ussdResponse = "CON Select Wallet to Deposit into:\n1. Main Wallet\n2. Welfare Wallet\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "DepositWalletSelect");
                    } elseif ($lastInput == "3") {
                        $ussdResponse = "CON Select Transaction Type:\n1. Withdraw from Main\n2. Make Welfare Contribution\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "WithdrawMenuSelect");
                    } elseif ($lastInput == "4") {
                        $ussdResponse = "CON Chama Points Hub:\n1. View Points Balance\n2. Redeem Points for Cash\n00. Back";
                        $this->utility->setTemplevel($SESSIONID, "ChamaPointsHub");
                    } elseif ($lastInput == "5") {
                        $ussdResponse = "END Your loan request has been received.";
                    } elseif ($lastInput == "6") {
                        $ussdResponse = "END Dial +2547XXXXXXXX for support.";
                    } else {
                        $ussdResponse = "CON Invalid choice.\n" . $this->renderMainMenu();
                    }
                    break;

                case "SelectBalanceAccount":
                    if ($lastInput == "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } else {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $selectedIndex = (int)$lastInput - 1;
                        if (isset($wallets[$selectedIndex])) {
                            $wallet = $wallets[$selectedIndex];
                            $symbol = (strtolower($wallet['currency']) === 'ksh') ? 'KES' : 'Points';
                            $formattedBal = number_format((float)$wallet['balance'], 2);
                            $ussdResponse = "END Your " . ucfirst($wallet['wallet_name']) . " balance is {$symbol} {$formattedBal}.";
                        } else {
                            $ussdResponse = "END Selection failed.";
                        }
                    }
                    break;

                case "DepositWalletSelect":
                    if ($lastInput == "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput == "1" || $lastInput == "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $targetName = ($lastInput == "1") ? "Main Wallet" : "Welfare Wallet";
                        $ussdResponse = "CON Enter Amount to Deposit to {$targetName}:";
                        $this->utility->setTemplevel($SESSIONID, "DepositAmountCapture");
                    } else {
                        $ussdResponse = "END Option unavailable.";
                    }
                    break;

                case "DepositAmountCapture":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $amount = (float)$lastInput;
                    if ($amount <= 0) {
                        $ussdResponse = "END Deposit must be > 0.";
                        break;
                    }
                    $targetWalletId = (in_array("1", $inputArray)) ? 1 : 2;
                    $walletLabel = ($targetWalletId === 1) ? "Main Wallet" : "Welfare Wallet";
                    $this->utility->processSimulatedDeposit($MSISDN, $targetWalletId, $amount);
                    $ussdResponse = "END [DEMO] KES " . number_format($amount, 2) . " credited to {$walletLabel}.";
                    break;

                case "WithdrawMenuSelect":
                    if ($lastInput == "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput == "1") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Amount to Withdraw (Main Wallet):";
                        $this->utility->setTemplevel($SESSIONID, "ProcessMainWithdrawal");
                    } elseif ($lastInput == "2") {
                        $this->utility->saveInput($lastInput, $SESSIONID);
                        $ussdResponse = "CON Enter Welfare Contribution Amount:";
                        $this->utility->setTemplevel($SESSIONID, "ProcessWelfareContribution");
                    }
                    break;

                case "ProcessMainWithdrawal":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $requestedAmount = (float)$lastInput;
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    $mainBalance = 0.0;
                    foreach ($wallets as $w) { if ($w['wallet_type_id'] == 1) $mainBalance = (float)$w['balance']; }

                    if ($requestedAmount > $mainBalance || $requestedAmount <= 0) {
                        $ussdResponse = "END Insufficient funds.";
                    } else {
                        $this->utility->updateWalletBalance($MSISDN, 1, -$requestedAmount);
                        $this->utility->logDemoTransaction($MSISDN, "Debit", $requestedAmount, ($mainBalance - $requestedAmount), "M-Pesa Payout");
                        $ussdResponse = "END [DEMO] Payout Transferred!";
                    }
                    break;

                case "ProcessWelfareContribution":
                    $this->utility->saveInput($lastInput, $SESSIONID);
                    $contributionAmount = (float)$lastInput;
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    $mainBalance = 0.0;
                    $welfareBalance = 0.0;
                    foreach ($wallets as $w) { 
                        if ($w['wallet_type_id'] == 1) $mainBalance = (float)$w['balance']; 
                        if ($w['wallet_type_id'] == 2) $welfareBalance = (float)$w['balance']; 
                    }

                    if ($contributionAmount > $mainBalance || $contributionAmount <= 0) {
                        $ussdResponse = "END Insufficient funds.";
                    } else {
                        $this->utility->updateWalletBalance($MSISDN, 1, -$contributionAmount);
                        $this->utility->updateWalletBalance($MSISDN, 2, $contributionAmount);
                        $this->utility->logDemoTransaction($MSISDN, "Debit", $contributionAmount, ($mainBalance - $contributionAmount), "Welfare sweep");
                        $this->utility->logDemoTransaction($MSISDN, "Credit", $contributionAmount, ($welfareBalance + $contributionAmount), "Welfare allocation");
                        $ussdResponse = "END [DEMO] Contribution Cleared!";
                    }
                    break;

                case "ChamaPointsHub":
                    if ($lastInput == "00") {
                        $ussdResponse = $this->renderMainMenu();
                        $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                    } elseif ($lastInput == "1") {
                        $wallets = $this->utility->getMemberBalances($MSISDN);
                        $points = 0.0;
                        foreach ($wallets as $w) { if ($w['wallet_type_id'] == 3) $points = (float)$w['balance']; }
                        $cashValue = $points * 0.50;
                        $ussdResponse = "END Balance: {$points} Points (KES " . number_format($cashValue, 2) . ")";
                    } elseif ($lastInput == "2") {
                        $ussdResponse = "CON Enter Points to redeem:";
                        $this->utility->setTemplevel($SESSIONID, "ExecutePointsRedemption");
                    }
                    break;

                case "ExecutePointsRedemption":
                    $pointsToRedeem = floor((float)$lastInput);
                    $wallets = $this->utility->getMemberBalances($MSISDN);
                    $currentPoints = 0.0;
                    $mainBalance = 0.0;
                    foreach ($wallets as $w) { 
                        if ($w['wallet_type_id'] == 3) $currentPoints = (float)$w['balance']; 
                        if ($w['wallet_type_id'] == 1) $mainBalance = (float)$w['balance']; 
                    }

                    if ($pointsToRedeem > $currentPoints || $pointsToRedeem <= 0) {
                        $ussdResponse = "END Redemption failed.";
                    } else {
                        $cashValue = $pointsToRedeem * 0.50;
                        $this->utility->updateWalletBalance($MSISDN, 3, -$pointsToRedeem);
                        $this->utility->updateWalletBalance($MSISDN, 1, $cashValue);
                        $this->utility->logDemoTransaction($MSISDN, "Debit", $pointsToRedeem, ($currentPoints - $pointsToRedeem), "Points redemption");
                        $this->utility->logDemoTransaction($MSISDN, "Credit", $cashValue, ($mainBalance + $cashValue), "Cash swap");
                        $ussdResponse = "END Conversion Successful!";
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