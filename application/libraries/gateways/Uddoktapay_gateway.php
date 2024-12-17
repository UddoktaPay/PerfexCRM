<?php

defined('BASEPATH') or exit('No direct script access allowed');

class UddoktaPayApi
{
    private $apiKey;
    private $apiBaseURL;

    public function __construct($apiKey, $apiBaseURL)
    {
        $this->apiKey = $apiKey;
        $this->apiBaseURL = $this->normalizeBaseURL($apiBaseURL);
    }

    private function normalizeBaseURL($apiBaseURL)
    {
        $baseURL = rtrim($apiBaseURL, '/');
        $apiSegmentPosition = strpos($baseURL, '/api');

        if ($apiSegmentPosition !== false) {
            $baseURL = substr($baseURL, 0, $apiSegmentPosition + 4); // Include '/api'
        }

        return $baseURL;
    }

    private function buildURL($endpoint)
    {
        $endpoint = ltrim($endpoint, '/');
        return $this->apiBaseURL . '/' . $endpoint;
    }

    public function initPayment($requestData, $apiType = 'checkout-v2')
    {
        $apiUrl = $this->buildURL($apiType);
        $response = $this->sendRequest('POST', $apiUrl, $requestData);

        $this->validateApiResponse($response, 'Payment request failed');
        return $response['payment_url'];
    }

    public function verifyPayment($invoiceId)
    {
        $verifyUrl = $this->buildURL('verify-payment');
        $requestData = ['invoice_id' => $invoiceId];
        return $this->sendRequest('POST', $verifyUrl, $requestData);
    }

    public function executePayment()
    {
        $headerApi = $_SERVER['HTTP_RT_UDDOKTAPAY_API_KEY'] ?? null;
        $this->validateApiHeader($headerApi);

        $rawInput = trim(file_get_contents('php://input'));
        $this->validateIpnResponse($rawInput);

        $data = json_decode($rawInput, true);
        $invoiceId = $data['invoice_id'];

        return $this->verifyPayment($invoiceId);
    }

    private function sendRequest($method, $url, $data)
    {
        $headers = [
            'RT-UDDOKTAPAY-API-KEY: ' . $this->apiKey,
            'accept: application/json',
            'content-type: application/json',
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("cURL Error: $error");
        }

        return json_decode($response, true);
    }

    private function validateApiHeader($headerApi)
    {
        if ($headerApi === null) {
            throw new Exception("Invalid API Key");
        }

        $apiKey = trim($this->apiKey);

        if ($headerApi !== $apiKey) {
            throw new Exception("Unauthorized Action.");
        }
    }

    private function validateApiResponse($response, $errorMessage)
    {
        if (!isset($response['payment_url'])) {
            $message = isset($response['message']) ? $response['message'] : $errorMessage;
            throw new Exception($message);
        }
    }

    private function validateIpnResponse($rawInput)
    {
        if (empty($rawInput)) {
            throw new Exception("Invalid response from UddoktaPay API.");
        }
    }
}

class Uddoktapay_gateway extends App_gateway
{
    public bool $processingFees = false;

    public function __construct()
    {

        /**
         * Call App_gateway __construct function
         */
        parent::__construct();
        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('uddoktapay');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('BD Payment Methods');

        /**
         * Add gateway settings
         */
        $this->setSettings([
            [
                'name'      => 'api_key',
                'encrypted' => true,
                'label'     => 'UddoktaPay API KEY',
            ],
            [
                'name'      => 'api_url',
                'encrypted' => true,
                'label'     => 'UddoktaPay API URL',
            ],
            [
                'name'          => 'exchange_rate',
                'encrypted'     => true,
                'label'         => 'Exchange Rate [1 USD = ? BDT]',
                'default_value' => '110',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Payment for Invoice {invoice_number}',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'currency',
                'default_value' => 'BDT',
            ],
        ]);
    }

    /**
     * Process the payment
     *
     * @param  array $data
     *
     * @return mixed
     */
    public function process_payment($data)
    {
        if (is_client_logged_in()) {
            $contact = $this->ci->clients_model->get_contact(get_contact_user_id());
        } else {
            if (total_rows(db_prefix() . 'contacts', ['userid' => $data['invoice']->clientid]) == 1) {
                $contact = $this->ci->clients_model->get_contact(get_primary_contact_user_id($data['invoice']->clientid));
            }
        }

        $amount = number_format($data['amount'], 2, '.', '');
        $currency = $data['invoice']->currency_name;
        $webhookKey = app_generate_hash();
        $returnUrl = site_url('gateways/uddoktapay/verify_payment?invoiceid=' . $data['invoice']->id . '&hash=' . $data['invoice']->hash);
        $webhookUrl = site_url('gateways/uddoktapay/webhook/' . $webhookKey);
        $invoiceUrl = site_url('invoice/' . $data['invoice']->id . '/' . $data['invoice']->hash);

        if ($currency !== 'BDT') {
            $exchangedAmount *= $this->decryptSetting('exchange_rate');
        }

        $requestData = [
            'full_name'    => $contact->firstname . ' ' . $contact->lastname,
            'email'        => $contact->email,
            'amount'       => $exchangedAmount,
            'metadata'     => [
                'invoice_id'  => $data['invoice']->id,
                'webhook_key' => $webhookKey,
                'amount' => $amount,
            ],
            'redirect_url' => $returnUrl,
            'return_type'  => 'GET',
            'cancel_url'   => $invoiceUrl,
            'webhook_url'  => $webhookUrl,
        ];

        try {
            $uddoktaPay = new UddoktaPayApi($this->decryptSetting('api_key'), $this->decryptSetting('api_url'));
            $paymentUrl = $uddoktaPay->initPayment($requestData);
            redirect($paymentUrl);
        } catch (\Exception $e) {
            set_alert('danger', "Initialization Error: " . $e->getMessage());
            redirect($invoiceUrl);
        }
    }

    /**
     * Retrieve payment from UddoktaPay
     *
     * @param  string $invoice_id
     *
     * @return mixed
     */
    public function fetch_payment($invoice_id = null)
    {
        try {
            $uddoktaPay = new UddoktaPayApi($this->decryptSetting('api_key'), $this->decryptSetting('api_url'));
            if ($invoice_id) {
                return $uddoktaPay->verifyPayment($invoice_id);
            } else {
                return $uddoktaPay->executePayment();
            }
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
