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
 * It intercepts raw telecom gateway requests, normalizes parameters, tracks session state
 * via the database, and dynamically dispatches processing to individual state classes.
 */
class UtilityController extends Controller
{
    /** @var Utility Instance handles database logic, ledger profiles, and transactional data checks. */
    private Utility $utility;

    /** @var UssdStateRegistry Central factory map associating state string keys with concrete handler classes. */
    private UssdStateRegistry $registry;

    /**
     * Controller Constructor.
     * Instantiates parent controller classes and pulls dependencies from the PSR-11 container.
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->utility = $container->get(Utility::class);
        $this->registry = $container->get(UssdStateRegistry::class);
    }

    /**
     * Normalizes Kenyan mobile numbers to the standard International E.164 format.
     * Converts local prefix formats (e.g., 07... or 01...) into 254... country codes.
     *
     * @param string $phone Raw phone number from the telco network gateway.
     * @return string Normalized phone number string prefixed with 254.
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
     * Processes incoming HTTP GET/POST queries forwarded from the telco provider hook.
     *
     * @param Request $request
     * @param Response $response
     * @return Response Plain-text response mapped to standard USSD protocol structures (CON / END).
     */
    public function __invoke(Request $request, Response $response): Response
    {
        // Extract parameters transmitted by the USSD service provider gateway
        $queryParams = $request->getQueryParams();

        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE  = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN    = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT     = rawurldecode($queryParams["INPUT"] ?? '');

        // Break up long session input parameters (e.g., "1*2*3") into an iterable tracking array
        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT);
        
        // Isolate the very last item typed by the user for processing evaluations
        $lastInput  = trim(end($inputArray));
        $ussdResponse = "";

        // Track and log trace logs for remote ngrok debugging workflows
        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn'  => $MSISDN,
            'input'   => $INPUT
        ]);

        // Safety Validation: Halt early if critical transaction identifiers are missing
        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error. Session identifiers missing.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

        /**
         * INITIAL SCREEN HANDLING & PORTAL RESETS
         * Triggered if the input string is blank (brand-new session instantiation)
         * or if structural command routes match explicit menu resets.
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            
            // If it is not a 'Back' command, it's a completely fresh dial-in; initialize the database log entry
            if ($lastInput !== "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                // Otherwise, save the back navigation entry inside historical logs safely
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            // Route Evaluation: Determine menu access paths depending on registration profile status
            if ($this->utility->isMemberRegistered($MSISDN)) {
                // Enrolled user -> point them straight to the primary control menu dashboard
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                $currentState = $this->registry->getState("MemberMainMenu");
            } else {
                // Unregistered subscriber -> redirect immediately to onboarding fields
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
                $currentState = $this->registry->getState("PromptRegistration");
            }
            
            // Execute the resolved state class menu layout logic
            $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
            
        } else {
            
            /**
             * DYNAMIC STATE ROUTING VIA MULTI-PAGE SESSIONS
             * Executed for active, ongoing multi-tiered interactive paths.
             */
            
            // Look up the user's active sub-menu level saved during their last request
            $currentLevel = $this->utility->getTemplevel($SESSIONID);
            
            try {
                // Match the text level key against class references inside UssdStateRegistry
                $currentState = $this->registry->getState($currentLevel);
                
                // Pass flow control to the resolved state handler class instance
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
                
            } catch (\RuntimeException $e) {
                // Gracefully catch registry class lookup mismatches to prevent raw PHP trace crashes
                $this->logger->error("USSD State Router Error", ['message' => $e->getMessage()]);
                $ussdResponse = "END System technical hitch. Please try again later.";
            }
        }

        // Write the compiled textual payload response into the HTTP body wrapper
        $response->getBody()->write($ussdResponse);
        
        // Return plain-text headers required by commercial telecommunication network gateways
        return $response->withHeader('Content-Type', 'text/plain');
    }
}