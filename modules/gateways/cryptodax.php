<?php
/**
 * CryptoDAX WHMCS Third Party Payment Gateway
 *
 * Webhook-first design:
 * - The browser return URL is UX only.
 * - An invoice is marked paid ONLY by a verified CryptoDAX webhook.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function cryptodax_MetaData()
{
    return [
        'DisplayName' => 'CryptoDAX',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function cryptodax_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'CryptoDAX',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://pay.cryptodax.io/api',
            'Description' => 'CryptoDAX API base URL, without trailing slash. Example: https://pay.cryptodax.io/api',
        ],
        'checkoutBaseUrl' => [
            'FriendlyName' => 'Checkout Base URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://pay.cryptodax.io',
            'Description' => 'Public checkout site. The Pay button opens {base}/invoice/{invoice_id}.',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Merchant API key (Authorization: Bearer).',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Same value as CryptoDAX WEBHOOK_SECRET. Used to verify X-Webhook-Signature.',
        ],
    ];
}

/**
 * Create/retrieve a CryptoDAX invoice and render a Pay button.
 *
 * POST /api/v1/invoices
 * Duplicate WHMCS invoice IDs reuse the same CryptoDAX order_id.
 */
function cryptodax_link($params)
{
    $invoiceId = (string) $params['invoiceid'];
    $amount = number_format((float) $params['amount'], 2, '.', '');
    $currency = strtoupper((string) $params['currency']);
    $description = (string) ($params['description'] ?? ('WHMCS Invoice #' . $invoiceId));

    if ($currency !== 'USD') {
        return '<div class="alert alert-danger">CryptoDAX currently accepts WHMCS invoices in USD only.</div>';
    }

    $apiUrl = rtrim((string) $params['apiUrl'], '/');
    $checkoutBaseUrl = rtrim((string) ($params['checkoutBaseUrl'] ?? ''), '/');
    $apiKey = trim((string) $params['apiKey']);

    if ($apiUrl === '' || $apiKey === '' || $checkoutBaseUrl === '') {
        logTransaction('CryptoDAX', ['invoice_id' => $invoiceId], 'Gateway Configuration Error');
        return '<div class="alert alert-danger">CryptoDAX is not configured correctly.</div>';
    }

    $payload = [
        'amount' => $amount,
        'currency' => 'USD',
        'order_id' => $invoiceId,
        'description' => $description,
        'expire_minutes' => 1440,
    ];

    $ch = curl_init($apiUrl . '/v1/invoices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        logTransaction(
            'CryptoDAX',
            ['invoice_id' => $invoiceId, 'error' => $curlError],
            'Create Payment Connection Error'
        );

        return '<div class="alert alert-danger">Unable to connect to CryptoDAX.</div>';
    }

    $data = json_decode((string) $response, true);

    if (
        $httpCode < 200 ||
        $httpCode >= 300 ||
        !is_array($data) ||
        empty($data['invoice_id'])
    ) {
        logTransaction(
            'CryptoDAX',
            [
                'invoice_id' => $invoiceId,
                'http_code' => $httpCode,
                'response' => is_array($data) ? $data : (string) $response,
            ],
            'Create Payment Error'
        );

        return '<div class="alert alert-danger">Unable to create CryptoDAX payment.</div>';
    }

    $checkoutUrl = $checkoutBaseUrl . '/invoice/' . rawurlencode((string) $data['invoice_id']);
    $safeCheckoutUrl = htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8');
    $buttonText = htmlspecialchars((string) ($params['langpaynow'] ?? 'Pay Now'), ENT_QUOTES, 'UTF-8');

    return '<form method="get" action="' . $safeCheckoutUrl . '">'
        . '<button type="submit" class="btn btn-primary">' . $buttonText . ' with CryptoDAX</button>'
        . '</form>';
}
