<?php
/**
 * CryptoDAX webhook (callback_url).
 *
 * CryptoDAX POSTs JSON like:
 * {"event":"invoice.paid","invoice_id":"bd87...","order_id":"<whmcs id>-<rand>","amount":"123.00",
 *  "currency":"USD","status":"paid","payment":{"transactions":[{"tx_hash":"0x..."}], ...}}
 *
 * The webhook body is NOT trusted. Every payment is re-checked through the API
 * (cryptodax_verify = verify.py) before the WHMCS invoice is marked paid.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../cryptodax/lib.php';

$gatewayModuleName = 'cryptodax';
$GATEWAY = getGatewayVariables($gatewayModuleName);
if (!$GATEWAY['type']) {
    die('Module Not Activated');
}

function cryptodax_respond($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(array('result' => $message));
    exit;
}

// ---- read webhook payload (JSON body; GET/POST form as fallback) ----
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = array_merge($_GET, $_POST);
}

$idGet = '';
foreach (array('invoice_id', 'id_get', 'id') as $k) {
    if (!empty($body[$k]) && is_string($body[$k])) {
        $idGet = $body[$k];
        break;
    }
}

$txHashes = array();
if (!empty($body['trans_id'])) {
    $txHashes[] = (string) $body['trans_id'];
}
if (!empty($body['tx_hash'])) {
    $txHashes[] = (string) $body['tx_hash'];
}
if (isset($body['payment']['transactions']) && is_array($body['payment']['transactions'])) {
    foreach ($body['payment']['transactions'] as $tx) {
        if (!empty($tx['tx_hash'])) {
            $txHashes[] = (string) $tx['tx_hash'];
        }
    }
}
$txHashes = array_values(array_unique($txHashes));

$event  = isset($body['event']) ? $body['event'] : '';
$status = isset($body['status']) ? $body['status'] : '';

if ($idGet === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $idGet)) {
    logTransaction($GATEWAY['name'], array('body' => $raw ?: $body), 'Invalid Webhook');
    cryptodax_respond(400, 'invalid payload');
}

// Not a payment event -> acknowledge and stop.
if (!in_array($status, array('paid', 'overpaid'), true) || empty($txHashes)) {
    logTransaction($GATEWAY['name'], array('event' => $event, 'status' => $status, 'invoice_id' => $idGet), 'Ignored: ' . ($status ?: 'no status'));
    cryptodax_respond(200, 'ignored');
}

// ---- verify through the API (verify.py logic) ----
$verified = null;
foreach ($txHashes as $txHash) {
    $result = cryptodax_verify($GATEWAY, $txHash, $idGet);
    if ($result['status'] === 'error') {
        logTransaction($GATEWAY['name'], array('invoice_id' => $idGet, 'verify' => $result), 'Verify Error');
        cryptodax_respond(502, 'verify failed, retry later');
    }
    if (isset($result['tx_hash'])) {
        $verified = $result;
        break;
    }
}

if (!$verified) {
    logTransaction($GATEWAY['name'], array('webhook' => $body, 'tx_hashes' => $txHashes), 'Unverified');
    cryptodax_respond(200, 'unverified');
}

// order_id confirmed by the API = "<WHMCS invoice id>-<random>"
$orderId = (string) $verified['invoice_id'];
if (!preg_match('/^(\d+)-[A-Za-z0-9]+$/', $orderId, $m)) {
    logTransaction($GATEWAY['name'], array('webhook' => $body, 'verify' => $verified), 'Unknown order_id');
    cryptodax_respond(200, 'unknown order');
}
$invoiceId = checkCbInvoiceID((int) $m[1], $GATEWAY['name']);   // dies if invoice does not exist
$transId   = $verified['tx_hash'];

checkCbTransID($transId);   // dies if this tx_hash was already recorded

// API amount is USD; convert back to the client's currency if "Convert To For Processing" is used.
$paymentAmount = (float) $verified['amount'];
if (!empty($GATEWAY['convertto'])) {
    $userId = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('userid');
    $clientCurrency = getCurrency($userId);
    if ((int) $clientCurrency['id'] !== (int) $GATEWAY['convertto']) {
        $paymentAmount = convertCurrency($paymentAmount, $GATEWAY['convertto'], $clientCurrency['id']);
    }
}

addInvoicePayment($invoiceId, $transId, $paymentAmount, 0, $gatewayModuleName);
logTransaction($GATEWAY['name'], array('webhook' => $body, 'verify' => $verified, 'applied_amount' => $paymentAmount), 'Successful');

cryptodax_respond(200, 'ok');
