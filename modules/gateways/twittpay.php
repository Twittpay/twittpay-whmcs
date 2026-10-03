<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/twittpay/Support/Enums/ErrorCode.php';
require_once __DIR__ . '/twittpay/Handler/GatewayKernel.php';

use TwittPay\Gateway\Handler\GatewayKernel;

function twittpay_MetaData()
{
    return array(
        'DisplayName' => 'TwittPay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function twittpay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'TwittPay',
        ),
        'apiKey' => array(
            'FriendlyName' => 'Brand Key',
            'Type' => 'text',
            'Size' => '150',
            'Default' => '',
            'Description' => 'Enter your TwittPay Merchant API Key',
        ),
        'currency_rate' => array(
            'FriendlyName' => 'USD to BDT Rate',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '122',
            'Description' => 'Used only when the invoice currency is USD',
        ),
    );
}

function twittpay_link($params)
{
    return GatewayKernel::renderPayButton($params);
}
