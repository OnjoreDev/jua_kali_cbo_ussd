<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Utility Model Class
 * Handles core business logic, database queries, SMS notifications via Celcom Africa,
 * registration processes, and balance computations for the Jua Kali CBO platform.
 */
class Utility extends Model
{
    /**
     * Internal Celcom Africa SMS Delivery Engine
     * Transmits transaction alerts to members via a structured JSON POST payload.
     * * @param string $msisdn Destination phone number (e.g., 254713420287)
     * @param string $message The text content payload to transmit
     * @return bool True if message successfully accepted by the gateway provider API
     */
    public function sendSMS(string $msisdn, string $message): bool
    {
        try {
            // Retrieve gateway authentication configurations from environment variables
            $partnerId = trim($_ENV['PARTNER_ID'] ?? '');
            $apiKey    = trim($_ENV['API_KEY'] ?? '');
            $senderId  = trim($_ENV['SENDER_ID'] ?? '');
            $baseUrl   = trim($_ENV['URL'] ?? 'https://isms.celcomafrica.com/api/services/sendsms/');

            // Terminate execution early if gateway environment properties are not fully set
            if (empty($partnerId) || empty($apiKey) || empty($senderId)) {
                $this->logger->error("SMS Dispatch canceled: Missing environmental configurations.");
                return false;
            }

            // Celcom Africa official JSON payload architecture
            $payload = [
                'partnerID' => $partnerId,
                'apikey'    => $apiKey,
                'shortcode' => $senderId,
                'mobile'    => trim($msisdn),
                'message'   => $message
            ];

            $jsonPayload = json_encode($payload);

            // Initialize a network session context using cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, rtrim($baseUrl, '/'));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Maximum wait boundary constraint
            
            // Explicitly set headers for Content-Type validation
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);

            // Bypass local peer SSL lookup for staging environment compatibility
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            // Execute the remote API call
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Handle network transport Layer Failures
            if ($response === false) {
                $this->logger->error("SMS gateway network timeout or connection failure for {$msisdn}");
                return false;
            }

            // Check if gateway returned an unexpected HTTP Code status
            if ($httpCode !== 200) {
                $this->logger->warning("SMS gateway answered with unexpected HTTP Code {$httpCode} for {$msisdn}. Response: " . trim($response));
                return false;
            }

            // Handle valid HTTP 200 but internal gateway errors
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
     * Used by the USSD entry routing checkpoint to branch users 
     * to either Registration prompts or the Member Main Menu.
     * @param string $phoneNumber Normalized telephone string
     * @return bool True if record entry exists
     */
    public function isMemberRegistered(string $phoneNumber): bool
    {
        $sql = "SELECT id FROM members WHERE phone_number = :phone LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return (bool) $stmt->fetch();
    }

    /**
     * Insert registered user into members table and provision default wallets
     * Wraps user creation and ledger account allocation within a database transaction 
     * to guarantee all default wallets are set up successfully or rolled back entirely on error.
     * @param string $fullName First and last name input from USSD
     * @param string $phoneNumber User MSISDN
     * @param string $vocation Job specialization text input
     * @return bool True if completely processed and committed
     */
    public function registerNewMember(string $fullName, string $phoneNumber, string $vocation): bool
    {
        try {
            // Initiate atomic ACID transaction layer
            $this->pdo->beginTransaction();

            // 1. Insert profile records into core members table
            $sql = "INSERT INTO members (fullname, phone_number, vocation) VALUES (:fullname, :phone_number, :vocation)";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                ':fullname' => $fullName,
                ':phone_number' => $phoneNumber,
                ':vocation' => $vocation
            ]);

            if (!$result) {
                $this->pdo->rollBack();
                return false;
            }

            // Extract the autoincrement ID assigned to this newly created user row
            $memberId = (int)$this->pdo->lastInsertId();

            // 2. Query all existing account system wallet types (Main, Welfare, Chama Points, Loan)
            $typesStmt = $this->pdo->query("SELECT id FROM wallet_types");
            $types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

            // 3. Loop through every distinct type to provision an initial wallet set at balance zero
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

            // Commit changes to permanent database state storage
            $this->pdo->commit();

            // Fire an asynchronous onboarding welcome text message via the API
            $welcomeMessage = "Welcome to Jua Kali CBO, {$fullName}! Your account has been set up successfully as a {$vocation}. Dial our code anytime to access services.";
            $this->sendSMS($phoneNumber, $welcomeMessage);

            return true;
        } catch (\Exception $e) {
            // Fallback and drop partial query executions if database exception trips
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error("Registration transaction failed for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch all ledger account balances associated with the user
     * Includes explicit MAX() aggregation and a structural GROUP BY statement on the unique 
     * wallet type metrics. This acts as a database filter barrier, wiping out duplicated line 
     * calculations caused by redundant join history.
     * @param string $phoneNumber Target lookup phone number
     * @return array Matrix array containing rows of individual wallet types and balances
     */
    public function getMemberBalances(string $phoneNumber): array
    {
        // Query grouping aggregates records precisely down to one unique output row per account option
        $sql = "SELECT 
                    wt.id as wallet_type_id,
                    wt.name as wallet_name,
                    wt.currency,
                    MAX(w.balance) as balance
                FROM wallets w
                JOIN members m ON w.member_id = m.id
                JOIN wallet_types wt ON w.wallet_type_id = wt.id
                WHERE m.phone_number = :phone 
                GROUP BY wt.id, wt.name, wt.currency
                ORDER BY wt.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fail-safe provisioning backup logic if a member profile has zero active rows inside wallets table
        if (empty($results)) {
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
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

                    // Re-query cleanly to extract the clean, fixed dataset matrix
                    $stmt->execute([':phone' => $phoneNumber]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }

        return $results;
    }

    /**
     * Send an explicit account balance summary SMS text
     * Pulls the clean, non-duplicated balance arrays and iterates through them 
     * to formulate a singular multi-line balance text statement dispatched to the user.
     * @param string $phoneNumber Destination member handset phone string
     */
    public function sendBalancesSms(string $phoneNumber): void
    {
        $wallets = $this->getMemberBalances($phoneNumber);
        if (empty($wallets)) return;

        $msg = "Jua Kali CBO Account Balances:\n";
        foreach ($wallets as $w) {
            // Map the alphanumeric currency classifications down to explicit textual symbols
            $symbol = (strtolower($w['currency']) === 'ksh') ? 'KES' : 'Pts';
            $msg .= "- " . ucfirst($w['wallet_name']) . ": " . $symbol . " " . number_format((float)$w['balance'], 2) . "\n";
        }

        $this->sendSMS($phoneNumber, trim($msg));
    }

    /**
     * Atomic Ledger Balance Mutation Updates
     * Mutates ledger accounts directly via increment or decrement mathematical injections.
     * @param string $phoneNumber Target account identifier phone key
     * @param int $walletTypeId Target target ledger ID (e.g., 1=Main, 2=Welfare)
     * @param float $amount Numeric monetary value shift (can accept negative floats for withdrawals)
     * @return bool Statement status execution check
     */
    public function updateWalletBalance(string $phoneNumber, int $walletTypeId, float $amount): bool
    {
        $sql = "UPDATE wallets w
                JOIN members m ON w.member_id = m.id
                SET w.balance = w.balance + :amount
                WHERE m.phone_number = :phone AND w.wallet_type_id = :type_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':amount' => $amount,
            ':phone' => $phoneNumber,
            ':type_id' => $walletTypeId
        ]);
    }

    /**
     * Audit trail transaction record engine
     * Maintains a permanent audit history log of all financial interactions, deposits, 
     * withdrawals, and point actions for internal auditing.
     * @param string $phoneNumber Processing profile telephone key
     * @param string $type Classification identifier (e.g., Credit, Debit)
     * @param float $amount Volume amount altered
     * @param float $currentBalance Post-calculated snapshot value balance
     * @param string $desc Detail memo explaining transactional cause
     * @return bool Process confirmation status
     */
    public function logDemoTransaction(string $phoneNumber, string $type, float $amount, float $currentBalance, string $desc): bool
    {
        $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
        $stmtMem->execute([':phone' => $phoneNumber]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
        if (!$member) return false;

        $memberId = $member['id'];

        // Generate a random unique pseudorandom payment voucher code reference format
        $receipt = "DEMO-" . strtoupper(bin2hex(random_bytes(4)));

        $sql = "INSERT INTO transactions (member_id, type, amount, balance, status, payment_receipt, description, created_at) 
                VALUES (:member_id, :type, :amount, :balance, 'Completed', :receipt, :desc, NOW())";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':member_id' => $memberId,
            ':type'      => $type,
            ':amount'    => $amount,
            ':balance'   => $currentBalance,
            ':receipt'   => $receipt,
            ':desc'      => $desc
        ]);
    }

    /**
     * Simulated credit engine with dynamic Loyalty Reward Points Allocation
     * Simulates external payment gateway response completion (STK push).
     * Modifies selected target wallet balances, triggers financial logging ledger 
     * sequences, and assigns loyalty points calculations based on payment scale benchmarks.
     * @param string $phoneNumber Target user executing saving deposit step
     * @param int $walletTypeId Designated target endpoint account
     * @param float $amount Real-time value deposited
     * @return bool Loop execution confirmation status
     */
    public function processSimulatedDeposit(string $phoneNumber, int $walletTypeId, float $amount): bool
    {
        // 1. Credit target wallet amount directly
        $this->updateWalletBalance($phoneNumber, $walletTypeId, $amount);

        // 2. Fetch updated balance state for transaction audit trail logs
        $balances = $this->getMemberBalances($phoneNumber);
        $newBalance = 0.0;
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === $walletTypeId) {
                $newBalance = (float)$b['balance'];
            }
        }

        $label = ($walletTypeId === 1) ? "main" : "welfare";

        // 3. Add explicit audit log record to standard database history ledger
        $this->logDemoTransaction($phoneNumber, "Credit", $amount, $newBalance, "Simulated M-Pesa STK Deposit to {$label} account");

        // 4. Send background transactional confirmation text alert
        $depositSms = "Confirmed! You have deposited KES " . number_format($amount, 2) . " into your " . ucfirst($label) . " Wallet. New Balance is KES " . number_format($newBalance, 2);
        $this->sendSMS($phoneNumber, $depositSms);

        // 5. Loyalty Engine Matrix rule execution: Award 1 Point for every KES 100 milestone deposited
        if ($amount >= 100 && ($walletTypeId === 1 || $walletTypeId === 2)) {
            $awardedPoints = floor($amount / 100);

            // Increment Chama Points Wallet (Type ID: 3)
            $this->updateWalletBalance($phoneNumber, 3, $awardedPoints);

            // Re-fetch individual points configuration balance totals for audit trail snapshots
            $updatedBalances = $this->getMemberBalances($phoneNumber);
            $ptsBalance = 0.0;
            foreach ($updatedBalances as $b) {
                if ((int)$b['wallet_type_id'] === 3) $ptsBalance = (float)$b['balance'];
            }

            // Log point transaction to audit trail ledger
            $this->logDemoTransaction($phoneNumber, "Credit", $awardedPoints, $ptsBalance, "Loyalty Points earned from Deposit");

            // Dispatch dynamic points alert text
            $rewardSms = "You have earned {$awardedPoints} Chama Points from your deposit. Total loyalty balance: {$ptsBalance}.";
            $this->sendSMS($phoneNumber, $rewardSms);
        }

        return true;
    }

    /**
     * Obtains the current outstanding loan balance directly from transaction history logs.
     * Searches for completed or pending loan records inside the transactions ledger.
     * @param string $phoneNumber Target lookup phone number
     * @return float The current running balance total for loans
     */
    public function getOutstandingLoanBalance(string $phoneNumber): float
    {
        $sql = "SELECT t.balance 
                FROM transactions t
                JOIN members m ON t.member_id = m.id
                WHERE m.phone_number = :phone 
                AND (t.description LIKE '%Loan%' OR t.description LIKE '%loan%')
                ORDER BY t.id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (float)$row['balance'] : 0.0;
    }

    /**
     * Processes a new loan request entry.
     * Inserts records into loan_requests, logs an audit trace entry into transactions,
     * and sends the required text alert to the applicant.
     * @param string $phoneNumber Active member telephone key
     * @param float $amount The principal loan volume requested
     * @return bool True if all elements insert successfully
     */
    public function createLoanRequest(string $phoneNumber, float $amount): bool
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Fetch internal Member ID
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
            $stmtMem->execute([':phone' => $phoneNumber]);
            $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $this->pdo->rollBack();
                return false;
            }
            $memberId = (int)$member['id'];

            // 2. Insert record into loan_requests table with a pending status
            $loanSql = "INSERT INTO loan_requests (member_id, wallet_id, amount, status, approved_by, created_at) 
                        VALUES (:member_id, :wallet_id, :amount, 'pending', 0, NOW())";
            $loanStmt = $this->pdo->prepare($loanSql);
            $loanStmt->execute([
                ':member_id' => $memberId,
                ':wallet_id' => 4, // References the wallet_types id for 'loan' accounts
                ':amount'    => (int)$amount
            ]);

            // 3. Compute dynamic running balance from transaction ledger records
            $currentLoanBal = $this->getOutstandingLoanBalance($phoneNumber);
            $newLoanBalance = $currentLoanBal + $amount;

            // 4. Record a tracing entry line inside the transactions audit ledger
            $receipt = "LOAN-" . strtoupper(bin2hex(random_bytes(4)));
            $txSql = "INSERT INTO transactions (member_id, type, amount, balance, status, payment_receipt, description, created_at) 
                      VALUES (:member_id, 'Credit', :amount, :balance, 'Pending', :receipt, :desc, NOW())";
            $txStmt = $this->pdo->prepare($txSql);
            $txStmt->execute([
                ':member_id' => $memberId,
                ':amount'    => (int)$amount,
                ':balance'   => (int)$newLoanBalance,
                ':receipt'   => $receipt,
                ':desc'      => "Pending Loan Request Submission"
            ]);

            $this->pdo->commit();

            // 5. Fire the precise confirmation alert text format requested
            $msg = "Loan request of amount {$amount} has been received and is awaiting approval from the admins.";
            $this->sendSMS($phoneNumber, $msg);

            return true;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error("Loan creation process aborted for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Formulates a fresh welfare tracking claim inside the database.
     * @param string $phoneNumber Target member handset phone string
     * @param string $claimType Category of emergency (e.g., medical, bereavement)
     * @return bool True if record is stored successfully
     */
    public function createWelfareClaim(string $phoneNumber, string $claimType): bool
    {
        try {
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
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
     * Fetches claims filed under this phone number to display real-time status updates inside USSD.
     * @param string $phoneNumber Target lookup phone number
     * @return array Matrix array containing tracking details
     */
    public function getWelfareClaimsList(string $phoneNumber): array
    {
        $sql = "SELECT wc.tracking_number, wc.claim_type, wc.status, wc.amount_eligible 
                FROM welfare_claims wc
                JOIN members m ON wc.member_id = m.id
                WHERE m.phone_number = :phone 
                ORDER BY wc.id DESC LIMIT 3";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dispatches immediate notification response for Menu Item 5 (Withdrawal Requests)
     */
    public function sendWithdrawalRequestAlert(string $phoneNumber): void
    {
        $msg = "Jua Kali CBO Alert: Your withdrawal request has been received. Funds will be released via M-Pesa shortly.";
        $this->sendSMS($phoneNumber, $msg);
    }

    /**
     * Dispatches immediate notification response for Menu Item 6 (Customer Care Helpline information text)
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
     * Sets state step anchor strings to preserve state tracking logic between raw USSD round-trips
     */
    public function setTemplevel(string $sessionId, string $templevel)
    {
        $updateSQL = "UPDATE ussd_inbox SET temp_level = :templevel WHERE session_id = :session_id";
        $stmt = $this->pdo->prepare($updateSQL);
        $stmt->execute([':templevel' => $templevel, ':session_id' => $sessionId]);
    }

    /**
     * Retrieves current active tracking position label assigned to the operating session reference
     */
    public function getTemplevel(string $sessionId)
    {
        $selectSQL = "SELECT temp_level FROM ussd_inbox WHERE session_id = :session_id";
        $stmt = $this->pdo->prepare($selectSQL);
        $stmt->execute([':session_id' => $sessionId]);
        $result = $stmt->fetch(PDO::FETCH_BOTH);
        return $result ? $result[0] : null;
    }

    /**
     * Instantiates an active record log within the inbox framework when code initialization loops start
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
     * Process Withdrawal from Main Wallet
     * Debit main wallet, log transaction, send SMS confirmation
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

            // TODO: In production, compute "Available Balance" by subtracting any PENDING debits 
            // from the core $mainBalance so they cannot request duplicate payouts simultaneously.
            if ($mainBalance < $amount) {
                $this->logger->warning("Insufficient balance for withdrawal: {$phoneNumber}");
                return false;
            }

            // Fetch member metadata safely
            $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
            $stmtMem->execute([':phone' => $phoneNumber]);
            $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
            $memberId = $member ? (int)$member['id'] : 0;

            $receipt = "WD-" . strtoupper(bin2hex(random_bytes(4)));

            // CRITICAL FIX: Mark status as 'Pending' instead of 'Completed'.
            // DO NOT mutate the wallets table balance until your B2C payout callback returns HTTP 200.
            $sql = "INSERT INTO transactions (member_id, type, amount, balance, status, payment_receipt, description, created_at) 
            VALUES (:member_id, 'Debit', :amount, :balance, 'Pending', :receipt, :desc, NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':member_id' => $memberId,
                ':amount'    => $amount,
                ':balance'   => $mainBalance, // Keeps track of current balance state when requested
                ':receipt'   => $receipt,
                ':desc'      => "USSD Withdrawal Request initiated"
            ]);

            // Send SMS alerting them it's in progress
            $msg = "Your withdrawal request of KES " . number_format($amount, 2) . " has been received and is being processed. You will receive an M-Pesa confirmation shortly.";
            $this->sendSMS($phoneNumber, $msg);

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Withdrawal failed for {$phoneNumber}: " . $e->getMessage());
            return false;
        }
    }
}
