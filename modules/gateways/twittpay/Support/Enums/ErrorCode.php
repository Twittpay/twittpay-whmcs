<?php

namespace TwittPay\Gateway\Support\Enums;

final class ErrorCode
{
    public const INVALID        = 'invalid';
    public const USED           = 'used';
    public const MISMATCH       = 'mismatch';
    public const PENDING_VERIFY = 'pending';

    private const MESSAGES = [
        self::INVALID        => 'Payment verification failed. Please try again or contact support.',
        self::USED           => 'This transaction has already been processed.',
        self::MISMATCH       => 'Payment amount does not match the invoice total. Please contact support.',
        self::PENDING_VERIFY => 'Your payment is still being verified. This page will update shortly.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Something went wrong. Please try again.';
    }
}
