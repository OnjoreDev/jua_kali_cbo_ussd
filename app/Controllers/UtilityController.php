<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use App\Ussd\UssdStateRegistry;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UtilityController extends Controller
{
    private Utility $utility;
    private UssdStateRegistry $registry;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
        $this->registry = $container->get(UssdStateRegistry::class);
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $phone = trim($phone);
        if (str_starts_with($phone, '07') || str_starts_with($phone, '01')) {
            return '254' . substr($phone, 1);
        }
        return $phone;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE  = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN    = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT     = rawurldecode($queryParams["INPUT"] ?? '');

        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT);
        $lastInput  = trim(end($inputArray));
        $ussdResponse = "";

        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

        // Fetch the current session tracking state level from the DB/API
        $currentLevel = $this->utility->getTemplevel($SESSIONID);

        /**
         * 1. INITIAL SCREEN HANDLING & GATEKEEPER
         * Catches fresh sessions, drops, network resets, or neutral entry fallbacks
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00" || $currentLevel === "InitialGateway") {
            if ($lastInput !== "00") {
                $this->utility->callApi('POST', '/session/create', [
                    'session_id' => $SESSIONID,
                    'msisdn'     => $MSISDN,
                    'ussd_code'  => $USSDCODE
                ]);
            }

            // Look up the member profile via your public endpoint
            $memberData = $this->utility->callApi('GET', '/member/find-by-phone/' . urlencode($MSISDN));

            // Check if the member profile exists in the database
            if (!empty($memberData) && !isset($memberData['status'])) {

                // PART 2 REGISTRATION: Account exists, but PIN has not been configured yet
                if (empty($memberData['pin_hash'])) {
                    $this->utility->setTemplevel($SESSIONID, "VerifyOtpState");
                    $currentState = $this->registry->getState("VerifyOtpState");
                    // Pass empty string as input so VerifyOtpState displays its initial input prompt
                    $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, "", [], $this->utility);
                }

                // REGULAR LOGIN: User is fully registered with a security PIN hash
                else {
                    $this->utility->setTemplevel($SESSIONID, "LoginState");
                    $currentState = $this->registry->getState("LoginState");
                    $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, "", [], $this->utility);
                }
            }

            // NEW REGISTRATION: Phone number not found in the members table
            else {
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
                $ussdResponse = "CON Welcome to Jua Kali CBO!\n1. Register";
            }
        }
        /**
         * 2. DYNAMIC STATE ROUTING
         */
        else {
            // NAVIGATION FILTER: Intercept global back/menu navigation codes safely
            if ($lastInput === "39" || $lastInput === "00") {
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                $currentState = $this->registry->getState("MemberMainMenu");
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, "", [], $this->utility);
            }
            // REGISTRATION SHORTCUT: Handle explicit option selection from introductory screen
            elseif ($currentLevel === "PromptRegistration" && $lastInput === "1") {
                $this->utility->setTemplevel($SESSIONID, "CaptureName");
                $ussdResponse = "CON Please enter your Full Name:";
            }
            // STANDARD OBJECT-ORIENTED STATE PROCESSING
            else {
                try {
                    $currentState = $this->registry->getState($currentLevel);
                    $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
                } catch (\RuntimeException $e) {
                    if (property_exists($this, 'logger')) {
                        $this->logger->error("USSD State Router Error", ['message' => $e->getMessage()]);
                    }
                    $ussdResponse = "END System technical hitch. Please try again later.";
                }
            }
        }

        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
