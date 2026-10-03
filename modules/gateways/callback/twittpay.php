<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/../twittpay/Support/Exceptions/TwittPayException.php';
require_once __DIR__ . '/../twittpay/Support/Enums/PaymentStatus.php';
require_once __DIR__ . '/../twittpay/Support/Enums/ErrorCode.php';
require_once __DIR__ . '/../twittpay/Http/TwittPayClient.php';
require_once __DIR__ . '/../twittpay/Handler/CallbackKernel.php';

use TwittPay\Gateway\Handler\CallbackKernel;

$action = (string) ($_REQUEST['action'] ?? '');

(new CallbackKernel('twittpay'))->handle($action);
