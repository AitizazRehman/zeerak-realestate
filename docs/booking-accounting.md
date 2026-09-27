# Booking revenue and receivables

## Setup and recognition

Pull the update and run `npm run production`. This phase uses existing journal
and account tables and needs no new migration. The Accounting Foundation must be
installed, with active system accounts 1200 (Accounts Receivable), 2300 (Customer
Advances), and 4100 (Property Sales). Account 4100 must have no children. Open all
periods affected by recognition and receipt allocations.

In **Sales → Bookings**, open **Booking accounting** using the book icon. Viewing
requires `accounting.view`; recognizing requires `accounting.edit`, with branch
access enforced. Confirmed or completed bookings can recognize their full sale
value. Supply a recognition date and supporting handover/agreement/approval
reference, then confirm that recognition requirements are met.

Recognition is an explicit accounting decision. Reserving, confirming, completing,
or fully paying a booking does not automatically recognize revenue. The software
does not determine whether a contract qualifies for recognition. Partial/milestone
recognition, tax, inventory cost, cost of sales, and profit recognition are outside
this phase. There is no automatic historical backfill.

## Journal flow

| Event | Debit | Credit |
| --- | --- | --- |
| Receive collection | Cash / bank | 2300 Customer Advances |
| Recognize full sale | 1200 Accounts Receivable | 4100 Property Sales |
| Apply receipt | 2300 Customer Advances | 1200 Accounts Receivable |
| Reverse applied receipt | 1200 Accounts Receivable | 2300 Customer Advances |
| Reverse cash collection | 2300 Customer Advances | Original cash / bank |
| Cancel recognized booking | 4100 Property Sales | 1200 Accounts Receivable |

Recognition applies all active, journaled booking receipts. Subsequent receipts
create both the collection and allocation entries in the same transaction. Each
allocation uses the later of the receipt and recognition dates. Every line carries
the booking project and customer. The booking screen displays recognition,
cancellation, and receipt allocation journal references and dates; the General
Ledger and Trial Balance include these postings.

For a PKR 1,000 sale with PKR 200 collected, recognition produces PKR 1,000 revenue,
PKR 800 receivable, and no remaining advance. Reversing the receipt restores the
PKR 1,000 receivable while keeping recognized revenue unchanged.

## Controls and corrections

- Recognition date must be on/after booking date and no later than today.
- Active receipts must have posted, unreversed journals and agree with booking
  totals. Historical receipts without journals block recognition; reconcile the
  historical data first. Do not create replacement cash receipts merely to satisfy
  this check. Review any manual revenue/receivable or opening balance postings to
  avoid duplication.
- Recognition cannot predate a receipt reversal already recorded for the booking.
  Reversed receipts are excluded from allocation; this guard prevents silently
  omitting their interim balances when backdating recognition.
- Recognized price/discount and booking date are immutable. Notes remain editable
  where the existing booking status permits. Recognized bookings cannot be deleted.
- Reverse receipts through Payments using a date on/after their allocation date.
  Both allocation and collection reversals post together. Revenue remains recognized,
  including when an operationally completed booking reopens after a receipt reversal.
- Cancel through the existing booking cancellation workflow after reversing all
  customer and commission payments. Revenue and commission accrual reversals occur
  with the booking cancellation, dated today. Completed bookings retain their
  existing cancellation restrictions.
- Closed periods, missing accounts, or allocation failures roll back the entire
  operation. Repeated recognition does not create duplicate journals or audit rows.
  Source journals cannot be reversed through the manual journal screen.

Revenue reversal is currently tied to booking cancellation. Independent credit
notes, amendments, and reversing recognition while retaining customer deposits
require a subsequent workflow.

## API and validation

- `GET /bookings/{id}/accounting`: recognition, cancellation and receipt allocations.
- `POST /bookings/{id}/recognize-revenue`: `accounting_date` (`YYYY-MM-DD`),
  `reference` (up to 300 characters), and `confirmed` (accepted/true).

```sh
php artisan test --filter=BookingAccountingTest
php artisan test --filter=CommissionAccountingTest
php artisan test --filter=ExpenseAccountingTest
php artisan test --filter=PaymentAccountingTest
php artisan test --filter=AccountingReportTest
npm run production
```

Tests use an isolated SQLite database. They cover pre/post-recognition receipts,
balances, dimensions, closed-period rollback (including allocation failures),
reversals, cancellation, historical data guards, immutability, branch access,
idempotency, and operational completion/reopening.
