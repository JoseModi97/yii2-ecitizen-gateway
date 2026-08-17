# Changelog

All notable changes to this project are documented in this file.

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
