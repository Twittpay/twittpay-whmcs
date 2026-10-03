<?php

namespace TwittPay\Gateway\Handler;

use TwittPay\Gateway\Support\Enums\ErrorCode;

final class GatewayKernel
{
    public static function renderPayButton(array $params): string
    {
        $invoiceId  = (int) $params['invoiceid'];
        $systemUrl  = rtrim($params['systemurl'], '/');
        $actionUrl  = $systemUrl . '/modules/gateways/callback/twittpay.php';
        $buttonText = (string) $params['langpaynow'];

        $alert       = self::renderAlertIfAny();
        $escapedUrl  = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');
        $escapedText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');

        return $alert . <<<HTML
        <form method="GET" action="{$escapedUrl}">
            <input type="hidden" name="action" value="init" />
            <input type="hidden" name="invoiceid" value="{$invoiceId}" />
            <input class="btn btn-primary" type="submit" value="{$escapedText}" />
        </form>
        HTML;
    }

    private static function renderAlertIfAny(): string
    {
        $errorCode = $_GET['error'] ?? '';

        if ($errorCode === '') {
            return '';
        }

        $type    = ($errorCode === ErrorCode::PENDING_VERIFY) ? 'warning' : 'danger';
        $message = htmlspecialchars(ErrorCode::message($errorCode), ENT_QUOTES, 'UTF-8');

        return "<div class=\"alert alert-{$type}\" style=\"margin-top:10px;\">{$message}</div>";
    }
}
