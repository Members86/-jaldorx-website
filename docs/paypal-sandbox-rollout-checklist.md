# PayPal Sandbox integration — staged rollout

## Current status
- This branch is isolated from `main`; pushes here do not deploy to IONOS.
- The PayPal REST helper is prepared, with method/path validation performed before requesting an OAuth token.
- The database migration adds payment state and reservation metadata. It must be applied only once; the live database was reported to show a successful ALTER TABLE result.
- The checkout still calls the legacy `api/order.php`, which reduces stock before payment. Do not enable a PayPal button against that endpoint.

## Required implementation order
1. Add a server-side order-creation endpoint that recalculates every price from trusted server data, creates a pending order, and reserves stock under a database transaction.
2. Create the PayPal order on the server using the server-calculated total and save the PayPal order ID.
3. Add a capture endpoint that accepts only the matching local pending order, captures on PayPal's server API, verifies `status=COMPLETED`, currency EUR, and the exact expected amount, then atomically converts reserved stock into sold stock and marks the order paid.
4. Make capture idempotent so refreshes/retries cannot deduct stock twice.
5. Add expiry cleanup that releases reservations for unpaid orders after their deadline.
6. Update checkout to use PayPal's JS SDK with the sandbox client ID and the new endpoints. Keep the current test checkout untouched until the new flow is ready.
7. Test: successful payment; customer cancellation; declined/failed capture; duplicate capture request; two concurrent purchases for the last item; expiry and stock release; exact 40-tree limit; shipping threshold and all box prices.
8. Review the complete diff and only then open a pull request. Do not merge/deploy until all tests pass.

## Secrets and configuration
- Never put the PayPal client secret in GitHub or browser JavaScript.
- The server config must be stored outside the public document root if IONOS allows it. If not, deny direct web access to the config file and verify that URL access cannot reveal its source.
- Sandbox mode only. Do not switch to live credentials or collect real payments during this phase.

## Important current risk
The legacy `api/order.php` still deducts `inventory.stock` immediately when a test order is saved. It is not safe to use for PayPal until the order/reservation/payment flow above replaces that behavior.
