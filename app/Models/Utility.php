<?php

declare(strict_types=1);

namespace App\Models;


/**
 * Utility Model Class
 * Handles core business logic, database queries, SMS notifications via Celcom Africa,
 * registration processes, and ledger computations under a split accounting system.
 */
class Utility extends Model
{

    public function callApi(string $method, string $endpoint, array $data = [], string $msisdn = ''): array
    {
        //use the above for the live app
        $baseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost:8080/api/v1';
        //use on the local server
        //$baseUrl = 'http://localhost:8080/api/v1';
        $token = $_ENV['USSD_CLIENT_TOKEN'] ?? '';

        // Dynamically ensure the endpoint format starts with a clean single slash
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        // Resolve member_id if a phone number is provided to satisfy AgentMiddleware
        $memberId = 0;
        if (!empty($msisdn)) {
            $member = $this->getMemberByPhone($msisdn);
            $memberId = $member['id'] ?? 0;
        }

        $ch = curl_init($baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // Tells cURL to follow HTTP-to-HTTPS redirects automatically on cPanel
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Bypasses local Windows cert verification issues when hitting production domains
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Authorization validates the server; X-Member-ID validates the agent/member role
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-Member-ID: ' . $memberId,
            'Accept: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Defensive Trap: Prevent json_decode from breaking if cURL returns false
        if ($response === false) {
            $this->logger->error("API cURL Transport Failure to {$endpoint}: " . $error);
            return ['status' => 'error', 'message' => 'Network handshake error: ' . $error];
        }

        $decoded = json_decode($response, true);

        if ($error || $httpCode >= 400) {
            $this->logger->error("API Call Failed to {$endpoint} [HTTP {$httpCode}]: " . ($error ?: $response));
            return is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Network error.'];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function getCustomerCare(string $phone): array
    {
        // Pass the phone number to the API so it knows who to SMS
        return $this->callApi('GET', '/member/customer-care?phone=' . urlencode($phone));
    }

    // In App\Models\Utility.php

    public function getTemplevel(string $sessionId): string
    {
        // Fetch the current level from the Slim API
        $response = $this->callApi('GET', '/session/get-level/' . urlencode($sessionId));
        return $response['level'] ?? 'InitialGateway'; // Changed from 'MemberMainMenu'
    }

    public function setTemplevel(string $sessionId, string $level): void
    {
        $this->callApi('POST', '/session/update-state', [
            'session_id' => $sessionId,
            'level' => $level
        ]);
    }

    public function saveInput(string $input, string $sessionId): void
    {
        $this->callApi('POST', '/session/update-input', [
            'session_id' => $sessionId,
            'input' => $input
        ]);
    }

    //check if member account exists in the db

    public function isMemberRegistered(string $phone): bool
    {
        // Call the public check-registration route
        // Note: Adjust the endpoint to match your routes.php structure
        $response = $this->callApi('GET', '/member/check-registration/' . urlencode($phone));

        return ($response['registered'] ?? false) === true;
    }

    /**
     * Calls the API to trigger the Customer Care SMS
     */
    public function requestCustomerCareSms(string $phone): bool
    {
        // Call the endpoint you just created
        // Pass the phone number as a query parameter
        $response = $this->callApi('GET', '/member/customer-care-details?phone=' . urlencode($phone));

        // Check if the API reported success
        return isset($response['status']) && $response['status'] === 'success';
    }

    // In App\Models\Utility.php (the USSD app side)


    public function registerNewMember(string $name, string $phone, string $vocation): bool
    {
        // The API is where the business logic lives. 
        // Send the registration request to your backend.
        $response = $this->callApi('POST', '/auth/register', [
            'name'     => $name,
            'phone'    => $phone,
            'vocation' => $vocation
        ]);

        // Check if the API successfully initiated registration
        return isset($response['status']) && $response['status'] === 'success';
    }

    public function isSessionActive(string $sessionId): bool
    {
        $response = $this->callApi('GET', '/session/get-level/' . urlencode($sessionId));
        return !empty($response['level']);
    }

    public function getSessionInputArray(string $sessionId): array
    {
        $response = $this->callApi('GET', '/session/get-inputs/' . urlencode($sessionId));
        return $response['inputs'] ?? [];
    }

    

    //hits the api endpoint to create a Loan
    /**
     * Hits the API endpoint to create a Loan request
     */
    public function createLoanRequest(string $msisdn, float $amount): bool
    {
        // The API route we created in routes.php was: 
        // $loan->post('/request', [LoanController::class, 'requestLoan']);
        // Note: The /api/v1 prefix is handled by callApi() internally or needs to be prepended

        $response = $this->callApi('POST', '/loan/request', [
            'phone'  => $msisdn,
            'amount' => $amount
        ]);

        // Check if the API returned a success status
        return isset($response['status']) && $response['status'] === 'success';
    }

    //will hit the api endpoint to get a member by their phone number
    public function getMemberByPhone(string $phone): array
    {
        // The URL structure must match your routes.php definition
        return $this->callApi('GET', '/member/find-by-phone/' . urlencode($phone));
    }

    
    /**
     * Fetch list of claims for a specific member
     * The route defined is: $group->group('/welfare', ...) inside /api/v1
     * Full URL: {BASE_URL}/welfare/claims
     */
    public function getWelfareClaimsList(string $phone): array
    {
        // The endpoint string here is relative to the /api/v1 base
        $response = $this->callApi('GET', '/welfare/claims?phone=' . urlencode($phone));

        return $response['claims'] ?? [];
    }

    /**
     * Create a new welfare claim
     * Full URL: {BASE_URL}/welfare/claim
     */
    // In App\Models\Utility.php

    public function createWelfareClaim(string $phone, string $claimType): array
    {
        $response = $this->callApi('POST', '/welfare/claim', [
            'phone'      => $phone,
            'claim_type' => $claimType
        ]);

        // Return the whole response so we can read the message
        return $response;
    }

    
    /**
     * Triggers a direct deposit into the member's welfare wallet (Wallet ID 2).
     * * @param string $phone    The active user phone number initiating the request
     * @param float $amount    The target contribution value input by the user
     * @param int $memberId    The resolved database ID of the member
     * @return bool            True if the STK push was successfully initialized
     */
    public function depositToWelfare(string $phone, float $amount, int $memberId): bool
    {
        $response = $this->callApi('POST', '/welfare/deposit', [
            'phone'     => $phone,
            'amount'    => $amount,
            'member_id' => $memberId
        ]);

        // Returns true only if the backend controller launched the STK thread successfully
        return isset($response['status']) && $response['status'] === 'success';
    }

    /**
     * Triggers a direct deposit into the member's Main Wallet (ID 1).
     * Replaced an atomic credit call with a secure async M-Pesa STK Push flow trigger.
     * * @param string $phone    The active user phone number initiating the request
     * @param float $amount    The target contribution value input by the user
     * @param int $memberId    The resolved database ID of the member
     * @return bool            True if the backend controller successfully launched the STK push
     */
    public function depositToMain(string $phone, float $amount, int $memberId): bool
    {
        $response = $this->callApi('POST', '/main/deposit', [
            'phone'     => $phone,
            'amount'    => $amount,
            'member_id' => $memberId
        ]);

        // Returns true only if the backend controller successfully launched the STK thread successfully
        return isset($response['status']) && $response['status'] === 'success';
    }
    // Fetch member wallet balances (used by WelfareMenuState to find Welfare Balance)
    public function getMemberBalances(string $phone): array
    {
        $response = $this->callApi('GET', '/member/balances?phone=' . urlencode($phone));
        return $response['wallets'] ?? [];
    }

    // Get the chama points balance
    public function getChamaPointsBalance(string $msisdn): int
    {
        $member = $this->getMemberByPhone($msisdn);

        // Log this to your logs so you can see if the lookup is failing
        if (!$member || !isset($member['id'])) {
            $this->logger->error("Balance fetch failed: Member not found for phone: " . $msisdn);
            return 0;
        }

        $response = $this->callApi('GET', '/chama/points/balance/' . $member['id']);

        // Log the raw response to see if the API is returning 0 or failing to find the wallet
        $this->logger->info("API Balance response for member " . $member['id'] . ": " . json_encode($response));

        return (int)($response['balance'] ?? 0);
    }

    /**
     * Redeems Chama Points.
     * Logic: Sends absolute value to API; controller handles debit classification.
     * * @param string $msisdn
     * @param int $points
     * @return array
     */
    public function redeemChamaPoints(string $msisdn, int $points): array
    {
        $member = $this->getMemberByPhone($msisdn);

        if (!$member || !isset($member['id'])) {
            return ['success' => false, 'message' => 'Member not found.'];
        }

        // Ensure we send a positive integer to the API
        $positivePoints = abs($points);

        $response = $this->callApi('POST', '/chama/points/redeem', [
            'member_id' => $member['id'],
            'points'    => $positivePoints
        ]);

        return [
            'success' => isset($response['status']) && $response['status'] === 'success',
            'message' => $response['message'] ?? 'Transaction processed successfully.'
        ];
    }
    /**
     * Adds Chama Points to a member's wallet.
     * This should only be accessible via Agent states.
     * * @param string $msisdn The target member's phone number
     * @param int $points    The number of points to add
     * @return array         Response status and message
     */
    
    public function addChamaPoints(string $msisdn, int $points): array
    {
        // The callApi will now resolve the ID and add the X-Member-ID header
        $response = $this->callApi('POST', '/chama/points/add', [
            'member_id' => $this->getMemberByPhone($msisdn)['id'] ?? 0,
            'points'    => $points
        ], $msisdn); // Pass the msisdn here to trigger resolution

        return [
            'success' => isset($response['status']) && $response['status'] === 'success',
            'message' => $response['message'] ?? 'Points added successfully.'
        ];
    }

    public function withdrawChamaPoints(string $msisdn): array
    {
        $member = $this->getMemberByPhone($msisdn);

        if (!$member || !isset($member['id'])) {
            return ['success' => false, 'message' => 'Member not found.'];
        }

        // Pass $msisdn as the 4th argument to callApi()
        // This resolves the member_id and injects the X-Member-ID header
        $response = $this->callApi('POST', '/chama/points/withdraw', [
            'member_id' => $member['id'],
            'points'    => 15
        ], $msisdn);

        return [
            'success' => isset($response['status']) && $response['status'] === 'success',
            'message' => $response['message'] ?? 'Points withdrawn successfully.'
        ];
    }
   

   /**
     * Checks if a member has a specific role by querying the API securely.
     */
    public function hasRole(string $phone, string $roleName): bool
    {
        // Clean up inputs to protect against hidden network whitespaces
        $cleanPhone = trim($phone);
        $cleanRole  = trim($roleName);

        if (empty($cleanPhone) || empty($cleanRole)) {
            return false;
        }

        $endpoint = '/member/has-role?phone=' . urlencode($cleanPhone) . '&role=' . urlencode($cleanRole);
        $response = $this->callApi('GET', $endpoint);

        // Add visibility to trace what happens during LoginState delegation vs subsequent states
        if (isset($this->logger)) {
            $this->logger->info("USSD Role Verification Matrix for {$cleanPhone} [Role: {$cleanRole}]: " . json_encode($response));
        }

        return (isset($response['has_role']) && $response['has_role'] === true);
    }
}
