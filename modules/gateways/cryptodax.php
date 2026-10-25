<?php
/**
 * CryptoDAX payment gateway for WHMCS 7.x / 8.x
 *
 * Flow:
 *  1. Client clicks "Pay" on the invoice -> POST /v1/invoices (USD)
 *  2. Client is redirected to the CryptoDAX checkout page
 *  3. CryptoDAX POSTs a JSON webhook to modules/gateways/callback/cryptodax.php
 *  4. The callback re-checks the payment through the API (verify) and marks the invoice paid
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/cryptodax/lib.php';

function cryptodax_MetaData()
{
    return array(
        'DisplayName' => 'CryptoDAX',
        'APIVersion'  => '1.1',
    );
}

function cryptodax_config()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'CryptoDAX Payment Gateway',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Your merchant API key from CryptoDAX',
        ),
        'apiUrl' => array(
            'FriendlyName' => 'API URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://pay.cryptodax.io/api',
            'Description'  => 'Base API URL, without a trailing slash (for example https://pay.cryptodax.io/api)',
        ),
        'checkoutUrl' => array(
            'FriendlyName' => 'Checkout URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://pay.cryptodax.io',
            'Description'  => 'If the API does not return a checkout_url, the customer is sent to {Checkout URL}/payment/{invoice_id}',
        ),
        'expireMinutes' => array(
            'FriendlyName' => 'Expire (minutes)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '30',
            'Description'  => 'How long the payment invoice stays valid',
        ),
    );
}

function cryptodax_link($params)
{
    $invoiceId = (int) $params['invoiceid'];
    $amount    = (float) $params['amount'];   // already converted if "Convert To For Processing" is set
    $currency  = strtoupper($params['currency']);
    $lang      = isset($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';

    if ($currency !== 'USD') {
        return '<div class="alert alert-danger">CryptoDAX accepts USD only. In the gateway settings, set Convert To For Processing to USD.</div>';
    }
    if (trim($params['apiKey']) === '' || trim($params['apiUrl']) === '') {
        return '<div class="alert alert-danger">The CryptoDAX gateway is not configured.</div>';
    }

    // Only create the remote invoice when the client actually clicks the button,
    // not every time the invoice page is rendered.
    $clicked = isset($_POST['cryptodax_pay'])
        && isset($_POST['cryptodax_invoice'])
        && (int) $_POST['cryptodax_invoice'] === $invoiceId;

    if (!$clicked) {
        $token = function_exists('generate_token') ? generate_token('plain') : '';
        return '<form method="post" action="">'
            . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '" />'
            . '<input type="hidden" name="cryptodax_invoice" value="' . $invoiceId . '" />'
            . '<input type="submit" name="cryptodax_pay" class="btn btn-success" value="' . htmlspecialchars($lang) . '" />'
            . '</form>';
    }

    $systemUrl   = rtrim($params['systemurl'], '/');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/cryptodax.php';
    // order_id = "<WHMCS invoice id>-<random>" : unique per attempt, and the callback
    // reads the WHMCS invoice id back from the order_id that the API confirms.
    $orderId     = $invoiceId . '-' . bin2hex(random_bytes(8));
    $description = 'Invoice #' . $invoiceId . ' - ' . $params['companyname'];

    list($code, $json, $raw, $err) = cryptodax_create_invoice($params, $amount, $orderId, $description, $callbackUrl);

    if ($code === 201 && !empty($json['invoice_id'])) {
        $idGet = $json['invoice_id'];
        $go = !empty($json['checkout_url'])
            ? $json['checkout_url']
            : rtrim($params['checkoutUrl'], '/') . '/payment/' . rawurlencode($idGet);

        logTransaction($params['paymentmethod'], array(
            'action'     => 'create',
            'invoiceid'  => $invoiceId,
            'order_id'   => $orderId,
            'invoice_id' => $idGet,
            'amount'     => $amount,
        ), 'Invoice Created');

        $goHtml = htmlspecialchars($go, ENT_QUOTES);
        return '<meta http-equiv="refresh" content="0;url=' . $goHtml . '" />'
            . '<script>window.location.href=' . json_encode($go) . ';</script>'
            . '<a class="btn btn-success" href="' . $goHtml . '">Continue to payment</a>';
    }

    logTransaction($params['paymentmethod'], array(
        'action'    => 'create',
        'invoiceid' => $invoiceId,
        'http_code' => $code,
        'curl'      => $err,
        'response'  => $raw,
    ), 'Create Failed');

    $msg = is_array($json) && isset($json['detail']) ? (is_string($json['detail']) ? $json['detail'] : json_encode($json['detail'])) : 'HTTP ' . $code;
    return '<div class="alert alert-danger">Could not connect to CryptoDAX: ' . htmlspecialchars($msg) . '</div>';
}
