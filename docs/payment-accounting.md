# Payment accounting

## Set up before accepting new payments

```sh
git pull origin main
php artisan migrate
php artisan db:seed --class=PaymentAccountingSeeder
npm run production
```

The existing Accounting Foundation accounts must already be seeded. The payment
seeder adds 1101 Cash in Hand and 1102 Bank Receipts Clearing only when their codes
do not already exist; it does not overwrite existing account settings.

In Accounting → Fiscal Years & Periods, create the fiscal year covering receipt
dates. It generates 12 open monthly periods. Closing a period blocks new journal
postings in that period. A year can close after all its periods close.

Create individual bank accounts beneath 1100 Cash & Bank in Chart of Accounts as
needed. Receiving accounts must be active asset accounts with no child accounts,
with active ancestors leading to the system Cash & Bank account. Group/control
accounts cannot be selected. Bank Receipts Clearing is a temporary clearing
account, not an individual bank statement account.

## Entries

Each new verified receipt posts a journal on its payment date:

| Side | Account |
| --- | --- |
| Debit | Selected cash/bank account |
| Credit | 2300 Customer Advances |

Both lines carry the customer and project. Customer Advances is used because
booking receivable/revenue recognition has not yet been integrated. This stage
does not recognize property sales revenue. Cheques follow the existing verified
receipt workflow; a separate cheque-clearance workflow is not implemented.

Payment creation, journal creation, booking/installment changes and financial
audit records share one transaction. Missing setup, a closed period or invalid
account rolls everything back. Each source payment can have only one journal.

The payment list/detail view shows its journal reference. Reverse payments using
the Payments screen. Reversal creates opposite lines in an open period on the
chosen reversal date (default today, no earlier than the receipt). The original
journal stays posted, so history and running balances remain complete. It is not
possible to reverse a payment journal through the manual Journal Entries API.

Historical receipts are shown as Not posted. They are not automatically backfilled.
Reversing a legacy receipt that never had a journal changes its operational
balances without creating an unmatched accounting reversal. Historical migration
and opening balance reconciliation should be handled as a separate step.

## Checks

```sh
php artisan test --filter=PaymentAccountingTest
php artisan test --filter=AccountingReportTest
```

Tests use isolated in-memory SQLite. They cover balanced postings, project/customer
dimensions, idempotency, closed-period rollback of receipts and reversals, account
and amount validation, prevention of manual reversal bypass, and legacy handling.
