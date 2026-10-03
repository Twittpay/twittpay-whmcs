<?php

namespace TwittPay\Gateway\Support\Exceptions;

final class TwittPayException extends \Exception
{
    public static function make(string $message): self
    {
        return new self($message);
    }
}
