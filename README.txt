CryptoDAX WHMCS Gateway - Webhook First v2
================================================

This module matches the live CryptoDAX merchant API in this repository.
Do not invent extra invoice/webhook fields; the gateway talks to the
real endpoints below.

DESIGN
------
This module intentionally does NOT trust the customer's browser return.

The only path that can mark a WHMCS invoice as paid is:

    CryptoDAX server
      -> signed invoice.paid / invoice.overpaid webhook
      -> modules/gateways/callback/cryptodax.php
      -> HMAC signature validation
      -> WHMCS invoice/transid/amount validation
      -> addInvoicePayment()

If the customer's browser is closed, disconnected, or never returns to WHMCS,
the invoice can still be marked Paid by the webhook.

INSTALL
-------
Extract into the WHMCS root. Final paths:

    modules/gateways/cryptodax.php
    modules/gateways/callback/cryptodax.php

Activate CryptoDAX in WHMCS Payment Gateways and configure:
- API URL            default https://pay.cryptodax.io/api
- Checkout Base URL  default https://pay.cryptodax.io
- API Key            merchant API key
- Webhook Secret     same as CryptoDAX WEBHOOK_SECRET

Also set the merchant webhook URL in the CryptoDAX dashboard
(PUT /api/v1/merchant/settings, JWT session) to:

    https://whmcs.example.com/modules/gateways/callback/cryptodax.php

Invoice create does not accept a per-invoice webhook_url. CryptoDAX
delivers all events to merchants.webhook_url.

CREATE INVOICE
--------------
POST {API_URL}/v1/merchant/invoices

Full example: https://pay.cryptodax.io/api/v1/merchant/invoices

Headers:
    Authorization: Bearer <merchant-api-key>
    Content-Type: application/json

Request:
{
  "amount": "10.00",
  "currency": "USD",
  "order_id": "123",
  "description": "Invoice #123"
}

Notes:
- order_id must match ^[A-Za-z0-9]+$ (WHMCS invoice IDs are numeric, so they are valid).
- Repeating the same order_id + amount returns the existing invoice (idempotent refresh).
- A different amount for the same order_id returns HTTP 409.
- callback_url, return_url, metadata, webhook_url, and Idempotency-Key are not part of this API.

Response (201 or 200):
{
  "invoice_id": "a1b2c3d4e5f678901234567890123456",
  "amount": "10.00",
  "currency": "USD",
  "order_id": "123",
  "callback_url": null,
  "description": null,
  "status": "pending",
  "expires_at": 1720000000,
  "paid_at": null
}

The Pay button opens:

    {Checkout Base URL}/invoice/{invoice_id}

The API does not return checkout_url.

WEBHOOK
-------
POST https://whmcs.example.com/modules/gateways/callback/cryptodax.php

Headers:
    Content-Type: application/json
    User-Agent: Cryptodax-Webhook/1.0
    X-Webhook-Event: invoice.paid
    X-Webhook-Signature: <hex HMAC-SHA256>

Signature input: RAW request body (not timestamp-prefixed).
Signature: HMAC-SHA256(WEBHOOK_SECRET, raw_body) as lowercase hex.

CryptoDAX canonicalizes JSON before signing (sorted keys, compact separators).
The callback hashes whatever body it received, which is that canonical JSON.

Example body:
{
  "event": "invoice.paid",
  "invoice_id": "a1b2c3d4e5f678901234567890123456",
  "order_id": "123",
  "status": "paid",
  "amount": "10.00",
  "currency": "USD",
  "paid_at": "2026-09-21T12:00:00+00:00",
  "payment": {
    "status": "paid",
    "coin": "USDT",
    "network": "TRX",
    "amount": "10.5",
    "received_amount": "10.5",
    "address": "TXYZexampleaddress",
    "confirmations_required": 1,
    "paid_at": "2026-09-21T12:00:00+00:00",
    "transactions": [
      {
        "tx_hash": "0xabc123def456",
        "amount": "10.5",
        "confirmations": 1,
        "status": "confirmed"
      }
    ]
  }
}

WHMCS invoice id  = order_id
WHMCS amount      = amount (USD invoice total, not crypto)
WHMCS transid     = payment.transactions[0].tx_hash, or cryptodax-{invoice_id}

WEBHOOK EVENT POLICY
--------------------
invoice.paid        -> validate and apply payment
invoice.overpaid    -> validate and apply payment (full WHMCS total only)
invoice.processing  -> HTTP 200, no WHMCS invoice change
invoice.underpaid   -> HTTP 200, no WHMCS invoice change
payment.paid        -> HTTP 200, no WHMCS invoice change
payment.updated     -> HTTP 200, no WHMCS invoice change
anything else       -> HTTP 200, no WHMCS invoice change

SECURITY / RELIABILITY
----------------------
- HTTPS must be used in production.
- Browser return is UX only.
- Webhook signature is HMAC-SHA256 of the raw body.
- hash_equals() is used for constant-time comparison.
- Transaction ID is used for webhook/payment idempotency.
- WHMCS checkCbInvoiceID() and checkCbTransID() are retained.
- Amount and currency are checked before addInvoicePayment().
- Duplicate order_id + amount reuses the CryptoDAX invoice.
- Do not log API keys or webhook secrets.

LIMITATIONS
-----------
- USD WHMCS invoices only (CryptoDAX invoices are USD).
- Full payment only (underpayment webhooks are ignored).
- No refund implementation.
- Merchant webhook_url must be configured in CryptoDAX, not in the create-invoice call.
- Checkout path /invoice/{invoice_id} is configurable via Checkout Base URL if the frontend route differs.
