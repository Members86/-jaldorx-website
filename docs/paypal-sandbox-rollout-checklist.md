# PayPal Sandbox integration — staged rollout

## Current status
- This branch is isolated from `main`; pushes here do not deploy to IONOS.
- The PayPal REST helper is prepared, with method/path validation performed before requesting an OAuth token.
- The database migration adds payment state and reservation metadata. It must be applied only once; the live database was reported to show a successful ALTER TABLE result.
- The sandbox checkout and the new create/capture endpoints are implemented on this branch; it is not deployed to production.
- The create endpoint rejects malformed or unknown cart items, aggregates all single-item quantities for tier pricing, and limits request size.
- Expired reservation cleanup is transactional and rechecks expiry after locking the order to reduce race conditions. Expiry comparisons use the database clock (`NOW()`) consistently instead of mixing PHP and database time zones. Cleanup now subtracts only when the recorded reservation quantity is available, including schemas that use the inventory row ID fallback; it fails visibly rather than forcing reservations to zero.
- Capture reconciliation checks the PayPal status, amount, currency, local order reference, and order number. Concurrent retries that observe an already-paid order return success without deducting stock again. If PayPal's exact completed capture is verified but local finalization fails, the API returns `PAYMENT_RECEIVED_RECONCILIATION_REQUIRED`; checkout explicitly tells the buyer not to pay again and preserves the cart while the order remains recoverable in `capturing`.
- The GitHub Actions lint workflow now extracts inline script blocks with a whitespace-safe script-tag pattern. Syntax lint passing does not establish that the payment flow works against IONOS/PayPal Sandbox.
- A separate test matrix documents 18 scenarios and the evidence needed before release: `docs/paypal-sandbox-test-matrix.md`.

## Required implementation order
1. Add a server-side order-creation endpoint that recalculates every price from trusted server data, creates a pending order, and reserves stock under a database transaction.
2. Create the PayPal order on the server using the server-calculated total and save the PayPal order ID.
3. Harden the capture endpoint for uncertain network outcomes: if PayPal captured successfully but the HTTP response was lost, a retry must reconcile the PayPal order/capture before changing local stock.
4. Make capture idempotent so refreshes/retries cannot deduct stock twice; never revert an uncertain capture to `pending` without checking PayPal's server-side order status.
5. Add expiry cleanup that releases reservations for unpaid orders after their deadline.
6. Update checkout to use PayPal's JS SDK with the sandbox client ID and the new endpoints. Keep the current test checkout untouched until the new flow is ready.
7. Test: successful payment; customer cancellation; declined/failed capture; duplicate capture request; two concurrent purchases for the last item; expiry and stock release; exact 40-tree limit; shipping threshold and all box prices.
8. Review the complete diff and only then open a pull request. Do not merge/deploy until all tests pass.

## Secrets and configuration
- Never put the PayPal client secret in GitHub or browser JavaScript.
- The server config must be stored outside the public document root if IONOS allows it. If not, deny direct web access to the config file and verify that URL access cannot reveal its source.
- Sandbox mode only. Do not switch to live credentials or collect real payments during this phase.

## Important current risk
The capture endpoint can leave an order in `capturing` after a network or database failure. A verified PayPal capture is now distinguished from a failure before confirmation so checkout can warn the buyer not to pay again. The local inventory transaction must still be recovered by a safe retry or operator review if inventory invariants are broken. Do not merge/deploy or accept real payments until the full sandbox scenarios have been exercised on IONOS.
