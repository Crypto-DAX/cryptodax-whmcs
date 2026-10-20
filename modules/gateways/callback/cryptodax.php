<?php
/**
 * CryptoDAX WHMCS Webhook Endpoint
 *
 * This endpoint is server-to-server only.
 * Browser return/callback state is never used to mark an invoice as paid.
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

header('Content-Type: application/json; charset=utf-8');

function cryptodax_webhook_response($statusCode, array $body)
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($gatewayParams['type'])) {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'module_not_active']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cryptodax_webhook_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'empty_body']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

/*
|--------------------------------------------------------------------------
| 1. Verify webhook authenticity
|--------------------------------------------------------------------------
|
| CryptoDAX headers:
|   X-Webhook-Signature: <hex HMAC-SHA256 of raw JSON body>
|   X-Webhook-Event: invoice.paid
|
| HMAC:
|   HMAC-SHA256(webhook_secret, raw JSON body)
*/

$webhookSecret = (string) ($gatewayParams['webhookSecret'] ?? '');
$signatureHeader = trim((string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));

if ($webhookSecret === '' || $signatureHeader === '') {
    logTransaction($gatewayParams['name'], ['event' => $payload['event'] ?? null], 'Missing Webhook Signature');
    cryptodax_webhook_response(401, ['ok' => false, 'error' => 'invalid_signature']);
}

$expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

if (!hash_equals($expectedSignature, $signatureHeader)) {
    logTransaction($gatewayParams['name'], ['event' => $payload['event'] ?? null], 'Invalid Webhook Signature');
    cryptodax_webhook_response(401, ['ok' => false, 'error' => 'invalid_signature']);
}

/*
|--------------------------------------------------------------------------
| 2. Parse event
|--------------------------------------------------------------------------
*/

$event = (string) ($payload['event'] ?? ($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? ''));

/*
 * Only a fully paid (or overpaid) invoice should settle the WHMCS invoice.
 * processing / underpaid / payment.updated are acknowledged and ignored.
 */
if (!in_array($event, ['invoice.paid', 'invoice.overpaid'], true)) {
    logTransaction(
        $gatewayParams['name'],
        ['event' => $event, 'payload' => $payload],
        'Webhook Acknowledged - No Invoice Change'
    );

    cryptodax_webhook_response(200, ['ok' => true, 'processed' => false]);
}

$invoiceId = $payload['order_id'] ?? null;
$cryptodaxInvoiceId = (string) ($payload['invoice_id'] ?? '');
$paymentAmount = $payload['amount'] ?? null;
$currency = strtoupper((string) ($payload['currency'] ?? ''));

$transactions = [];
if (isset($payload['payment']['transactions']) && is_array($payload['payment']['transactions'])) {
    $transactions = $payload['payment']['transactions'];
}

$transactionId = null;
if (!empty($transactions[0]['tx_hash'])) {
    $transactionId = (string) $transactions[0]['tx_hash'];
} elseif ($cryptodaxInvoiceId !== '') {
    $transactionId = 'cryptodax-' . $cryptodaxInvoiceId;
}

if ($invoiceId === null || $invoiceId === '') {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'missing_invoice_id']);
}

if ($transactionId === null || $transactionId === '') {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'missing_transaction_id']);
}

if (!is_numeric($paymentAmount) || (float) $paymentAmount <= 0) {
    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'invalid_amount']);
}

/*
|--------------------------------------------------------------------------
| 3. Validate WHMCS invoice ID
|--------------------------------------------------------------------------
*/

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

/*
|--------------------------------------------------------------------------
| 4. Idempotency / duplicate webhook handling
|--------------------------------------------------------------------------
*/

$existingTransaction = Capsule::table('tblaccounts')
    ->where('transid', (string) $transactionId)
    ->first();

if ($existingTransaction) {
    cryptodax_webhook_response(200, [
        'ok' => true,
        'processed' => false,
        'duplicate' => true,
    ]);
}

checkCbTransID((string) $transactionId);

/*
|--------------------------------------------------------------------------
| 5. Validate amount and currency
|--------------------------------------------------------------------------
*/

if ($currency !== 'USD') {
    logTransaction(
        $gatewayParams['name'],
        ['invoice_id' => $invoiceId, 'transaction_id' => $transactionId, 'currency' => $currency],
        'Currency Mismatch'
    );

    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'currency_mismatch']);
}

$invoice = Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->first();

if (!$invoice) {
    cryptodax_webhook_response(404, ['ok' => false, 'error' => 'invoice_not_found']);
}

$expectedAmount = number_format((float) $invoice->total, 2, '.', '');
$receivedAmount = number_format((float) $paymentAmount, 2, '.', '');

if ($expectedAmount !== $receivedAmount) {
    logTransaction(
        $gatewayParams['name'],
        [
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
            'expected_amount' => $expectedAmount,
            'received_amount' => $receivedAmount,
        ],
        'Amount Mismatch'
    );

    cryptodax_webhook_response(400, ['ok' => false, 'error' => 'amount_mismatch']);
}

/*
|--------------------------------------------------------------------------
| 6. Log verified transaction
|--------------------------------------------------------------------------
*/

logTransaction(
    $gatewayParams['name'],
    [
        'event' => $event,
        'invoice_id' => $invoiceId,
        'cryptodax_invoice_id' => $cryptodaxInvoiceId,
        'transaction_id' => (string) $transactionId,
        'amount' => $receivedAmount,
        'currency' => $currency,
    ],
    'Payment Completed'
);

/*
|--------------------------------------------------------------------------
| 7. Apply payment to WHMCS invoice
|--------------------------------------------------------------------------
*/

addInvoicePayment(
    $invoiceId,
    (string) $transactionId,
    (float) $receivedAmount,
    0.00,
    $gatewayModuleName
);

cryptodax_webhook_response(200, [
    'ok' => true,
    'processed' => true,
]);
