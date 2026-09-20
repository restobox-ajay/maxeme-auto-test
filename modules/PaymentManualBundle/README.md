# PaymentManualBundle

**Type:** Payment · **Name:** Manual Payment Methods · **Edit route:** `admin_bundle_payment_manual_index`

## What it does

Lets an admin create an arbitrary number of manually-verified payment methods (e.g. "Bank Transfer", "E-Transfer") — one tagged `PaymentMethodProviderInterface` service (`ManualPaymentMethodProvider`) backs however many methods exist as `PaymentMethod` rows with `source = 'PaymentManualBundle'`. Each row is wrapped as a `ManualPaymentMethod` instance at resolve time (fixed priority 20, `validate()` always succeeds — verification of a manual payment happens outside the app). See [docs/bundles/payment.md](../../docs/bundles/payment.md) for the singular-vs-provider distinction.

## How it's configured

Full admin CRUD at `admin_bundle_payment_manual_index` (`/admin/bundles/payments/manual`) — create, rename, and delete methods, each getting its own generated slug (`manual-<uniqid>`).

## External dependencies

None.

## See also

[docs/bundles/payment.md](../../docs/bundles/payment.md) — uses this bundle as the `PaymentMethodProviderInterface` worked example.
