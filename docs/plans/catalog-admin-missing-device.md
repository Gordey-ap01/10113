# Catalog editing and missing-device requests

Date: 2026-09-29. Deployment target: existing Beget WordPress staging only.

## Catalogue administration

- Count actual catalogue models, including drafts; show the published subset separately.
- Select existing categories and brands when adding a device. Filter brands by category and validate the relation on the server.
- Assign a brand to one or more existing categories. Configure its numerical weight separately in each category; lower values appear first.
- Configure model weight and preserve existing model URLs and prices. Keep old Excel files readable; include model order in new exports.
- Verify zero weights, draft counts, category/brand combinations, ordering, stale imports and Excel round trips.

## Missing-device workflow

- Place a clear “Нет моего устройства” button in the right side of the selection panel, with the repair count reduced to secondary information.
- Open a dedicated accessible dialog without services or an assumed selected device. Name and phone are required; brand, model and comment are optional.
- Normalize phone entry to +7 and format it in all feedback forms. Verify typing, pasted numbers, deletion and incomplete input.
- Save requests before reporting success. Add protected admin table, status changes, date filtering, request counts and frequent missing brands/models. Mark staging submissions as tests; preserve disabled staging mail.
- Verify optional fields, repeated submissions, error handling, keyboard focus and mobile layout.

## Delivery

- Back up staging database and changed code before deployment.
- Run PHP/JavaScript checks and reversible integration tests. Test actual public submission and inspect its admin row.
- Review the changes, verify production files stayed unchanged, then commit and push the existing migration branch.

## Verification and result — 2026-09-29

Deployed to the existing Beget WordPress staging site after backing up its database and
changed code. Production file hashes remained unchanged.

- `test-http.php`: all 124 catalog routes passed.
- `test-admin-catalog.php`: counts, directory selections, weights and XLSX compatibility passed.
- `test-missing-requests.php`: request storage and analytics checks passed.
- `test-workbook-security.php`: unsafe workbook cases passed.
- `test-phone.mjs`: all 6 phone-entry tests passed.
- A synthetic missing-device request submitted through the real browser was saved with
  optional brand/model/comment fields left empty.
- Browser checks passed at 320×740, 390×844 and 768×1024 without horizontal overflow.
  Escape closed the dialog and returned focus to its trigger. The catalog layout was also
  reviewed in a 1024px-wide screenshot. At 1440px, DOM checks found no horizontal overflow;
  the screenshot timed out, so that size has no completed screenshot review.
- All 12 deployed source files matched the local files by SHA-256. Production hashes were
  checked again and remained unchanged.
- Read-only rendering of `Admin::page` on Beget confirmed the brands table shows 117
  actual models, all 117 currently drafts, with 0 published. The new-device screen renders
  existing category/brand selects, correct category relations for brand filtering and the
  model-weight field. Brand editing renders existing category checkboxes, preserves the
  selected relations, provides a separate weight per category and keeps the slug read-only.
  Catalog data and revision were unchanged by these checks; the private server helper was removed.

Authenticated admin browser review is pending the current WordPress password. Server-side
admin rendering and catalog checks passed; they do not replace an authenticated browser
review of the admin screens.
