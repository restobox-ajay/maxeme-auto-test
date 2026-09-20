# PaymentStripeBundle

**Type:** Payment · **Name:** Stripe · **Edit route:** `admin_bundle_payment_stripe`

## What it does

Offers a single fixed payment method — "Credit Card (Stripe)" (slug `stripe`, priority 30) — implementing `PaymentMethodInterface` directly. This is the one payment bundle with a real external dependency: `renderCheckoutOption()` embeds Stripe Elements card fields and, at order time, `validate()` retrieves the created `PaymentIntent` from Stripe via `StripeClient` and requires `status === 'succeeded'`; if a `Company` is present in context it also checks the intent's `metadata['company_id']` matches, preventing a completed payment from being replayed onto a different company's order.

If Stripe isn't configured (no publishable/secret key set), `renderCheckoutOption()` shows the option as disabled rather than hiding it, and `validate()` fails with "Card payment is not available right now."

## How it's configured

Admin screen at `admin_bundle_payment_stripe` (`/admin/bundles/payments/stripe`) — **this is the one bundle in the repo with a real external dependency worth calling out explicitly.** It stores separate test-mode and live-mode publishable/secret key pairs in the DB (via `AppSettings`, not `.env`), plus which mode is active. `PaymentStripeBundle\Service\StripeConfigProvider` is the single source of truth for this config — both this bundle's checkout payment method and the customer-facing post-order invoice-payment flow in `App\Controller\Customer\OrderController` read Stripe credentials through it, so there's one place to look for "what Stripe keys is this app actually using right now."

In test mode, the checkout UI shows a reminder to use Stripe's test card `4242 4242 4242 4242` (any future expiry, any 3-digit CVC).

## External dependencies

**Stripe** (`stripe/stripe-php`) — requires a real Stripe account and API keys (test and/or live) configured via the admin screen above before this method is usable. Without keys configured, the option shows but is disabled.
