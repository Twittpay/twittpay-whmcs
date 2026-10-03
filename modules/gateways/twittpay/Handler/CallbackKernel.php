<?php

namespace TwittPay\Gateway\Handler;

use TwittPay\Gateway\Http\TwittPayClient;
use TwittPay\Gateway\Support\Enums\PaymentStatus;
use TwittPay\Gateway\Support\Enums\ErrorCode;
use TwittPay\Gateway\Support\Exceptions\TwittPayException;
use WHMCS\Config\Setting;

final class CallbackKernel
{
    private string $gatewayModuleName;
    private array $gatewayParams;
    private TwittPayClient $client;
    private string $systemUrl;
    private string $callbackUrl;
    private float $currencyRate;

    public function __construct(string $gatewayModuleName)
    {
        $this->gatewayModuleName = $gatewayModuleName;
        $this->gatewayParams     = getGatewayVariables($gatewayModuleName);

        if (empty($this->gatewayParams['type'])) {
            die('Gateway is not active.');
        }

        $this->currencyRate = (float) $this->gatewayParams['currency_rate'];
        $this->systemUrl    = rtrim(Setting::getValue('SystemURL'), '/');
        $this->callbackUrl  = $this->systemUrl . '/modules/gateways/callback/' . $gatewayModuleName . '.php';
        $this->client        = new TwittPayClient($this->gatewayParams['apiKey']);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'init':
                $this->handleInit();
                break;
            case 'return':
                $this->handleReturn();
                break;
            case 'ipn':
                $this->handleIpn();
                break;
            default:
                $this->badRequest();
        }
    }

    private function handleInit(): void
    {
        $invoiceId = (int) ($_REQUEST['invoiceid'] ?? 0);
        $invoice   = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        if ($invoice['result'] !== 'success') {
            die('Invalid invoice.');
        }

        $clientDetails = localAPI('GetClientsDetails', ['clientid' => $invoice['userid']]);
        $currencyCode  = $this->resolveCurrencyCode($invoiceId);
        $total         = $this->convertToPayable((float) $invoice['balance'], $currencyCode);

        $payload = [
            'cus_name'    => trim($clientDetails['firstname'] . ' ' . $clientDetails['lastname']),
            'cus_email'   => $clientDetails['email'],
            'amount'      => $total,
            'webhook_url' => $this->callbackUrl . '?action=ipn',
            'success_url' => $this->callbackUrl . '?action=return&invoiceid=' . $invoiceId,
            'cancel_url'  => $this->systemUrl . '/viewinvoice.php?id=' . $invoiceId,
            'metadata'    => ['invoiceid' => $invoiceId],
        ];

        try {
            $response = $this->client->createPayment($payload);
        } catch (TwittPayException $e) {
            $this->logAttempt('init', $invoiceId, $payload, ['error' => $e->getMessage()], 'Init Failed');
            $this->redirectToInvoiceWithError($invoiceId, ErrorCode::INVALID);
            return;
        }

        $this->logAttempt(
            'init',
            $invoiceId,
            $payload,
            $response,
            !empty($response['status']) ? 'Session Created' : 'Init Failed'
        );

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            header('Location: ' . $response['payment_url']);
            exit;
        }

        $this->redirectToInvoiceWithError($invoiceId, ErrorCode::INVALID);
    }

    private function handleReturn(): void
    {
        $invoiceId     = (int) ($_REQUEST['invoiceid'] ?? 0);
        $transactionId = (string) ($_REQUEST['transactionId'] ?? '');

        $result = $this->verifyAndCredit($transactionId);

        if ($result['status'] === 'success') {
            redirSystemURL('id=' . $result['invoiceid'], 'viewinvoice.php');
            exit;
        }

        $this->redirectToInvoiceWithError($invoiceId, $result['error']);
    }

    private function handleIpn(): void
    {
        $transactionId = (string) ($_REQUEST['transactionId'] ?? '');
        $this->verifyAndCredit($transactionId);
        exit;
    }

    private function verifyAndCredit(string $transactionId): array
    {
        try {
            $payment = $this->client->verifyPayment($transactionId);
        } catch (TwittPayException $e) {
            $this->logAttempt('verify', 0, ['transaction_id' => $transactionId], ['error' => $e->getMessage()], 'Verify Failed');
            return ['status' => 'error', 'error' => ErrorCode::INVALID];
        }

        $status = $payment['status'] ?? '';
        $this->logAttempt('verify', 0, ['transaction_id' => $transactionId], $payment, $status ?: 'No Response');

        if ($status === PaymentStatus::PENDING) {
            return ['status' => 'error', 'error' => ErrorCode::PENDING_VERIFY];
        }

        if ($status !== PaymentStatus::COMPLETED) {
            return ['status' => 'error', 'error' => ErrorCode::INVALID];
        }

        $invoiceId = $this->extractInvoiceId($payment['metadata'] ?? null);

        if ($invoiceId <= 0) {
            return ['status' => 'error', 'error' => ErrorCode::INVALID];
        }

        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

        if ($invoice['result'] !== 'success') {
            return ['status' => 'error', 'error' => ErrorCode::INVALID];
        }

        $expected = $this->convertToPayable((float) $invoice['balance'], $this->resolveCurrencyCode($invoiceId));
        $paid     = (float) ($payment['amount'] ?? 0);

        if ($paid < ($expected - 1)) {
            return ['status' => 'error', 'error' => ErrorCode::MISMATCH, 'invoiceid' => $invoiceId];
        }

        checkCbTransID($transactionId);
        addInvoicePayment($invoiceId, $transactionId, $paid, 0, $this->gatewayModuleName);

        return ['status' => 'success', 'invoiceid' => $invoiceId];
    }

    private function extractInvoiceId($metadata): int
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        if (!is_array($metadata)) {
            return 0;
        }

        return (int) ($metadata['invoiceid'] ?? 0);
    }

    private function convertToPayable(float $amount, string $currencyCode): float
    {
        return $currencyCode === 'USD' ? $amount * $this->currencyRate : $amount;
    }

    private function resolveCurrencyCode(int $invoiceId): string
    {
        $result = localAPI('GetInvoices', ['invoiceid' => $invoiceId]);

        if ($result['result'] === 'success' && isset($result['invoices']['invoice'][0]['currencycode'])) {
            return (string) $result['invoices']['invoice'][0]['currencycode'];
        }

        if (function_exists('logActivity')) {
            logActivity("TwittPay: could not resolve currency code for invoice #{$invoiceId}, defaulting to BDT.");
        }

        return 'BDT';
    }

    private function logAttempt(string $stage, int $invoiceId, array $request, array $response, string $status): void
    {
        logTransaction($this->gatewayModuleName, [
            'stage'     => $stage,
            'invoiceid' => $invoiceId,
            'request'   => $request,
            'response'  => $response,
        ], $status);
    }

    private function redirectToInvoiceWithError(int $invoiceId, string $errorCode): void
    {
        redirSystemURL('id=' . $invoiceId . '&error=' . $errorCode, 'viewinvoice.php');
        exit;
    }

    private function badRequest(): void
    {
        http_response_code(400);
        echo 'Invalid request.';
        exit;
    }
}
