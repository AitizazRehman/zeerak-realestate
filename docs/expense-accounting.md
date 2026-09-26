# Expense accounting

## Update

```sh
git pull origin main
php artisan migrate
npm run production
```

The Accounting Foundation and payment cash/bank accounts must already exist.
If cash/bank accounts have not yet been seeded, run
`php artisan db:seed --class=PaymentAccountingSeeder`. Open the relevant period
under Accounting → Fiscal Years & Periods.

## Recording expenses

New expenses require a debit account and a cash/bank account. The debit selector
includes active, non-control leaf accounts of type Expense or Cost of Sales that
allow manual posting. The credit selector uses the same eligible Cash & Bank
accounts as Payments. Both lines carry the selected project.

Each new expense posts on its expense date:

| Side | Account |
| --- | --- |
| Debit | Selected expense or cost-of-sales account |
| Credit | Selected cash/bank account |

This workflow records paid expenses. Unpaid vendor bills, capitalized inventory
or asset purchases, and payable settlement need their own workflows. Category
labels do not determine the debit account; the user selects the account explicitly.

The expense, journal, and financial audit are saved in one transaction. A missing
account or closed period rolls everything back. Duplicate calls to the posting
service do not create additional journals for the same expense.

## Corrections and reversals

Posted amounts, dates, categories, projects/properties, payment methods and
accounts are immutable. Description, vendor/reference and notes can be updated
with an audit record; the original journal description remains unchanged.

Use Reverse Expense to cancel an expense, supplying a reason and a reversal date
in an open period, on or after the expense date and no later than today. It creates
opposite journal lines while retaining the original journal, expense and attachments.
Create a replacement expense for financial corrections. Reversed expenses cannot
be edited or deleted; posted expenses cannot be deleted through the old DELETE API.

The expense screen defaults to Active, with Reversed and All filters available.
Reversed expenses are excluded from operational dashboard/report/export totals.
Those operational reports show the current active expense population; the General
Ledger and Trial Balance retain both postings on their actual entry/reversal dates
and are the source for historical accounting balances.

Historical expenses are not automatically posted. Reversing a historical expense
without a journal does not create an unmatched reversal. Historical accounting
migration and opening balances remain a separate task.

## Verification

```sh
php artisan test --filter=ExpenseAccountingTest
php artisan test --filter=PaymentAccountingTest
php artisan test --filter=AccountingReportTest
```

These tests use isolated in-memory SQLite and require PHP's SQLite extension.
