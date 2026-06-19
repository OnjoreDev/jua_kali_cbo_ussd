<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Utility;
use App\Ussd\UssdStateRegistry;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * UtilityController Class
 *
 * Acts as the centralized HTTP entry gateway for all incoming USSD traffic.
 */
class UtilityController extends Controller
{
    /** @var Utility Instance handles database logic, ledger profiles, and transactional data checks. */
    private Utility $utility;

    /** @var UssdStateRegistry Central factory map associating state string keys with concrete handler classes. */
    private UssdStateRegistry $registry;

    /**
     * Controller Constructor.
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
        $this->registry = $container->get(UssdStateRegistry::class);
    }

    /**
     * Normalizes Kenyan mobile numbers to the standard International E.164 format.
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
     * Single Action Invocation Endpoint.
     */
    public function __invoke(Request $request, Response $response): Response
    {
        // Retrieve all URL parameters (query string) sent by the USSD Gateway
        $queryParams = $request->getQueryParams();

        // Extract core session identifiers with default empty strings to avoid errors
        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        // Decode the USSD dial string (e.g., *123#)
        $USSDCODE  = rawurldecode($queryParams["USSDCODE"] ?? '');
        // Normalize phone number (e.g., converting 07... to +2547...)
        $MSISDN    = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        // Decode user input (which often contains '*' separators for navigation)
        $INPUT     = rawurldecode($queryParams["INPUT"] ?? '');

        // Convert input string into an array (e.g., '1*2' becomes ['1', '2'])
        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT);
        // Get the very last action taken by the user
        $lastInput  = trim(end($inputArray));
        $ussdResponse = "";

        // Log incoming traffic for debugging and analytics
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn'  => $MSISDN,
            'input'   => $INPUT
        ]);

        // Validation: Ensure the gateway provided mandatory identification
        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error. Session identifiers missing.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

        /**
         * INITIAL SCREEN HANDLING & PORTAL RESETS
         * Check if user is starting fresh or triggering a reset (back/home)
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {

            // If it's a new session, create one; otherwise, save the navigation input
            if ($lastInput !== "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            // Logic routing: If registered, load main menu; if not, force registration
            if ($this->utility->isMemberRegistered($MSISDN)) {
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                $currentState = $this->registry->getState("MemberMainMenu");
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
            } else {
                // Set state to registration and prompt the user
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
                $ussdResponse = "CON Welcome! You do not have an account.\nPress 1 to register.";
            }
        } else {

            /**
             * DYNAMIC STATE ROUTING VIA MULTI-PAGE SESSIONS
             * Determine where the user is in the menu flow based on session storage
             */
            $currentLevel = $this->utility->getTemplevel($SESSIONID);

            try {
                // Fetch the handler associated with the current navigation level
                $currentState = $this->registry->getState($currentLevel);
                // Process the user input within that specific state/menu
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
            } catch (\RuntimeException $e) {
                // Handle unexpected failures gracefully
                $this->logger->error("USSD State Router Error", ['message' => $e->getMessage()]);
                $ussdResponse = "END System technical hitch. Please try again later.";
            }
        }

        // Return the response string (CON for continuation, END for termination) to the gateway
        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
