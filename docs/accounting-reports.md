# Accounting reports

The Accounting menu includes General Ledger and Trial Balance. Both require
`accounting.view` and read only posted journal entries. Existing payments,
expenses, commissions and bookings do not appear until journal posting is
connected to those modules.

## Balances

- Dates are inclusive. Opening balances include all posted lines before From.
- Ledger running balances use date, journal ID and line ID order and continue
  across pages. Each page contains up to 50 lines.
- Positive net balances are shown as Dr; negative balances as Cr.
- Trial Balance lists accounts with posting history through To. Period debit
  and credit columns show activity between From and To; closing columns include
  the opening balance.
- Reversals remain separate postings and contribute on their own entry dates.
- Historical inactive and soft-deleted accounts remain visible.
- Project/customer filters apply to opening balances and period activity. A
  filtered trial balance may not balance if dimensions exist on only one side
  of an entry.
- Values are PKR. There is no currency conversion in this version.

## Update and verify

Run in the project directory after pulling main:

```sh
php artisan migrate
npm ci
npm run production
php artisan test --filter=AccountingReportTest
```

The report test uses an isolated in-memory SQLite connection and requires the
PHP SQLite extension. It covers opening balances, drafts/future exclusions,
reversals, dimensions, pagination, empty results and invalid date ranges.
