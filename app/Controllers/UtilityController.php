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
        $queryParams = $request->getQueryParams();

        $SESSIONID = $queryParams["SESSIONID"] ?? '';
        $USSDCODE  = rawurldecode($queryParams["USSDCODE"] ?? '');
        $MSISDN    = $this->normalizePhoneNumber($queryParams["MSISDN"] ?? '');
        $INPUT     = rawurldecode($queryParams["INPUT"] ?? '');

        $inputArray = ($INPUT === "") ? [] : explode("*", $INPUT);
        $lastInput  = trim(end($inputArray));
        $ussdResponse = "";

        $this->logger->info("USSD Request", [
            'session' => $SESSIONID,
            'msisdn'  => $MSISDN,
            'input'   => $INPUT
        ]);

        if (empty($SESSIONID) || empty($MSISDN)) {
            $response->getBody()->write("END System connection error. Session identifiers missing.");
            return $response->withHeader('Content-Type', 'text/plain');
        }

        /**
         * INITIAL SCREEN HANDLING & PORTAL RESETS
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            
            if ($lastInput !== "00") {
                $this->utility->createSession($SESSIONID, $MSISDN, $USSDCODE);
            } else {
                $this->utility->saveInput($lastInput, $SESSIONID);
            }

            if ($this->utility->isMemberRegistered($MSISDN)) {
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                $currentState = $this->registry->getState("MemberMainMenu");
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
            } else {
                // FIX: Set the state so subsequent requests route here, but output the prompt instantly 
                // so the gateway renders the input box instead of processing an empty input.
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
                $ussdResponse = "CON Welcome! You do not have an account.\nPress 1 to register.";
            }
            
        } else {
            
            /**
             * DYNAMIC STATE ROUTING VIA MULTI-PAGE SESSIONS
             */
            $currentLevel = $this->utility->getTemplevel($SESSIONID);
            
            try {
                $currentState = $this->registry->getState($currentLevel);
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
                
            } catch (\RuntimeException $e) {
                $this->logger->error("USSD State Router Error", ['message' => $e->getMessage()]);
                $ussdResponse = "END System technical hitch. Please try again later.";
            }
        }

        $response->getBody()->write($ussdResponse);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}