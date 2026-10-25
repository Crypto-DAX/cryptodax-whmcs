CryptoDAX payment gateway module for WHMCS 7 and 8

Installation:
1) Copy the modules folder into the WHMCS root (the old bitpayir files are not required).
2) In the WHMCS admin area, go to Setup > Payments > Payment Gateways and enable CryptoDAX.
3) Enter the API Key and API URL.
4) If customers are not billed in USD, set Convert To For Processing to USD
   (the API accepts USD only).

Webhook URL (sent automatically as callback_url):
https://YOUR-WHMCS/modules/gateways/callback/cryptodax.php

Security: webhook data is not trusted on its own. Each payment is verified again
through the API, the same way verify.py does
(/v1/invoices/{id} and /v1/merchant/payment/{id}). The tx_hash must belong to a
transaction on that same invoice, and the status must be paid or overpaid. A
duplicate tx_hash is not recorded again.
