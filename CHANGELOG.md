# Changelog

All notable changes to this project are documented in this file.

## [1.1.0] - 2026-08-17

### Changed

- Dropped the "no database required" framing from the docs/description —
  the library still doesn't touch a database itself, but that's no longer
  presented as a selling point on its own.
- `payButton()`'s form is now built by hand instead of via `Html::beginForm()`,
  which no longer requires a bootstrapped `Yii::$app` just to render a
  static form (it was also injecting an irrelevant app CSRF token into a
  form that posts to eCitizen's external domain).

### Added

- `callbackUrl` as the primary friendly alias for `callBackURLOnSuccess`
  (`successUrl` still works, kept for backwards compatibility). README now
  has a "URLs explained" section clarifying `callbackUrl` (browser
  redirect, not verifiable on its own) vs `notifyUrl` (server-to-server,
  what `verify()` actually checks).
- README "Storing payment records in your own database" section: a
  scenario, a generic column design, a portable Yii2 migration, raw SQL
  for MySQL/MariaDB, PostgreSQL, SQL Server, SQLite, and Oracle, and
  example insert/update code using `Yii::$app->db`.

## [1.0.0] - 2026-08-17

### Added

- `EcitizenClient` — beginner-friendly facade with plain-English fields
  (`amount`, `reference`, `description`, `name`, `idNumber`, `phone`,
  `email`), `payButton()`, `checkout()`, `verify()`, `isPaid()`, and
  friendly `InvalidArgumentException` messages for missing fields.
- `EcitizenGateway` — Yii2 component that builds/signs checkout payloads
  and verifies inbound callback/notification signatures.
- `PhoneHelper` — Kenyan MSISDN normalization/validation for `sendSTK`.
- `EcitizenInvoiceInterface` and `EcitizenPaymentTrait` (optional,
  advanced) — for apps that want the library to update their own invoice
  ActiveRecord automatically.
