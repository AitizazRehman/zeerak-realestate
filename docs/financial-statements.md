# Customer and project financial statements

## Update and navigation

```sh
git pull origin main
npm run production
```

No new migration or seeder is required. Under **Accounting**, open **Customer
Statements** or **Project Statements**. Both require `accounting.view`.

Choose the required customer or project, optionally narrow by the other dimension,
and apply an inclusive date range. The displayed title shows the applied selection.
Changing filter fields does not change the current results until View statement
is pressed. Pagination and CSV export use the applied selection.

## Customer statement

Consolidates posted entries tagged to the customer across their bookings/projects,
restricted to system accounts 1200 (Accounts Receivable) and 2300 (Customer Advances).
Shows account opening balances, period debit/credit movements and closing balances,
plus closing receivable, advance and net position indicators. Net position compares
receivable less advances; it does not settle or transfer money between bookings.

Balanced journal counterparts and agent commission costs are excluded. Collections
appear in Customer Advances; recognized revenue creates receivables; receipt
allocations and reversals appear on their own accounting dates. Unrecognized booking
values and historical transactions without journals are not ledger balances.
Existing operational customer statements remain unchanged.

## Project statement

Consolidates all posted lines tagged to the selected project, including receipts,
revenue recognition, allocations, expenses, commissions and reversals. Shows account
opening/movement/closing balances and period recorded revenue, recorded costs
(expense and cost-of-sales accounts), and their difference as the recorded result.
Receivable and advance indicators use closing balances through the To date.

The recorded result is not a complete project profitability calculation: unposted
inventory costs, unallocated overhead, taxes and other missing journals are not
inferred. Lines without a project tag are excluded. Selecting a customer further
restricts the project statement to that customer's tagged lines; shared project
costs without that customer tag will not appear.

## Detail, export and access

- Only posted journals are included. Drafts and entries after To are excluded.
- Opening balances include all matching postings before From. Reversals remain
  separate activity on their own dates.
- Transaction details show account, project, customer, description, debit, credit
  and a running balance **per account**, continuing across pages of 50 lines.
- Positive signed balances are debit; negative are credit. UI labels use Dr/Cr.
- CSV contains the applied selection, all account totals, and **all** matching
  period activity, regardless of the current page. Negative balances remain numeric;
  formula-like text fields are escaped for spreadsheet safety.
- Historical inactive/deleted accounts and archived customers/projects remain
  available so prior postings do not disappear.
- Admin and Super Admin may view all branches. Other users need a branch and may
  select only customers/projects owned by that branch. Lines with either dimension
  explicitly assigned to another branch are excluded, including from CSV exports.
  Scope follows current ownership because journal lines do not store a branch
  snapshot. Untagged sides are included only where the required entity still matches.
- Statements do not assert that a dimensional selection forms a balanced trial
  balance. No artificial balancing entry or overall running balance is introduced.

## API and validation

`GET /accounting/statement-options` returns branch-scoped customer/project choices.
`GET /accounting/statements` and `GET /accounting/statements/export` accept:

- `type`: `customer` or `project`
- `customer_id`: required for customer statements; optional for project statements
- `project_id`: required for project statements; optional for customer statements
- `from`, `to`: `YYYY-MM-DD`, with To on/after From
- `page`: optional, used by JSON activity pagination only

```sh
php artisan test --filter=FinancialStatementTest
```

Tests use isolated in-memory SQLite. Coverage includes opening balances, drafts,
future dates, reversals, per-account pagination, dimensional intersections, archived
records, empty results, branch isolation, full exports and CSV formula escaping.
