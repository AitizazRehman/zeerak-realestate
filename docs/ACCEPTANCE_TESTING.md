# ZeeraK ERP Acceptance Testing

Run these checks before a production deployment and after any major finance, CRM, branch-access or document-storage change.

## 1. Automated non-destructive check

The command below does not create, update or delete application records:

```bash
php artisan zeerak:acceptance-check
```

For deployment gates, warnings can also fail the command:

```bash
php artisan zeerak:acceptance-check --strict
```

It verifies:
- core ERP tables exist
- critical API routes are registered
- converted leads have linked customers
- converted lead site visits reference the same customer
- payment/customer/booking references are consistent
- payment installments belong to the same booking
- installments belong to the same booking as their installment plan
- expense/project branch ownership is consistent
- active customer and expense branch ownership
- financial-document entity types are supported
- private financial-document paths are safe
- financial-document database rows still have physical files

A failure should be investigated before deployment. The command never repairs data automatically.

## 2. CRM workflow

Using a test lead in the intended branch:

1. Create a new lead.
2. Assign the lead to a Sales Agent and project from the same branch.
3. Schedule a site visit.
4. Update the site visit status and feedback.
5. Convert the lead to a customer.
6. Confirm the previous site visit is linked to the new customer.
7. Try changing the converted lead back to another status. The system must reject it.
8. Create another lead with the same phone or email and attempt conversion.
9. Confirm the UI offers to link the existing customer rather than silently creating a duplicate.

## 3. Booking and installment workflow

1. Create a booking for the converted customer.
2. Confirm the property becomes unavailable according to the booking workflow.
3. Create an installment plan.
4. Verify installment count and total equal the plan configuration.
5. Confirm due dates follow the selected frequency.
6. Confirm the final installment absorbs any rounding difference.

## 4. Payment workflow

1. Record a payment against the booking/installment.
2. Confirm booking paid and remaining balances update.
3. Confirm installment paid and remaining balances update.
4. Download the payment receipt.
5. Attach a supporting document.
6. Reverse the payment using an authorized account.
7. Confirm the booking/installment balances return to the expected values.
8. Confirm the audit trail contains both payment and reversal activity.

Run afterward:

```bash
php artisan zeerak:reconcile-finances
```

Do not use `--fix` until every reported inconsistency has been reviewed.

## 5. Finance supporting documents

For payment, installment, commission and expense records:

1. Upload a valid PDF or image.
2. Download it and confirm the file opens correctly.
3. Attempt an invalid/mismatched file extension and confirm the upload is rejected.
4. Delete a document.
5. Confirm the record disappears and an audit entry is retained.
6. Re-run `php artisan zeerak:acceptance-check` and confirm no missing files are reported.

## 6. Branch isolation

Test with both an administrator and a branch-limited user.

A branch-limited user must not be able to access another branch's:
- projects and properties
- leads and customers
- bookings and payments
- installments
- commissions and expenses
- financial supporting documents

Do not rely only on hidden frontend buttons. Try direct URLs/API requests where practical.

## 7. Roles and permissions

Verify representative users for:
- Super Admin/Admin
- Sales Agent
- finance/reporting roles used by the organization

Confirm both visible navigation and backend authorization match the granted permissions.

## 8. Reports and dashboards

Check:
- Dashboard date ranges
- Sales Dashboard
- financial summary
- sales report
- collections report
- installment report
- expense report
- commission report
- PDF/Excel exports where available

Compare at least one report total manually against source transactions.

## 9. Settings and interface

Verify:
- company name updates across the application
- company logo updates login/sidebar/favicon
- light/dark mode persists after refresh
- compact navigation persists
- browser favicon loads
- global loader follows the selected theme
- background notification polling does not show the global loader

## 10. Backup and restore readiness

Before production launch:

```bash
php artisan zeerak:backup
php artisan zeerak:verify-backup
```

A production release should not proceed until both the automated acceptance check and backup verification complete successfully and the manual critical workflows above have been exercised.
