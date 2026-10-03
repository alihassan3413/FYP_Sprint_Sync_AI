# Finance rules (non-negotiable)

These rules apply to every piece of Finance code: clients, recurring invoices,
invoices, numbering, approval, email delivery, payments, time-entry aggregation,
scheduled automation, and anything added later. A change that breaks one of
them is a bug, even if every test passes.

## 1. Idempotency

Anything that can be **retried, double-clicked, replayed, queued or scheduled**
must produce the same result when it runs twice. The server is the authority:
disabled buttons, debounced clicks and other frontend guards are conveniences,
never the protection.

Use, in this order of preference:

1. **Database unique constraints** so a duplicate cannot be stored at all.
2. **Transactions** around every multi-row change.
3. **Conditional state transitions** (`UPDATE … WHERE status = 'expected'`, then
   check the affected-row count) so two racing requests cannot both win.
4. **Explicit idempotency keys** for anything that reaches the outside world
   (email, webhooks, payment providers), stored with a unique constraint.

How each Finance operation must satisfy this:

| Operation | Guarantee | Mechanism |
|---|---|---|
| Recurring invoice generation | One invoice per plan per billing period, however often the job or scheduler runs | `UNIQUE(billing_plan_id, period_start)`; a unique violation means "already done", not an error |
| Approval | Double-click or retry never issues or numbers an invoice twice | Conditional transition from the awaiting state; numbering inside the same transaction |
| Invoice numbering | Concurrent approvals never share a number | `UNIQUE(workspace_id, number)`, sequence allocated in the approving transaction, retry on conflict |
| Email delivery | A queue retry never emails a client twice | Delivery row with a unique idempotency key, claimed atomically (`pending → sending`) before contacting SMTP; send jobs do not auto-retry after the claim, a failed send is retried by a person |
| Payments | Resubmitting the form never records a payment twice | Idempotency key per submission (unique), payment and invoice balance updated in one transaction |
| Time-entry aggregation | Re-running never double-counts tracked hours | Aggregation is recomputed from source entries (replace, never increment); imported entries carry a unique source key |
| Scheduled automation | Overlap or retries never create duplicate work | `withoutOverlapping()` on scheduler commands **and** the unique constraints above; jobs re-check state on start |

## 2. Money

- Amounts are **integers in the currency's minor unit** (`Money`, `*_minor`
  columns). Never floats, never `DECIMAL` (SQLite gives back floats).
- All totals come from `InvoiceCalculator`. Rounding happens only in
  `Money::divideHalfUp`.
- Every amount carries a `Currency`. Values in different currencies are never
  added together; dashboards group by currency. No FX conversion.

## 3. History never changes

- Issued invoices are immutable. Lines, rates, adjustments, client details and
  sender details are **snapshotted** onto the invoice when it is created.
- Corrections are made by cancelling and reissuing, never by editing.
- Recurring-plan edits affect future invoices only.
- Adjusted tracked hours are stored on the invoice line (original tracked
  quantity, billed quantity, actor, time, reason). Time entries are not changed
  from the invoice.

## 4. Access and privacy

- Every Finance decision goes through `Workspace::allowsFinance()`. Workspace
  admin rank grants nothing on its own. v1 is owner-only.
- Finance records are addressed by an opaque `public_id` (ULID) in URLs and
  props. The sequential `id` and storage paths are never sent to the browser.
- Opaque ids are a privacy measure, **not** authorization. Tenant routes, scoped
  bindings and policies stay mandatory; a record from another workspace is a 404.
- Finance files (client logos, later invoice PDFs) live on the private disk and
  are only streamed through a route that checks the policy.

## 5. Two different logos

- **Client logo** (`clients.logo_path`): the customer's branding, shown in the
  app next to the client.
- **Sender / company logo**: the workspace's own branding printed on invoices.
  It belongs to the workspace's invoicing details, not to any client. When it is
  added, invoices snapshot it like the rest of the sender details.

Never reuse one for the other.
