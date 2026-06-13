<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class MpesaCallbackController extends Controller
{
    private Utility $utility;
    private PDO $db;

    /**
     * Official Safaricom Daraja API Gateway Callback IP Address Blocks.
     * Includes Sandbox testing clusters and active Production nodes.
     */
    private array $allowedSafaricomIps = [
        '196.201.212.69',   // <-- Added this IP to unblock your current transaction tests
        '196.201.214.200', '196.201.214.206', '196.201.214.207', '196.201.214.208',
        '196.201.213.114', '196.201.214.214', '196.201.214.215', '196.201.214.59',
        '196.201.212.74',  '196.201.212.129', '196.201.212.138', '196.201.212.132',
        '196.201.212.136', '196.201.212.128', '196.201.212.137', '196.200.211.22'
    ];

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
        $this->db = $container->get(PDO::class);
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // IP Verification Layer
        $serverParams = $request->getServerParams();
        $clientIp = $serverParams['HTTP_X_FORWARDED_FOR'] ?? $serverParams['HTTP_CLIENT_IP'] ?? $serverParams['REMOTE_ADDR'] ?? '';

        if (str_contains($clientIp, ',')) {
            $ipArray = explode(',', $clientIp);
            $clientIp = trim($ipArray[0]);
        }

        if (!in_array($clientIp, $this->allowedSafaricomIps, true)) {
            $this->logger->warning("Unauthorized Callback Attempt Blocked", ['blocked_ip' => $clientIp]);
            $response->getBody()->write(json_encode(["error" => "Forbidden"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $rawJson = (string)$request->getBody();
        $data = json_decode($rawJson, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['Body']['stkCallback'])) {
            $response->getBody()->write(json_encode(["error" => "Bad Request"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $stkCallback = $data['Body']['stkCallback'];
        $resultCode = $stkCallback['ResultCode'] ?? -1;

        if ($resultCode === 0) {
            $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            
            $amount = 0.0;
            $mpesaReceiptNumber = '';
            $phoneNumber = '';

            foreach ($callbackMetadata as $item) {
                if ($item['Name'] === 'Amount') { $amount = (float)$item['Value']; }
                if ($item['Name'] === 'MpesaReceiptNumber') { $mpesaReceiptNumber = (string)$item['Value']; }
                if ($item['Name'] === 'PhoneNumber') { $phoneNumber = (string)$item['Value']; }
            }

            // --- THE FIXED WALLET ROUTING LOOKUP LAYER ---
            // Read the literal URL path hitting this controller endpoint action 
            $uriPath = $request->getUri()->getPath();

            if (str_ends_with(strtolower($uriPath), '/welfare')) {
                $walletTypeId = 2;
                $walletName = "Welfare Fund Account";
            } else {
                // If it hits /main, it defaults safely to type 1
                $walletTypeId = 1;
                $walletName = "Main Savings Wallet";
            }

            try {
                // Idempotency Check
                $checkStmt = $this->db->prepare("SELECT id FROM receipts WHERE payment_receipt = :receipt LIMIT 1");
                $checkStmt->execute([':receipt' => $mpesaReceiptNumber]);
                if ($checkStmt->fetch()) {
                    $response->getBody()->write(json_encode(["ResponseCode" => "0", "ResponseDesc" => "Success"]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
                }

                // Find Member ID
                $memberStmt = $this->db->prepare("SELECT id FROM members WHERE phone_number = :phone LIMIT 1");
                $memberStmt->execute([':phone' => $phoneNumber]);
                $member = $memberStmt->fetch();

                if ($member) {
                    $memberId = (int)$member['id'];

                    // Calculate running balance snapshot for the dynamically verified walletTypeId
                    $balStmt = $this->db->prepare("SELECT balance FROM wallets WHERE member_id = :member_id AND wallet_type_id = :wallet_type_id LIMIT 1");
                    $balStmt->execute([':member_id' => $memberId, ':wallet_type_id' => $walletTypeId]);
                    $currentWallet = $balStmt->fetch();
                    $currentBal = $currentWallet ? (float)$currentWallet['balance'] : 0.0;
                    
                    $newRunningBalance = $currentBal + $amount;

                    // Insert to receipts table
                    $insertStmt = $this->db->prepare("
                        INSERT INTO receipts (member_id, wallet_type_id, amount, running_balance, payment_receipt, description, created_at)
                        VALUES (:member_id, :wallet_type_id, :amount, :running_balance, :payment_receipt, :description, NOW())
                    ");
                    
                    $insertStmt->execute([
                        ':member_id'       => $memberId,
                        ':wallet_type_id'  => $walletTypeId,
                        ':amount'          => $amount,
                        ':running_balance' => $newRunningBalance,
                        ':payment_receipt' => $mpesaReceiptNumber,
                        ':description'     => "USSD M-Pesa STK Push Deposit to " . $walletName
                    ]);

                    // Send SMS notification
                    $smsMsg = "Confirmed: Your deposit of KES " . number_format($amount, 2) . " to Jua Kali CBO " . $walletName . " has been processed successfully. Ref: {$mpesaReceiptNumber}. Thank you.";
                    $this->utility->sendSMS($phoneNumber, $smsMsg);
                }

            } catch (\Exception $e) {
                $this->logger->error("Database Error in Callback Processing", ['message' => $e->getMessage()]);
            }
        }

        $response->getBody()->write(json_encode(["ResponseCode" => "0", "ResponseDesc" => "Success"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}