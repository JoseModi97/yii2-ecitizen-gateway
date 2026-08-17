# yii2-ecitizen-gateway

The easy way to accept eCitizen payments from a Yii2 app. No database
tables, no models, no interfaces to implement for the basic flow — create
one object with your eCitizen credentials, and use two methods.

## 1. Install

Copy the `src/` folder into your project (e.g. `common/ecitizen/`), or add
it as a local path package in your app's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../yii2-ecitizen-gateway" }
    ],
    "require": {
        "odhis/yii2-ecitizen-gateway": "*"
    }
}
```

```bash
composer update odhis/yii2-ecitizen-gateway
```

## 2. Get your eCitizen credentials

From your eCitizen merchant/payments account you'll need four values:
`apiClientID`, `apiKey`, `secret`, `serviceID`. Keep them out of your git
repo — put them in an env file or a local, gitignored config file.

## 3. Create the client

```php
use odhis\ecitizen\EcitizenClient;

$ecitizen = new EcitizenClient([
    'apiClientID' => 'YOUR_API_CLIENT_ID',
    'apiKey'      => 'YOUR_API_KEY',
    'secret'      => 'YOUR_SECRET',
    'serviceID'   => 'YOUR_SERVICE_ID',
]);
```

If you forget one of these, you get a plain-English error telling you
exactly which one is missing — nothing fails silently.

## 4. Show a "Pay with eCitizen" button

```php
echo $ecitizen->payButton([
    'amount'      => 500,
    'reference'   => 'INV-0001',          // any unique ID you make up for this payment
    'description' => 'School fees',
    'name'        => 'Jane Doe',
    'idNumber'    => '12345678',          // National ID or Passport number
    'phone'       => '0712345678',        // optional
    'email'       => 'jane@example.com',  // optional
    'successUrl'  => 'https://example.com/payment/success',
    'notifyUrl'   => 'https://example.com/payment/notify',
]);
```

That's it — this prints a real HTML form with a submit button. Click it,
and the payer is sent to eCitizen to pay. No view file, no iframe, no
JavaScript to write.

If a required field (`amount`, `reference`, `description`, `name`,
`idNumber`) is missing, you get a plain-English error telling you which
one and an example of the full call — instead of a payment silently
failing to build.

Only need the raw payload (e.g. to build your own custom form)? Use
`checkout()` instead of `payButton()` — same input, returns
`['url' => ..., 'payload' => [...]]`.

## 5. Confirm the payment

eCitizen will call your `notifyUrl` after payment. In that action, hand it
whatever was posted:

```php
public function actionNotify()
{
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

    $result = $ecitizen->verify(Yii::$app->request->bodyParams);

    if ($result['success']) {
        // Payment confirmed. Do whatever you want here — e.g. mark an
        // order paid, send a receipt email, log it. This library doesn't
        // assume you have a database table, so nothing is saved for you.
    }

    return ['status' => $result['success'] ? 'ok' : 'error'];
}
```

`verify()` checks the eCitizen signature and payment status for you and
returns a plain array:

```php
[
    'success'        => true,          // signature valid AND status confirms payment
    'signatureValid' => true,
    'status'         => 'Settled',
    'reference'      => 'INV-0001',    // same value you passed as 'reference'
    'amountPaid'     => 500.0,
    'raw'            => [...],         // everything eCitizen sent, just in case
]
```

Two important setup notes for the notify action:

- Disable CSRF validation for it — eCitizen posts to it without a browser
  session: `$this->enableCsrfValidation = false;` inside `beforeAction()`
  for that one action.
- Don't trust a request that doesn't pass `verify()`. `success` is only
  `true` when the signature checks out **and** the status is a real
  success status — a forged or incomplete request always comes back
  `false`.

## Troubleshooting

| Error you see | What it means | Fix |
|---|---|---|
| `Cannot set up eCitizen: missing 'apiKey' (...)` | A required credential wasn't passed to `new EcitizenClient([...])` | Add the missing key(s) to the array |
| `Cannot start this payment — missing: amount (...)` | `payButton()`/`checkout()` was called without a required field | Add the missing field(s), example call is in the message |
| `verify()` returns `success => false` but you were sure it was paid | Either the signature didn't match (wrong `secret`/`apiKey`, or the payload was tampered with) or eCitizen's status wasn't a success status | Check `signatureValid` and `status` in the result to see which one failed |

## What this library does NOT do

On purpose, to stay simple: it does not create or require any database
table, model, or migration, and it does not store or send anything for
you. `payButton()`/`checkout()` only build a signed payload; `verify()`
only checks one. Everything else — what happens after a payment is
confirmed — is entirely up to your own code.

## Advanced (optional)

If you already have your own invoice/payment ActiveRecord and want the
library to update it directly instead of handling `verify()`'s result
yourself, two optional pieces are included:

- `src/interfaces/EcitizenInvoiceInterface.php` — a small contract
  (`getBillRefNumber()`, `markStatus()`, etc.) your model can implement.
- `src/controllers/EcitizenPaymentTrait.php` — a controller trait that
  wires signature/status/amount/currency verification straight into a
  lookup + update of your model.

These are entirely optional — skip them if `EcitizenClient` above already
covers what you need, which is true for most projects.

## Files

- `src/EcitizenClient.php` — the beginner-friendly entry point described
  above: `payButton()`, `checkout()`, `verify()`, `isPaid()`.
- `src/EcitizenGateway.php` — the underlying Yii2 component that signs
  payloads and verifies hashes (used internally by `EcitizenClient`, or
  usable directly for advanced cases).
- `src/helpers/PhoneHelper.php` — Kenyan MSISDN normalization/validation.
- `src/interfaces/EcitizenInvoiceInterface.php`,
  `src/controllers/EcitizenPaymentTrait.php` — optional, only needed if
  you want automatic database updates (see "Advanced" above).
