<?php
/**
 * CryptoDAX shared helpers (same logic as request.py / verify.py).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function cryptodax_api_url($gateway)
{
    return rtrim(trim($gateway['apiUrl']), '/');
}

/**
 * Low-level HTTP call. Returns array(httpCode, decodedJson|null, rawBody, curlError).
 */
function cryptodax_http($gateway, $method, $path, $body = null)
{
    $ch = curl_init(cryptodax_api_url($gateway) . $path);
    $headers = array(
        'Accept: application/json',
        'Authorization: Bearer ' . trim($gateway['apiKey']),
    );
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    return array($code, is_array($json) ? $json : null, $raw, $err);
}

/**
 * request.py -> POST /v1/invoices
 */
function cryptodax_create_invoice($gateway, $amountUsd, $orderId, $description, $callbackUrl)
{
    $data = array(
        'amount'         => number_format((float) $amountUsd, 2, '.', ''),
        'currency'       => 'USD',
        'order_id'       => (string) $orderId,
        'description'    => $description,
        'callback_url'   => $callbackUrl,
        'expire_minutes' => max(1, (int) $gateway['expireMinutes']),
    );
    return cryptodax_http($gateway, 'POST', '/v1/invoices', $data);
}

/**
 * verify.py -> verify(trans_id, id_get)
 * Returns array('status' => ...) and, only for a paid/overpaid invoice whose
 * payments contain $transId, also amount, tx_hash, invoice_id (= order_id).
 */
function cryptodax_verify($gateway, $transId, $idGet)
{
    $idGet = rawurlencode($idGet);

    list($c1, $invoice, $raw1, $e1) = cryptodax_http($gateway, 'GET', '/v1/invoices/' . $idGet);
    list($c2, $checkout, $raw2, $e2) = cryptodax_http($gateway, 'GET', '/v1/merchant/payment/' . $idGet);

    if ($c1 < 200 || $c1 >= 300 || !$invoice || $c2 < 200 || $c2 >= 300 || !$checkout) {
        return array(
            'status' => 'error',
            'error'  => "invoice HTTP $c1 $e1 / payment HTTP $c2 $e2",
            'raw'    => array('invoice' => $raw1, 'payment' => $raw2),
        );
    }

    $txHashes = array();
    $payments = isset($checkout['payments']) && is_array($checkout['payments']) ? $checkout['payments'] : array();
    foreach ($payments as $payment) {
        $txs = isset($payment['transactions']) && is_array($payment['transactions']) ? $payment['transactions'] : array();
        foreach ($txs as $tx) {
            if (!empty($tx['tx_hash'])) {
                $txHashes[] = (string) $tx['tx_hash'];
            }
        }
    }

    $status  = isset($invoice['status']) ? $invoice['status'] : null;
    $isPaid  = in_array($status, array('paid', 'overpaid'), true);
    $matched = in_array((string) $transId, $txHashes, true);

    if ($isPaid && !$matched) {
        return array('status' => 'unverified');
    }

    $result = array('status' => $status);
    if ($isPaid && $matched) {
        $result['amount']     = isset($invoice['amount']) ? $invoice['amount'] : null;
        $result['tx_hash']    = (string) $transId;
        $result['invoice_id'] = isset($invoice['order_id']) ? $invoice['order_id'] : null;
    }
    return $result;
}
