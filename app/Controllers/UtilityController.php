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

        /**
         * 1. INITIAL SCREEN HANDLING & GATEKEEPER
         */
        if ($INPUT === "" || $lastInput === "39" || $lastInput === "00") {
            if ($lastInput !== "00") {
                $this->utility->callApi('POST', '/session/create', [
                    'session_id' => $SESSIONID,
                    'msisdn'     => $MSISDN,
                    'ussd_code'  => $USSDCODE
                ]);
            }

            if ($this->utility->isMemberRegistered($MSISDN)) {
                $this->utility->setTemplevel($SESSIONID, "LoginState");
                $currentState = $this->registry->getState("LoginState");
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, "", [], $this->utility);
            } else {
                $this->utility->setTemplevel($SESSIONID, "PromptRegistration");
                $ussdResponse = "CON Welcome! You do not have an account.\nPress 1 to register.";
            }
        }
        /**
         * 2. DYNAMIC STATE ROUTING (With Navigation Filtering)
         */
        else {
            $currentLevel = $this->utility->getTemplevel($SESSIONID);

            // NAVIGATION FILTER: Intercept triggers before they reach state logic
            if ($lastInput === "39" || $lastInput === "00") {
                $this->utility->setTemplevel($SESSIONID, "MemberMainMenu");
                $currentState = $this->registry->getState("MemberMainMenu");
                $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, "", [], $this->utility);
            } 
            // SPECIAL CASE: Registration trigger
            elseif ($currentLevel === "PromptRegistration" && $lastInput === "1") {
                $this->utility->setTemplevel($SESSIONID, "CaptureName");
                $ussdResponse = "CON Please enter your Full Name:";
            } 
            // STANDARD STATE PROCESSING
            else {
                try {
                    $currentState = $this->registry->getState($currentLevel);
                    $ussdResponse = $currentState->handle($SESSIONID, $MSISDN, $lastInput, $inputArray, $this->utility);
                } catch (\RuntimeException $e) {
                    // Preserved your logger usage
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