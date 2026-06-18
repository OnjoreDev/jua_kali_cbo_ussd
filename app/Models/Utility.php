<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

use Exception;

/**
 * Utility Model Class
 * Handles core business logic, database queries, SMS notifications via Celcom Africa,
 * registration processes, and ledger computations under a split accounting system.
 */
class Utility extends Model
{
    /**
     * Internal Celcom Africa SMS Delivery Engine
     */
    public function sendSMS(string $msisdn, string $message): bool
    {
        try {
            $partnerId = trim($_ENV['PARTNER_ID'] ?? '');
            $apiKey    = trim($_ENV['API_KEY'] ?? '');
            $senderId  = trim($_ENV['SENDER_ID'] ?? '');
            $baseUrl   = trim($_ENV['URL'] ?? 'https://isms.celcomafrica.com/api/services/sendsms/');

            if (empty($partnerId) || empty($apiKey) || empty($senderId)) {
                $this->logger->error("SMS Dispatch canceled: Missing environmental configurations.");
                return false;
            }

            $payload = [
                'partnerID' => $partnerId,
                'apikey'    => $apiKey,
                'shortcode' => $senderId,
                'mobile'    => trim($msisdn),
                'message'   => $message
            ];

            $jsonPayload = json_encode($payload);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, rtrim($baseUrl, '/'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $this->logger->error("SMS gateway network timeout or connection failure for {$msisdn}");
                return false;
            }

            if ($httpCode !== 200) {
                $this->logger->warning("SMS gateway answered with unexpected HTTP Code {$httpCode} for {$msisdn}. Response: " . trim($response));
                return false;
            }

            $responseData = json_decode($response, true);
            if (isset($responseData['response-code']) && (int)$responseData['response-code'] !== 200) {
                $this->logger->warning("Celcom Africa API Error [{$responseData['response-code']}]: {$responseData['response-description']} for {$msisdn}");
                return false;
            }

            $this->logger->info("SMS dispatched successfully to {$msisdn}. Gateway response: " . trim($response));
            return true;
        } catch (\Exception $e) {
            $this->logger->error("SMS execution exception encountered for {$msisdn}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the phone number exists in the members table
     */
    public function isMemberRegistered(string $phoneNumber): bool
    {
        $sql = "SELECT id FROM members WHERE phone = :phone LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return (bool) $stmt->fetch();
    }

    /**
     * Insert registered user into members table and provision default wallets
     */
    public function registerNewMember(string $name, string $phoneNumber, string $vocation): bool
    {
        try {
            $this->pdo->beginTransaction();

            $sql = "INSERT INTO members (name, phone, vocation) VALUES (:name, :phone, :vocation)";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':name' => $name,
                ':phone' => $phoneNumber,
                ':vocation' => $vocation
            ]);

            if (!$result) {
                $this->pdo->rollBack();
                return false;
            }

            $memberId = (int)$this->pdo->lastInsertId();

            $typesStmt = $this->pdo->query("SELECT id FROM wallet_types");
            $types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($types)) {
                $walletSql = "INSERT IGNORE INTO wallets (member_id, balance, wallet_type_id, created_at) VALUES (:member_id, 0, :wallet_type_id, NOW())";
                $walletStmt = $this->pdo->prepare($walletSql);
                foreach ($types as $typeId) {
                    $walletStmt->execute([
                        ':member_id'      => $memberId,
                        ':wallet_type_id' => $typeId
                    ]);
                }
            }

            $this->pdo->commit();

            $welcomeMessage = "Welcome to Jua Kali CBO, {$name}! Your account has been set up successfully as a {$vocation}. Dial our code anytime to access services.";
            $this->sendSMS($phoneNumber, $welcomeMessage);

            return true;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error("Registration transaction failed for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch all ledger account balances associated with the user
     */
    public function getMemberBalances(string $phoneNumber): array
    {
        $sql = "SELECT 
                    wt.id as wallet_type_id,
                    wt.name as wallet_name,
                    wt.currency,
                    MAX(w.balance) as balance
                FROM wallets w
                JOIN members m ON w.member_id = m.id
                JOIN wallet_types wt ON w.wallet_type_id = wt.id
                WHERE m.phone = :phone 
                GROUP BY wt.id, wt.name, wt.currency
                ORDER BY wt.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone = :phone LIMIT 1");
            $stmtMem->execute([':phone' => $phoneNumber]);
            $member = $stmtMem->fetch(PDO::FETCH_ASSOC);

            if ($member) {
                $memberId = $member['id'];
                $typesStmt = $this->pdo->query("SELECT id FROM wallet_types");
                $types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($types)) {
                    $walletSql = "INSERT IGNORE INTO wallets (member_id, balance, wallet_type_id, created_at) VALUES (:member_id, 0, :wallet_type_id, NOW())";
                    $walletStmt = $this->pdo->prepare($walletSql);
                    foreach ($types as $typeId) {
                        $walletStmt->execute([
                            ':member_id'      => $memberId,
                            ':wallet_type_id' => $typeId
                        ]);
                    }

                    $stmt->execute([':phone' => $phoneNumber]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        return $results;
    }

    /**
     * Send an explicit account balance summary SMS text
     */
    public function sendBalancesSms(string $phoneNumber): void
    {
        $wallets = $this->getMemberBalances($phoneNumber);
        if (empty($wallets)) return;

        $msg = "Jua Kali CBO Account Balances:\n";
        foreach ($wallets as $w) {
            $symbol = (strtolower($w['currency']) === 'ksh') ? 'KES' : 'Pts';
            $msg .= "- " . ucfirst($w['wallet_name']) . ": " . $symbol . " " . number_format((float)$w['balance'], 2) . "\n";
        }

        $this->sendSMS($phoneNumber, trim($msg));
    }

    /**
     * Split Ledger Log: Log Incoming Credits (Receipts)
     * Triggers automated wallet increment/decrement math inside the database layer.
     */
    public function logReceipt(string $phoneNumber, int $walletTypeId, float $amount, string $desc, ?string $customReceipt = null): bool
    {
        $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone = :phone LIMIT 1");
        $stmtMem->execute([':phone' => $phoneNumber]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
        if (!$member) return false;

        $memberId = $member['id'];
        $receipt = $customReceipt ?? "RCP-" . strtoupper(bin2hex(random_bytes(4)));

        // Pre-fetch running balance before trigger execution to capture snapshot states
        $balances = $this->getMemberBalances($phoneNumber);
        $runningBal = 0.0;
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === $walletTypeId) {
                $runningBal = (float)$b['balance'];
            }
        }

        // Compute running balance based on trigger math rules
        if ($walletTypeId === 4) {
            $runningBal -= $amount; // Payments lower debt
        } else {
            $runningBal += $amount; // Payments raise savings
        }

        $sql = "INSERT INTO receipts (member_id, wallet_type_id, amount, running_balance, payment_receipt, description, created_at) 
                VALUES (:member_id, :wallet_type_id, :amount, :running_balance, :receipt, :desc, NOW())";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':member_id'       => $memberId,
            ':wallet_type_id'  => $walletTypeId,
            ':amount'          => (int)$amount,
            ':running_balance' => (int)$runningBal,
            ':receipt'         => $receipt,
            ':desc'            => $desc
        ]);
    }

    /**
     * Split Ledger Log: Log Outgoing Debits (Disbursements)
     * Triggers automated wallet increment/decrement math inside the database layer.
     */
    public function logDisbursement(string $phoneNumber, int $walletTypeId, float $amount, string $desc, ?string $customReceipt = null): bool
    {
        $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone = :phone LIMIT 1");
        $stmtMem->execute([':phone' => $phoneNumber]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
        if (!$member) return false;

        $memberId = $member['id'];
        $receipt = $customReceipt ?? "DSB-" . strtoupper(bin2hex(random_bytes(4)));

        // Pre-fetch running balance before trigger execution to capture snapshot states
        $balances = $this->getMemberBalances($phoneNumber);
        $runningBal = 0.0;
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === $walletTypeId) {
                $runningBal = (float)$b['balance'];
            }
        }

        // Compute running balance based on trigger math rules
        if ($walletTypeId === 4) {
            $runningBal += $amount; // Disbursements raise debt
        } else {
            $runningBal -= $amount; // Disbursements lower savings
        }

        $sql = "INSERT INTO disbursements (member_id, wallet_type_id, amount, running_balance, payout_receipt, description, created_at) 
                VALUES (:member_id, :wallet_type_id, :amount, :running_balance, :receipt, :desc, NOW())";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':member_id'       => $memberId,
            ':wallet_type_id'  => $walletTypeId,
            ':amount'          => (int)$amount,
            ':running_balance' => (int)$runningBal,
            ':receipt'         => $receipt,
            ':desc'            => $desc
        ]);
    }

    /**
     * Simulated credit engine with split ledger system tracking
     */
    public function processSimulatedDeposit(string $phoneNumber, int $walletTypeId, float $amount): bool
    {
        $label = ($walletTypeId === 1) ? "main" : "welfare";

        // 1. Log directly to receipts ledger. The trigger updates the running balance table row automatically.
        $this->logReceipt($phoneNumber, $walletTypeId, $amount, "Simulated M-Pesa STK Deposit to {$label} account");

        // 2. Fetch updated balance state for presentation formatting outputs
        $balances = $this->getMemberBalances($phoneNumber);
        $newBalance = 0.0;
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === $walletTypeId) {
                $newBalance = (float)$b['balance'];
            }
        }

        $depositSms = "Confirmed! You have deposited KES " . number_format($amount, 2) . " into your " . ucfirst($label) . " Wallet. New Balance is KES " . number_format($newBalance, 2);
        $this->sendSMS($phoneNumber, $depositSms);

        // 3. Loyalty Reward Points Matrix
        if ($amount >= 100 && ($walletTypeId === 1 || $walletTypeId === 2)) {
            $awardedPoints = floor($amount / 100);

            // Log points receipt to system ledger
            $this->logReceipt($phoneNumber, 3, $awardedPoints, "Loyalty Points earned from Deposit");

            $updatedBalances = $this->getMemberBalances($phoneNumber);
            $ptsBalance = 0.0;
            foreach ($updatedBalances as $b) {
                if ((int)$b['wallet_type_id'] === 3) $ptsBalance = (float)$b['balance'];
            }

            $rewardSms = "You have earned {$awardedPoints} Chama Points from your deposit. Total loyalty balance: {$ptsBalance}.";
            $this->sendSMS($phoneNumber, $rewardSms);
        }

        return true;
    }

    /**
     * Obtain the current outstanding loan balance directly from running wallet states
     */
    public function getOutstandingLoanBalance(string $phoneNumber): float
    {
        $balances = $this->getMemberBalances($phoneNumber);
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === 4) {
                return (float)$b['balance'];
            }
        }
        return 0.0;
    }

    /**
     * Processes a new loan request entry.
     */
    public function createLoanRequest(string $phoneNumber, float $amount): bool
    {
        try {
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone = :phone LIMIT 1");
            $stmtMem->execute([':phone' => $phoneNumber]);
            $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
            if (!$member) return false;

            $memberId = (int)$member['id'];

            // Insert pending contract line item
            $loanSql = "INSERT INTO loan_requests (member_id, wallet_type_id, amount, status, approved_by, created_at) 
                        VALUES (:member_id, :wallet_type_id, :amount, 'pending', 0, NOW())";
            $loanStmt = $this->pdo->prepare($loanSql);
            $loanStmt->execute([
                ':member_id' => $memberId,
                ':wallet_type_id' => 4,
                ':amount'    => (int)$amount
            ]);

            // Fire tracking confirmation text alert
            $msg = "Loan request of amount {$amount} has been received and is awaiting approval from the admins.";
            $this->sendSMS($phoneNumber, $msg);

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Loan creation process aborted for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Formulates a fresh welfare tracking claim inside the database.
     */
    public function createWelfareClaim(string $phoneNumber, string $claimType): bool
    {
        try {
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone = :phone LIMIT 1");
            $stmtMem->execute([':phone' => $phoneNumber]);
            $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
            if (!$member) return false;

            $memberId = (int)$member['id'];
            $trackingNo = strtoupper(substr($claimType, 0, 3)) . "-" . strtoupper(substr(md5(uniqid((string)rand(), true)), 0, 5));

            $sql = "INSERT INTO welfare_claims (member_id, claim_type, amount_eligible, status, tracking_number, created_at) 
                    VALUES (:member_id, :claim_type, 0.00, 'reviewing', :tracking, NOW())";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':member_id'  => $memberId,
                ':claim_type' => $claimType,
                ':tracking'   => $trackingNo
            ]);

            if ($result) {
                $msg = "Your Welfare claim application for " . strtoupper($claimType) . " has been filed successfully. Ticket reference: " . $trackingNo . ".";
                $this->sendSMS($phoneNumber, $msg);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Welfare claim insertion error for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetches claims filed under this phone number
     */
    public function getWelfareClaimsList(string $phoneNumber): array
    {
        $sql = "SELECT wc.tracking_number, wc.claim_type, wc.status, wc.amount_eligible 
                FROM welfare_claims wc
                JOIN members m ON wc.member_id = m.id
                WHERE m.phone = :phone 
                ORDER BY wc.id DESC LIMIT 3";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dispatches notification response for Withdrawal Requests
     */
    public function sendWithdrawalRequestAlert(string $phoneNumber): void
    {
        $msg = "Jua Kali CBO Alert: Your withdrawal request has been received. Funds will be released via M-Pesa shortly.";
        $this->sendSMS($phoneNumber, $msg);
    }

    /**
     * Dispatches notification response for Customer Care Helpline
     */
    public function sendCustomerCareAlert(string $phoneNumber): void
    {
        $msg = "Jua Kali CBO Support: For any questions or payment assistance, reach out directly to our dedicated customer support desk by calling +254790727272.";
        $this->sendSMS($phoneNumber, $msg);
    }

    /**
     * Tracks raw network multi-string inputs over lifetime session records
     */
    public function saveInput(string $input, string $sessionId)
    {
        $insertSQL = "UPDATE ussd_inbox SET message = CONCAT(IFNULL(message, ''), '|', :input) WHERE session_id = :session_id LIMIT 1";
        $stmt = $this->pdo->prepare($insertSQL);
        $stmt->execute([':input' => $input, ':session_id' => $sessionId]);
    }

    /**
     * Sets state step anchor strings
     */
    public function setTemplevel(string $sessionId, string $templevel)
    {
        $updateSQL = "UPDATE ussd_inbox SET temp_level = :templevel WHERE session_id = :session_id";
        $stmt = $this->pdo->prepare($updateSQL);
        $stmt->execute([':templevel' => $templevel, ':session_id' => $sessionId]);
    }

    /**
     * Retrieves current active tracking position label
     */
    /**
     * Retrieves current active tracking position label
     */
    public function getTemplevel(string $sessionId)
    {
        // The query only requires the session_id to find the record
        $selectSQL = "SELECT temp_level FROM ussd_inbox WHERE session_id = :session_id LIMIT 1";
        $stmt = $this->pdo->prepare($selectSQL);

        // Remove ':templevel' => $templevel because $templevel is undefined and not in the SQL
        $stmt->execute([':session_id' => $sessionId]);

        $result = $stmt->fetch(PDO::FETCH_BOTH);
        return $result ? $result[0] : null;
    }
    /**
     * Instantiates an active record log within the inbox framework
     */
    public function createSession(string $sessionId, string $msisdn, string $ussdCode): bool
    {
        $sql = "INSERT INTO ussd_inbox (session_id, msisdn, shortcode, temp_level, message) VALUES (:session_id, :msisdn, :shortcode, :temp_level, :message)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':msisdn'     => $msisdn,
            ':shortcode'  => $ussdCode,
            ':temp_level' => 'MemberMainMenu',
            ':message'    => $ussdCode
        ]);
    }

    /**
     * Process Withdrawal from Main Wallet (Initiates Pending Outflow Tracking)
     */
    public function processWithdrawal(string $phoneNumber, float $amount): bool
    {
        try {
            $wallets = $this->getMemberBalances($phoneNumber);
            $mainBalance = 0.0;

            foreach ($wallets as $w) {
                if ((int)$w['wallet_type_id'] === 1) {
                    $mainBalance = (float)$w['balance'];
                }
            }

            if ($mainBalance < $amount) {
                $this->logger->warning("Insufficient balance for withdrawal: {$phoneNumber}");
                return false;
            }

            // Log directly into disbursements ledger as a pending action item block
            $this->logDisbursement($phoneNumber, 1, $amount, "USSD Withdrawal Request initiated");

            $msg = "Your withdrawal request of KES " . number_format($amount, 2) . " has been received and is being processed. You will receive an M-Pesa confirmation shortly.";
            $this->sendSMS($phoneNumber, $msg);

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Withdrawal failed for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }
    //mpesa functions
    /**
     * Generates a valid OAuth Access Token from Safaricom Daraja
     */
    /**
     * Generates a valid OAuth Access Token from Safaricom Daraja
     */
    private function getDarajaAccessToken(): string
    {
        $consumerKey = $_ENV['MPESA_CONSUMER_KEY'] ?? '';
        $consumerSecret = $_ENV['MPESA_CONSUMER_SECRET'] ?? '';
        $url = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

        $credentials = base64_encode($consumerKey . ':' . $consumerSecret);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic " . $credentials]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        
        // Critical network fallback flags:
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Timeout early after 5 seconds if Safaricom is dead
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->logger->error("Daraja Access Token cURL Network Error", ['error' => $error_msg]);
            curl_close($ch);
            throw new \RuntimeException("Daraja token connection timeout.");
        }

        $result = json_decode($response, true);
        curl_close($ch);

        if (!isset($result['access_token'])) {
            $this->logger->error("Failed to generate M-Pesa access token", ['response' => $response]);
            throw new \RuntimeException("M-Pesa Token Generation Failed.");
        }

        return $result['access_token'];
    }

    
    /**
     * Initiates an STK Push with a specific destination callback URL config
     */
   public function initiateStkPush(string $phoneNumber, float $amount, string $accountReference, string $callbackUrl): bool
{
    try {
        $accessToken = $this->getDarajaAccessToken();
        $url = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";

        $shortcode = $_ENV['MPESA_BUSINESS_SHORTCODE'] ?? '';
        $passkey = $_ENV['MPESA_PASSKEY'] ?? '';

        // FIX: Explicitly force East African Time (EAT) so it matches Safaricom's validation clock
        $date = new \DateTime("now", new \DateTimeZone("Africa/Nairobi"));
        $timestamp = $date->format('YmdHis');
        
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            "BusinessShortCode" => $shortcode,
            "Password"          => $password,
            "Timestamp"         => $timestamp,
            "TransactionType"   => "CustomerPayBillOnline",
            "Amount"            => (int)$amount,
            "PartyA"            => $phoneNumber,
            "PartyB"            => $shortcode,
            "PhoneNumber"       => $phoneNumber,
            "CallBackURL"       => $callbackUrl,
            "AccountReference"  => $accountReference,
            "TransactionDesc"   => "USSD Deposit Triggered"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $accessToken,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        
        // TEMPORARY DEBUGGING LOG (Remove this in production)
        // Check your server error logs or log files to see exactly why Safaricom is rejecting it.
        $this->logger->info("Daraja Response Payload: " . $response);

        $result = json_decode($response, true);
        curl_close($ch);

        return (isset($result['ResponseCode']) && $result['ResponseCode'] === "0");

    } catch (\Exception $e) {
        $this->logger->error("STK Push Exception: " . $e->getMessage());
        return false;
    }
}
}
