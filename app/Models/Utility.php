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

    public function callApi(string $method, string $endpoint, array $data = []): array
    {
        $baseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost:8080/api/v1';
        $token = $_ENV['USSD_CLIENT_TOKEN'] ?? '';

        $ch = curl_init($baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode($response, true);

        // If an error occurred (HTTP 4xx/5xx), return the error message if available
        if ($error || $httpCode >= 400) {
            $this->logger->error("API Call Failed to {$endpoint} [HTTP {$httpCode}]: " . ($error ?: $response));
            // Return the API error response if it exists, otherwise empty array
            return is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Network error or unreachable.'];
        }

        return is_array($decoded) ? $decoded : [];
    }
    // In App\Models\Utility.php

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
        return $response['level'] ?? 'MemberMainMenu'; // Default to Main Menu if unknown
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

    // Fetch list of claims for a specific member
    // In App\Models\Utility.php

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
     * Triggers a direct deposit into the member's welfare wallet.
     * * @param string $phone
     * @param float $amount
     * @return bool
     */
    // public function depositToWelfare(string $phone, float $amount): bool
    // {
    //     $response = $this->callApi('POST', '/welfare/deposit', [
    //         'phone'  => $phone,
    //         'amount' => $amount
    //     ]);

    //     // Returns true only if the API returned status: success
    //     return isset($response['status']) && $response['status'] === 'success';
    // }
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

    // Add this to App\Models\Utility.php

    // Add this to App\Models\Utility.php

    /**
     * Triggers a direct deposit into the member's Main Wallet (ID 1).
     */
    public function depositToMain(int $memberId, int $amount): bool
    {
        // Ensure this matches the endpoint defined in your routes
        $response = $this->callApi('POST', '/main/deposit', [
            'member_id' => $memberId,
            'amount'    => $amount
        ]);

        // Return true only if the API returned status: success
        return isset($response['status']) && $response['status'] === 'success';
    }
    // Fetch member wallet balances (used by WelfareMenuState to find Welfare Balance)
    public function getMemberBalances(string $phone): array
    {
        $response = $this->callApi('GET', '/member/balances?phone=' . urlencode($phone));
        return $response['wallets'] ?? [];
    }
}
