# Credit sales, credit purchases and party ledgers

This is a **customer / supplier sub-ledger**, not a general ledger. There is no chart of accounts, journal, or double-entry
bookkeeping; it only tracks what each customer owes the business and what the business owes each supplier.

All money is stored as integer minor units (amount x 100). Convert decimals only at the boundary with
`money_to_minor()` / `minor_to_money()` (`app/Helpers/helpers.php`). Services work in integers only.

## Conventions

| Event | Customer ledger | Supplier ledger |
|---|---|---|
| Sale invoice | Debit | |
| Customer receipt / advance | Credit | |
| Sale return | Credit | |
| Refund paid to customer (return payment) | Debit | |
| Opening balance (positive) | Debit (customer owes) | Credit (we owe) |
| Purchase invoice | | Credit |
| Payment to supplier / advance | | Debit |
| Purchase return | | Debit |
| Refund received from supplier | | Credit |
| Cancel / void / amendment | Compensating reversal entry (never edit/delete) | same |

* Customer balance = `SUM(debit) - SUM(credit)`; positive = customer owes us, negative = customer credit/advance.
* Supplier balance = `SUM(credit) - SUM(debit)`; positive = we owe the supplier, negative = supplier advance.
* `ledger_entries` is append-only (model hooks throw on update/delete). Each posting has a unique `posting_key`, so
  posting twice is impossible.
* Ledger posting happens for sales/purchases/returns **at creation, whatever their status** (Pending, Shipped, ...),
  cancelling reverses it. Stock still moves only on the original rules (`StockService::MOVING_STATUSES`).

## Where things live

| Concern | File |
|---|---|
| Ledger, receipts, allocation, voids, cancellation, opening balances | `app/Services/Ledger/LedgerService.php` |
| Create/update invoices & returns (server totals, stock, ledger) | `app/Services/Ledger/DocumentWriter.php` |
| Row-locked stock moves | `app/Services/Ledger/StockService.php` |
| Outstanding / ageing / ledger / history queries | `app/Services/Ledger/CreditQueries.php` |
| Real cash movements | `app/Services/Ledger/CashFlow.php` |
| Reference numbers (`SL-00001` ...) | `app/Services/Ledger/ReferenceGenerator.php` (`reference_counters` table) |
| Reconciliation | `app/Services/Ledger/Reconciler.php`, `php artisan ledger:reconcile` |
| Historical backfill | `php artisan ledger:backfill` |
| Customer / supplier payments, advances, opening balance | `app/Http/Controllers/PartyAccountController.php` |
| Reports | `app/Livewire/Reports/{OutstandingInvoices,PartyLedger,PartyPayments,Ageing}Report.php` |

## Payments

* `customer_receipts` / `supplier_payments` are payment **headers** (one real money movement).
* The existing `sale_payments` / `purchase_payments` rows are the **allocations** of a header to an invoice
  (`receipt_id` set). Legacy rows have `receipt_id = NULL` ("direct" payments).
* Invoice `paid_amount`, `due_amount`, `payment_status` are cached values written **only** by
  `LedgerService::recalculateInvoicePaymentStatus()` from valid (non-voided) payment rows.
* An allocation cannot exceed the invoice balance; allocations cannot exceed the header amount. The unallocated rest of a
  header is an **advance**; allocate it later from the customer/supplier page. Allocation never changes the party balance.
* Payments are never deleted. "Void" keeps the row, restores the invoice balance and reverses the ledger entry. Voiding a
  header-linked payment voids the whole header.
* Return documents' payments (refunds) are direct rows with their own ledger entry.

## Cancelling documents

`DELETE` on a sale / purchase / return now **cancels** it (status `Cancelled`, `cancelled_at`): stock is restored only if
the document had moved it, the ledger entry is reversed, header-linked payments are released back to the party as an
advance (money not lost), and legacy direct payments are voided. The document and its lines are retained.
Cancelling a purchase whose stock was already sold is refused (it would make stock negative).

## Overdue / ageing

Based on `due_date` (optional, never invented for old invoices). Documents without a due date are shown as **Current / not due**.
Outstanding = invoice total - valid payment rows (not the cached columns).

## Permissions (created by migration `2026_10_16_000004` and the seeder; granted to the `Admin` role)

`access_customer_ledger`, `access_supplier_ledger`, `receive_customer_payments`, `make_supplier_payments`,
`access_credit_reports`, `manage_opening_balances`, `void_payments`. Cancelling reuses `delete_sales`, `delete_purchases`, `delete_*_returns`.
Super Admin passes every gate as before.

## Deployment

1. **Back up the database** (see below) and put the app in maintenance mode: `php artisan down`.
2. Deploy code, `composer install --no-dev -o`.
3. `php artisan migrate --force`  (adds columns/tables/permissions; reversible with `migrate:rollback --step=4`).
   * The unique index on document `reference` is only added when there are no duplicates (see the log if skipped).
4. `php artisan ledger:reconcile --csv` - read-only; lists invoices whose stored paid/due disagree with their payment rows.
   * For each row get a business decision, then create a CSV `kind,id,resolution,reason,approved_by` with
     `resolution=use_payments` (payment rows are the truth) - incomplete rows are ignored.
5. `php artisan ledger:backfill --dry-run [--resolutions=file.csv]`, review, then `php artisan ledger:backfill [--resolutions=file.csv]`.
   Idempotent and transactional; it refuses to run while discrepancies are unresolved and verifies the ledger agrees
   with the documents before committing.
6. Set opening balances (customer / supplier page > Opening Balance) if needed. `php artisan up`.

Until step 5 is done, ledger balances of parties with historical documents are incomplete. Run it in the same maintenance window.
Existing invoices whose paid/due disagree with their payments cannot be paid/edited until they are resolved.

## Backup and recovery

* MySQL: `mysqldump --single-transaction --routines <db> > backup-$(date +%F).sql` before step 3; keep it until the backfill is verified.
* Recovery of a failed deploy: `php artisan down`, restore the dump, redeploy the previous release, `php artisan up`.
* `migrate:rollback --step=4` removes the new schema (ledger data is lost; the original sales/purchases data is untouched).

## Tests

`vendor/bin/phpunit` runs on in-memory SQLite (`phpunit.xml`). It never touches `database/database.sqlite`.
The concurrency test is sequential: it proves the balance is re-read under a lock for every payment, not parallel execution.
