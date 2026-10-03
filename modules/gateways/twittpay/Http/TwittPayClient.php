<?php

namespace TwittPay\Gateway\Http;

use TwittPay\Gateway\Support\Exceptions\TwittPayException;

final class TwittPayClient
{
    private const BASE_URL        = 'https://checkout.twittpay.com/api/payment';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT         = 15;
    private const MAX_ATTEMPTS    = 3;

    // curl errno 6 = could not resolve host, 7 = couldn't connect,
    // 28 = operation timed out — these are the transient network-level
    // failures worth retrying (Cloudflare handshake glitches, brief DNS
    // hiccups). Anything else (bad SSL cert, refused, etc.) fails fast.
    private const RETRYABLE_ERRNOS = [6, 7, 28];

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $trimmed = trim($apiKey);

        if ($trimmed === '') {
            throw TwittPayException::make('TwittPay API key is not configured.');
        }

        $this->apiKey = $trimmed;
    }

    public function createPayment(array $payload): array
    {
        return $this->post('/create', $payload);
    }

    public function verifyPayment(string $transactionId): array
    {
        if (trim($transactionId) === '') {
            throw TwittPayException::make('Transaction ID cannot be empty.');
        }

        return $this->post('/verify', ['transaction_id' => $transactionId]);
    }

    private function post(string $path, array $payload): array
    {
        $endpoint  = self::BASE_URL . $path;
        $attempt   = 0;
        $lastError = '';

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'API-KEY: ' . $this->apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($curl);
            $errno    = curl_errno($curl);
            $error    = curl_error($curl);
            curl_close($curl);

            if ($errno === 0) {
                $decoded = json_decode((string) $response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw TwittPayException::make('Invalid JSON response from TwittPay.');
                }

                return $decoded ?? [];
            }

            $lastError = $error;

            if (!in_array($errno, self::RETRYABLE_ERRNOS, true)) {
                break;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep((int) pow(2, $attempt)); // 2s, 4s, 8s
            }
        }

        if (function_exists('logActivity')) {
            logActivity(sprintf(
                'TwittPay gateway unreachable after %d attempt(s): %s',
                $attempt,
                $lastError
            ));
        }

        throw TwittPayException::make('Payment gateway temporarily unavailable. Please try again shortly.');
    }
}
