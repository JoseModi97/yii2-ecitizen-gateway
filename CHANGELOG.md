# Changelog

All notable changes to this project are documented in this file.

## [1.2.1] - 2026-08-17

### Changed

- README: replaced `<details><summary>` collapsible HTML blocks (used for
  the "installing from source" note and the per-database raw SQL
  examples) with plain `####` headings. Packagist and GitHub both render
  the HTML version fine, but it was suspected of being the reason the
  yiiframework.com extension listing page's README parser was rendering
  the page body as blank. No content changed, only the markup.

## [1.2.0] - 2026-08-17

### Changed

- **`yiisoft/yii2` is no longer a required dependency.** `EcitizenGateway`
  no longer extends `yii\base\Component`, and `EcitizenClient::payButton()`
  no longer uses `yii\helpers\Html` — both are now plain PHP. This means:
  - `composer require josemodi97/yii2-ecitizen-gateway` no longer pulls in
    the Yii2 framework or its legacy `bower-asset/*` dependency chain.
  - The library installs cleanly into Yii2 apps, Yii3 apps, or any other
    PHP project — previously, installing it into a project that wasn't a
    Yii2 app configured with the `asset-packagist.org` repository (e.g. a
    Yii3 app) failed with an unresolvable `bower-asset/jquery` requirement,
    since that requirement came from `yiisoft/yii2` itself, not from
    anything this library actually uses.
  - `yiisoft/yii2` moved to `suggest`, since it's still needed for the
    optional Yii2-specific pieces: `EcitizenPaymentTrait`,
    `EcitizenInvoiceInterface`, `EcitizenGateway::absoluteUrl()`, and
    registering `EcitizenGateway` as a Yii2 application component.
  - `EcitizenGateway`'s constructor now throws `InvalidArgumentException`
    instead of `yii\base\InvalidConfigException` for a missing setting.
- This is not a breaking change for existing Yii2 users: `EcitizenClient`'s
  public API (`payButton()`, `checkout()`, `verify()`, `isPaid()`) and
  output are unchanged.

## [1.1.1] - 2026-08-17

### Fixed

- README install instructions now show a normal
  `composer require josemodi97/yii2-ecitizen-gateway` (the package is
  published on Packagist) instead of requiring a local `path` repository
  entry — that's still documented, but only as a fallback for installing
  from source. Also fixed the leftover `odhis/...` package name in that
  example, which never matched the actual Packagist name.

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
