# UltraDev InfinitePay for OpenMage
 
InfinitePay **Integrated Checkout** (Pix and credit card) for OpenMage 20.x / PHP 8.1+.
 
After the order is placed, the customer is redirected to InfinitePay's secure payment page. Payment confirmation is handled server-side through three independent paths (customer return, webhook and cron), all funneled through a single processor that always verifies the payment against the InfinitePay API.
 
## Features
 
- Redirects to the InfinitePay hosted payment page (card data never touches your store)
- Pix with optional percentage discount, applied automatically on the payment page
- Credit card in up to 12 installments, with optional merchant-assumed installment fees
- Payment confirmation by customer return, webhook and cron (every 15 minutes)
- Never trusts URL or webhook parameters: every confirmation goes through `payment_check`
- Per-order MySQL lock (`GET_LOCK`) to prevent webhook, return and cron from processing the same order at once
- Amount sanity check (holds the order for manual review if the paid amount is below the expected value)
- Automatic offline invoice creation after confirmed payment
- Automatic cancellation of unpaid orders after a configurable number of hours
- Payment details (method, installments, paid amount, NSU, invoice slug, receipt) shown in the admin order view
- Checkout form styled by an external stylesheet loaded through a layout handle (no inline CSS in the template)
## Requirements
 
- OpenMage 20.x
- PHP 8.1 or higher with the cURL extension
- HTTPS on the storefront (redirect and webhook URLs are generated with `_secure`)
- Working Magento cron (for pending order re-checks and expiration)
- PHP-FPM recommended (see [Points of attention](#points-of-attention))
- An InfinitePay account (InfiniteTag and, optionally, an API Key)
## Module structure
 
```
ultradev-infinitepay/
├── composer.json
├── modman
├── app/
│   ├── etc/modules/UltraDev_InfinitePay.xml
│   ├── code/community/UltraDev/InfinitePay/
│   │   ├── Block/
│   │   │   ├── Form/Checkout.php
│   │   │   └── Info/Checkout.php
│   │   ├── Helper/Data.php
│   │   ├── Model/
│   │   │   ├── Api.php
│   │   │   ├── Observer.php
│   │   │   ├── Processor.php
│   │   │   └── Method/Checkout.php
│   │   ├── controllers/
│   │   │   ├── NotificationController.php
│   │   │   └── PaymentController.php
│   │   ├── etc/
│   │   │   ├── config.xml
│   │   │   └── system.xml
│   │   └── sql/ultradev_infinitepay_setup/install-1.0.0.php
│   └── design/frontend/base/default/
│       ├── layout/ultradev/infinitepay.xml
│       └── template/ultradev/infinitepay/form/checkout.phtml
└── skin/frontend/base/default/css/ultradev/infinitepay/checkout.css
```
 
## Installation
 
### Composer
 
Add the package repository (VCS or path) to the `composer.json` in your store root, then run:
 
```bash
composer require ultradev/infinitepay-openmage
rm -rf var/cache/*
php n98-magerun.phar cache:flush
```
 
### modman
 
The included `modman` file maps the module code, the module declaration, the layout, the template and the skin folder:
 
```bash
modman clone <repository-url>
php n98-magerun.phar cache:flush
```
 
## Configuration
 
Go to **System → Configuration → Payment Methods**.
 
### UltraDev InfinitePay – General Settings
 
| Field | Description |
| --- | --- |
| InfiniteTag (handle) | Your InfinitePay username, without the `$` symbol. Used in payment verification (`payment_check`). |
| API Key | Generated in **Checkout → Settings → API Keys** in the InfinitePay dashboard. Sent in the `X-Api-Key` header, server-side only. Stored encrypted. |
| Debug log | Writes payloads and responses to `var/log/ultradev_infinitepay.log`. Enable it during testing. |
 
### UltraDev InfinitePay – Pix and Card (Integrated Checkout)
 
| Field | Description |
| --- | --- |
| Enabled | Enables the payment method. |
| Title | Label shown at checkout. |
| Status after confirmed payment | Order status applied once payment is confirmed (default `processing`). |
| Accept Pix on the payment page | Card is always available. If set to "No", the link is created with card only. |
| Pix discount (%) | Greater than 0 and less than 100. Sent as `options.discounts.pix` on every link. Empty = no discount. |
| Assume installment fees up to (x) | 0 to 12. Empty = not sent (account default). |
| Single payment link | Prevents reusing the same link after it has been paid. |
| Send link expiration (`valid_until`) | Uses the expiration period below. Test the date format in the playground before enabling. |
| Pre-fill address at checkout | Assumes street lines 1 to 4 are street, number, complement and neighborhood. |
| Cancel unpaid order after (hours) | Run by cron every 15 minutes. `0` = never cancel automatically. |
| Sort order | Position among payment methods. |
 
## How it works
 
1. The customer selects **InfinitePay** and places the order. The order is created as `pending_payment`.
2. `getOrderPlaceRedirectUrl()` sends the customer to `ultradev-infinitepay/payment/redirect`, which creates the checkout link (once per order) and redirects to InfinitePay.
3. After paying, the customer returns to `ultradev-infinitepay/payment/return`, which triggers verification and then redirects to `checkout/onepage/success`.
4. InfinitePay also calls `ultradev-infinitepay/notification/webhook`. The module answers `200` immediately and verifies the payment afterwards.
5. A cron job re-checks pending orders every 15 minutes and cancels expired ones.
6. All three paths call `UltraDev_InfinitePay_Model_Processor::process()`, which calls `payment_check`, validates the amount, registers the payment and creates the invoice.
## Layout and styling
 
The checkout form stylesheet lives in its own file and is loaded through a reusable layout handle:
 
- `skin/frontend/base/default/css/ultradev/infinitepay/checkout.css`
- `app/design/frontend/base/default/layout/ultradev/infinitepay.xml`
The layout file defines the `ultradev_infinitepay_assets` handle, which adds the stylesheet to the `head`. It is applied to `checkout_onepage_index` by default. To load it on another checkout page (for example, a custom one-step checkout), add that page's handle to the layout file:
 
```xml
<YOUR_CHECKOUT_HANDLE>
    <update handle="ultradev_infinitepay_assets"/>
</YOUR_CHECKOUT_HANDLE>
```
 
The `style="display:none;"` on the payment form `<ul>` in `checkout.phtml` is intentional. The core `payment.js` toggles that element through its inline `style.display`, so moving the rule into a stylesheet would leave the form permanently hidden.
 
## Points of attention
 
### Advanced fields position
 
`discounts`, `payment_methods_enabled`, `assured_installments`, `single_payment` and `valid_until` are sent inside `options`, as indicated by InfinitePay's AI assistant. The documentation only provides the texts, not an example JSON. Run **Test API** in the Documentation tab playground and compare it with the payload in the log. If the position or format (the `pix` key, the `valid_until` date format) differs, the fix is limited to `_buildPayload()` and `_buildOptions()` in `Model/Api.php`.
 
### `payment_check` with API Key
 
`payment_check` is sent with `handle`, `order_nsu`, `transaction_nsu` and `slug`. That is why the InfiniteTag is in the admin even when using an API Key. Check the log to confirm the response contains `paid: true`.
 
### Custom checkouts (UltraDev_Checkout)
 
The module relies on the checkout honoring `getOrderPlaceRedirectUrl()` and on the return landing on `checkout/onepage/success`. If your one-step checkout handles either differently, the controller code needs to be adapted. In addition, the stylesheet only loads on pages whose layout handle is registered in `infinitepay.xml` (see [Layout and styling](#layout-and-styling)).
 
### Webhook response time
 
The webhook answers `200` first and only then verifies the payment, using `fastcgi_finish_request()` (PHP-FPM). Without FPM, the response is sent only after processing finishes, which may exceed the ~1 second InfinitePay expects. The cron job covers any missed notification.
 
### Pre-filled address (`send_address`)
 
Disabled by default because it assumes street lines 1 to 4 are street, number, complement and neighborhood. Enable it only after the first order works end to end.
 
### Checkout form
 
The form explains both payment options. The "5% off" badge appears only when Pix is enabled and the discount is greater than 0.
 
## Logs
 
`var/log/ultradev_infinitepay.log`
 
## Where payment data is stored
 
No custom tables are created. Payment data is stored in `sales_flat_order_payment.additional_information` (`ip_checkout_url`, `ip_slug`, `ip_transaction_nsu`, `ip_capture_method`, `ip_installments`, `ip_amount`, `ip_paid_amount`, `ip_receipt_url`).
 
## License
 
MIT
 
