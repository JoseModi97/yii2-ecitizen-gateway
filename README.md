# yii2-ecitizen-gateway

The easy way to accept eCitizen payments from a Yii2 app. No models, no
interfaces to implement for the basic flow — create one object with your
eCitizen credentials, and use two methods.

## 1. Install

```bash
composer require josemodi97/yii2-ecitizen-gateway
```

That's it — it's a normal Packagist package
([josemodi97/yii2-ecitizen-gateway](https://packagist.org/packages/josemodi97/yii2-ecitizen-gateway)),
so this is the same one-line install as any other Composer dependency. No
repository configuration, no path setup.

<details>
<summary>Installing from source instead (contributing, or no internet access to Packagist)</summary>

Clone or copy this repo, then point your app's `composer.json` at it as a
local path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../yii2-ecitizen-gateway" }
    ],
    "require": {
        "josemodi97/yii2-ecitizen-gateway": "*"
    }
}
```

```bash
composer update josemodi97/yii2-ecitizen-gateway
```
</details>

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
    'callbackUrl' => 'https://example.com/payment/success',
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

### URLs explained (yes, there's a callback URL)

eCitizen actually uses two different URLs, and it's easy to mix them up:

| Field | eCitizen calls this | When it fires | Can you trust it on its own? |
|---|---|---|---|
| `callbackUrl` | `callBackURLOnSuccess` | The payer's **browser** is redirected here after they finish on eCitizen's checkout page. | No — it's just a page view. Anyone could open that URL manually. Use it only to show a "thank you, we're confirming your payment" page. |
| `notifyUrl` | `notificationURL` | eCitizen's **server** POSTs the signed payment result here, independently of the browser. | Yes, once you've run it through `verify()` — that's what actually checks the signature. |

In short: `callbackUrl` is for the user's experience, `notifyUrl` (checked
with `verify()`) is for your actual confirmation logic. `successUrl` is
still accepted as an alias of `callbackUrl` if you're already using it.

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
        // order paid, send a receipt email, or save it to your own table
        // (see "Storing payment records" below for a worked example).
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

On purpose, to stay simple: `payButton()`/`checkout()` only build a signed
payload, and `verify()` only checks one — neither one saves anything on
its own. Whether and how you persist a payment (a database row, a log
line, nothing at all) is entirely up to your own code; see the next
section if you do want a table for it.

## Storing payment records in your own database (optional)

You don't need a database to accept a payment with this library — but
most real apps will want a row per payment, so here's a scenario and a
generic design for it.

**Scenario:** a student pays school fees. You want to (1) create a row the
moment you show the pay button, with status `Pending`, and (2) flip it to
`Settled` once `verify()` confirms it, so you have a durable record even
if the browser tab closes before the callback fires.

### 1. Design the table

These columns cover the fields eCitizen actually gives you — adjust names
to fit your own conventions:

| Column | Type (see engine notes below) | Notes |
|---|---|---|
| `id` | auto-incrementing primary key | |
| `reference` | string, **unique** | the `reference` you pass to `checkout()`/`payButton()` |
| `amount` | decimal(12,2) | expected amount |
| `currency` | string(3) | e.g. `KES` |
| `client_name`, `client_id_number`, `client_msisdn`, `client_email` | string | payer details |
| `status` | string | `Pending` / `Settled` / `Failed` |
| `raw_response` | text | JSON-encoded `verify()['raw']`, for audits/debugging |
| `created_at`, `updated_at` | timestamp | |

### 2. Create the table

If your app uses **Yii2 migrations** (recommended — the migration DSL
below is portable across every database Yii2 supports, so you don't have
to hand-write per-engine SQL):

```php
// migrations/m260101_000000_create_ecitizen_payments_table.php
class m260101_000000_create_ecitizen_payments_table extends \yii\db\Migration
{
    public function safeUp()
    {
        $this->createTable('ecitizen_payments', [
            'id' => $this->primaryKey(),
            'reference' => $this->string(64)->notNull()->unique(),
            'amount' => $this->decimal(12, 2)->notNull(),
            'currency' => $this->string(3)->notNull()->defaultValue('KES'),
            'client_name' => $this->string(),
            'client_id_number' => $this->string(),
            'client_msisdn' => $this->string(),
            'client_email' => $this->string(),
            'status' => $this->string(20)->notNull()->defaultValue('Pending'),
            'raw_response' => $this->text(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('ecitizen_payments');
    }
}
```

Run it with:

```bash
php yii migrate/create create_ecitizen_payments_table   # generates the file above
php yii migrate                                          # applies it
```

If you're not using migrations, here's the same table as raw SQL for the
most common engines:

<details>
<summary>MySQL / MariaDB</summary>

```sql
CREATE TABLE ecitizen_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(64) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    client_name VARCHAR(255),
    client_id_number VARCHAR(64),
    client_msisdn VARCHAR(20),
    client_email VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    raw_response TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```
</details>

<details>
<summary>PostgreSQL</summary>

```sql
CREATE TABLE ecitizen_payments (
    id SERIAL PRIMARY KEY,
    reference VARCHAR(64) NOT NULL UNIQUE,
    amount NUMERIC(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    client_name VARCHAR(255),
    client_id_number VARCHAR(64),
    client_msisdn VARCHAR(20),
    client_email VARCHAR(255),
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    raw_response TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```
</details>

<details>
<summary>SQL Server</summary>

```sql
CREATE TABLE ecitizen_payments (
    id INT IDENTITY(1,1) PRIMARY KEY,
    reference NVARCHAR(64) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    client_name NVARCHAR(255),
    client_id_number NVARCHAR(64),
    client_msisdn NVARCHAR(20),
    client_email NVARCHAR(255),
    status NVARCHAR(20) NOT NULL DEFAULT 'Pending',
    raw_response NVARCHAR(MAX),
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
```
</details>

<details>
<summary>SQLite</summary>

```sql
CREATE TABLE ecitizen_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference TEXT NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    currency TEXT NOT NULL DEFAULT 'KES',
    client_name TEXT,
    client_id_number TEXT,
    client_msisdn TEXT,
    client_email TEXT,
    status TEXT NOT NULL DEFAULT 'Pending',
    raw_response TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```
</details>

<details>
<summary>Oracle</summary>

```sql
CREATE TABLE ecitizen_payments (
    id NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference VARCHAR2(64) NOT NULL UNIQUE,
    amount NUMBER(12,2) NOT NULL,
    currency CHAR(3) DEFAULT 'KES' NOT NULL,
    client_name VARCHAR2(255),
    client_id_number VARCHAR2(64),
    client_msisdn VARCHAR2(20),
    client_email VARCHAR2(255),
    status VARCHAR2(20) DEFAULT 'Pending' NOT NULL,
    raw_response CLOB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);
```
</details>

### 3. Insert a row when you create the payment

```php
Yii::$app->db->createCommand()->insert('ecitizen_payments', [
    'reference' => $reference,       // same value passed to payButton()/checkout()
    'amount' => $amount,
    'currency' => 'KES',
    'client_name' => $name,
    'client_id_number' => $idNumber,
    'client_msisdn' => $phone,
    'client_email' => $email,
    'status' => 'Pending',
])->execute();
```

### 4. Update it once `verify()` confirms the payment

```php
$result = $ecitizen->verify(Yii::$app->request->bodyParams);

if ($result['success']) {
    Yii::$app->db->createCommand()->update('ecitizen_payments', [
        'status' => 'Settled',
        'raw_response' => json_encode($result['raw']),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['reference' => $result['reference']])->execute();
}
```

`Yii::$app->db` is the Yii2 database component your app already has
configured (MySQL, PostgreSQL, SQL Server, SQLite, or Oracle) — the query
builder calls above work unchanged regardless of which one you're on.

If you'd rather have an ActiveRecord model and have the library do this
update for you automatically, see "Advanced" below.

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
  you want automatic database updates against your own ActiveRecord model
  (see "Advanced" above). For a plain SQL table with no ActiveRecord, see
  "Storing payment records in your own database" above instead.
