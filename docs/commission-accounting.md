# Commission accounting

## Update and setup

```sh
git pull origin main
php artisan migrate
npm run production
```

The Accounting Foundation must already be installed. Keep system accounts 6500
(Sales Commission Expense) and 2200 (Commission Payable) active; 6500 must have no
children. Payments use the same active cash/bank posting accounts as customer
payments. If those accounts have not been seeded, run
`php artisan db:seed --class=PaymentAccountingSeeder`.
Open the relevant period under Accounting → Fiscal Years & Periods.

## Workflow

Create a commission against a booking assigned to its sales agent. Pending
commissions do not post. Approval and payment are separate accounting events:

| Action | Debit | Credit |
| --- | --- | --- |
| Approve | 6500 Sales Commission Expense | 2200 Commission Payable |
| Mark paid | 2200 Commission Payable | Selected cash/bank account |
| Reverse payment | Original cash/bank account | 2200 Commission Payable |
| Cancel approved commission | 2200 Commission Payable | 6500 Sales Commission Expense |

Select the accounting date when approving, paying, or cancelling. Dates must be
in an open period and no later than today. Payment cannot precede approval;
reversal cannot precede payment; repayment/cancellation cannot precede an earlier
payment reversal. Both sides carry the booking project and customer. The agent
is referenced in the journal line description.

A payment always settles the entire commission. Partial payouts, withholding,
and commission capitalization are not included. Status, journal, payment history,
and financial audit updates are transactional; a closed period or invalid account
rolls them all back.

## Corrections and history

Use Reverse payment only when the payout was voided or funds were returned.
Supply a reversal date and reason. The commission returns to approved, restoring
its payable; it can then be paid again or cancelled. Each payout and reversal is
retained separately in Accounting history. The original journals and attachments
remain available. Source journals cannot be reversed through the manual journal
screen.

A paid commission must have its payment reversed before cancellation. Cancelling
an approved commission reverses its accrual. Cancelling a pending commission
creates no journal. Booking cancellation applies the same commission cancellation
rules in one transaction; a closed period blocks the entire booking cancellation.

## Historical commissions

Existing records are not automatically imported. For an approved commission with
no journal, choose **Post approval to accounting**, review the accounting date,
and post its expense/payable before recording a payment. Ensure the liability has
not already been included in opening balances or manual journals to avoid counting
it twice.

Reversing a historical paid commission with no accounting payment record changes
its operational status only; it creates no unmatched journal. Recognize the
resulting approved commission explicitly if it is to be paid through this workflow.
Historical accounting reconciliation remains a separate task.

## API and verification

`PUT /commissions/{id}` accepts `accounting_date`, and requires
`cash_bank_account_id` and a unique `request_key` when moving to paid. Keep the same
key for retries; create a new key for a new payout after reversal. Reusing a reversed
payment key is rejected. `POST /commissions/{id}/reverse` requires a reason,
`reversal_date`, and the active `payment_id` for accounted payouts; this prevents
a stale reversal request from reversing a later payout. `POST /commissions/{id}/recognize`
accepts `accounting_date` for explicit historical approval recognition.

```sh
php artisan test --filter=CommissionAccountingTest
php artisan test --filter=ExpenseAccountingTest
php artisan test --filter=PaymentAccountingTest
php artisan test --filter=AccountingReportTest
```

Tests use isolated in-memory SQLite and require PHP's SQLite extension. They cover
posting balances, retries, repayment history, stale reversal requests, closed-period
rollback, booking cancellation, historical recognition, and account/date validation.
