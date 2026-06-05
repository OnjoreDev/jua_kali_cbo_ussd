<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Utility extends Model
{
    /**
     * Check if the phone number exists in the members table
     */
    public function isMemberRegistered(string $phoneNumber): bool
    {
        $sql = "SELECT id FROM members WHERE phone_number = :phone LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return (bool) $stmt->fetch();
    }

    /**
     * Insert a newly registered user into the members table
     */
    public function registerNewMember(string $fullName, string $phoneNumber, string $vocation): bool
    {
        $sql = "INSERT INTO members (fullname, phone_number, vocation) VALUES (:fullname, :phone_number, :vocation)";
        $stmt = $this->pdo->prepare($sql);

        $result = $stmt->execute([
            ':fullname' => $fullName,
            ':phone_number' => $phoneNumber,
            ':vocation' => $vocation
        ]);

        if ($result) {
            $this->logger->info("New member registered successfully: {$phoneNumber}");
        }

        return $result;
    }

    /**
     * FETCH DYNAMIC BALANCE BALANCES: Pulls directly using relational mappings 
     */
    public function getMemberBalances(string $phoneNumber): array
    {
        $sql = "SELECT 
                    wt.id as wallet_type_id,
                    wt.name as wallet_name,
                    wt.currency,
                    w.balance
                FROM wallets w
                JOIN members m ON w.member_id = m.id
                JOIN wallet_types wt ON w.wallet_type_id = wt.id
                WHERE m.phone_number = :phone 
                ORDER BY wt.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':phone' => $phoneNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ATOMIC LEDGER BALANCING: Directly modifies wallet balances for the member
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
     * TRANSACTION RECORD ENGINE: Appends records to the transaction history ledger
     */
    public function logDemoTransaction(string $phoneNumber, string $type, float $amount, float $currentBalance, string $desc): bool
    {
        $stmtMem = $this->pdo->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
        $stmtMem->execute([':phone' => $phoneNumber]);
        $member = $stmtMem->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            $this->logger->warning("Failed to log transaction. Member with phone {$phoneNumber} not found.");
            return false;
        }

        $memberId = $member['id'];
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
     * SIMULATED OVER-THE-AIR DEPOSIT STACK: Simulates successful M-Pesa interactions
     */
    public function processSimulatedDeposit(string $phoneNumber, int $walletTypeId, float $amount): bool
    {
        $this->logger->info("Processing simulated deposit for {$phoneNumber}, Amount: {$amount}");

        // 1. Credit target wallet ledger balance atomically
        $this->updateWalletBalance($phoneNumber, $walletTypeId, $amount);

        // 2. Fetch current balance configuration for auditing and logging records
        $balances = $this->getMemberBalances($phoneNumber);
        $newBalance = 0.0;
        foreach ($balances as $b) {
            if ((int)$b['wallet_type_id'] === $walletTypeId) {
                $newBalance = (float)$b['balance'];
            }
        }

        $label = ($walletTypeId === 1) ? "main" : "welfare";
        $this->logDemoTransaction($phoneNumber, "Credit", $amount, $newBalance, "Simulated M-Pesa STK Deposit to {$label} account");

        // 3. Loyalty Reward Rule: Every KES 100 deposited into main or welfare earns 1 Point
        if ($amount >= 100 && ($walletTypeId === 1 || $walletTypeId === 2)) {
            $awardedPoints = floor($amount / 100);
            $this->updateWalletBalance($phoneNumber, 3, $awardedPoints);

            // Recalculate dynamic points balance state for logging
            $updatedBalances = $this->getMemberBalances($phoneNumber);
            $ptsBalance = 0.0;
            foreach ($updatedBalances as $b) {
                if ((int)$b['wallet_type_id'] === 3) {
                    $ptsBalance = (float)$b['balance'];
                }
            }
            $this->logDemoTransaction($phoneNumber, "Credit", $awardedPoints, $ptsBalance, "Loyalty Points earned from Deposit");
        }

        return true;
    }

    /**
     * FIX: Use IFNULL to prevent updates from breaking when message is an empty/NULL column string initially
     */
    public function saveInput(string $input, string $sessionId)
    {
        $insertSQL = "UPDATE ussd_inbox 
                      SET message = CONCAT(IFNULL(message, ''), '|', :input) 
                      WHERE session_id = :session_id LIMIT 1";
        $stmt = $this->pdo->prepare($insertSQL);
        $stmt->execute([':input' => $input, ':session_id' => $sessionId]);
    }

    public function setTemplevel(string $sessionId, string $templevel)
    {
        $updateSQL = "UPDATE ussd_inbox SET temp_level = :templevel WHERE session_id = :session_id";
        $stmt = $this->pdo->prepare($updateSQL);
        $stmt->execute([':templevel' => $templevel, ':session_id' => $sessionId]);
    }

    public function getTemplevel(string $sessionId)
    {
        $selectSQL = "SELECT temp_level FROM ussd_inbox WHERE session_id = :session_id";
        $stmt = $this->pdo->prepare($selectSQL);
        $stmt->execute([':session_id' => $sessionId]);
        $result = $stmt->fetch(PDO::FETCH_BOTH);
        return $result ? $result[0] : null;
    }

    /**
     * FIX: Populate the initial shortcode string straight into the message column during creation
     */
    public function createSession(string $sessionId, string $msisdn, string $ussdCode): bool
    {
        $sql = "INSERT INTO ussd_inbox (session_id, msisdn, shortcode, temp_level, message) 
                VALUES (:session_id, :msisdn, :shortcode, :temp_level, :message)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':session_id' => $sessionId,
            ':msisdn'     => $msisdn,
            ':shortcode'  => $ussdCode,
            ':temp_level' => 'MemberMainMenu',
            ':message'    => $ussdCode
        ]);
    }
}