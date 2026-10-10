# JALDORX PayPal Sandbox — test matrix

These tests must be run on an isolated IONOS sandbox deployment with PayPal Sandbox credentials and a non-production database. Do not use real payment credentials or merge this branch into `main` to perform the tests.

## Preconditions
- Confirm the deployed test copy points to the intended test database, not the live shop database.
- Confirm `paypal-config.php` is outside the public document root and has `mode => sandbox`.
- Confirm the PayPal public-config endpoint returns only the sandbox client ID and never the client secret.
- Record the inventory `stock` and `reserved` values before each scenario.
- Use PayPal Sandbox buyer accounts and test payment methods only.

## Test cases

| ID | Scenario | Expected result |
|---|---|---|
| P01 | One item, successful sandbox payment | Confirmation shown; order marked paid; stock decreases by 1; reserved decreases by 1. |
| P02 | Customer closes/cancels PayPal approval | No paid order; stock unchanged; reservation remains only until expiry or safe cleanup. |
| P03 | Retry after cancellation | A new attempt can proceed if stock is available; no reservation is subtracted twice. |
| P04 | PayPal authorization/order creation fails | No payable order is returned; any committed reservation is safely released; stock unchanged. |
| P05 | Invalid/missing customer fields | Server rejects request; no order and no reservation. |
| P06 | Terms checkbox not accepted | Server rejects with `TERMS_NOT_ACCEPTED`; no order and no reservation. |
| P07 | Tampered client price/subtotal | Server ignores client-calculated prices; PayPal amount matches server-calculated order. |
| P08 | 41 trees or more | Server rejects request; stock and reservations unchanged. |
| P09 | Exactly 40 single trees | Server-calculated total is €149.90; no extra shipping. |
| P10 | Subtotal just below €99 | Shipping is calculated server-side according to quantity rule. |
| P11 | Subtotal at/above €99 | Shipping is €0.00. |
| P12 | Duplicate capture request after successful payment | Returns already-paid success; stock is deducted only once. |
| P13 | Two concurrent orders competing for the final available stock | At most one order reserves the unavailable final unit; no negative available stock. |
| P14 | Expired pending order | Reservation is released once; order becomes expired; stock itself is not decremented. |
| P15 | PayPal capture succeeds but local finalization fails | Checkout explicitly says not to pay again; order remains in recoverable `capturing` state; operator reconciliation is required. |
| P16 | Inventory reservation is inconsistent | Finalization fails safely; no forced zeroing or blind stock decrement. |
| P17 | Refresh/reopen checkout after confirmed payment | Cart is cleared only after server confirms local paid state. |
| P18 | Mobile layout on iPad and desktop browser | Form and PayPal button remain usable without horizontal overflow. |

## Evidence to record
For each case, note the test ID, order number (never customer secrets), API outcome, payment status, stock and reserved values before/after, and whether the result matched expectations. Do not publish buyer personal data, client secrets, access tokens, or full payment details.

## Release gate
Do not merge or deploy to production until all applicable cases pass, the latest PHP/JavaScript syntax checks pass, the complete diff has been reviewed, and live credentials/configuration remain out of the sandbox branch. Real payment acceptance requires a separate explicit live-readiness review.
