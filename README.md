# 10113

Service 101 concept with a blue navigation header, responsive repair catalog, B2B page,
stable service counters, booking forms, an on-site technician request flow, payment methods,
category-specific repair guidance, repair status widget and review platform summaries.

## Update prices

`data/services.csv` is the only catalog source used by the website. Keep the header names
unchanged. For predictable Excel editing, save it as `CSV UTF-8 (Comma delimited) (*.csv)`.
The browser loader also accepts semicolon, comma or tab delimiters and UTF-8, Windows-1251 or
UTF-16 encoding. Edited and newly added rows are loaded on the next page visit with browser
caching disabled.

Excel workflow:

1. Open the existing `data/services.csv` instead of creating a new workbook.
2. Keep all 17 column names in the first row unchanged.
3. Use **Save As -> CSV UTF-8 (Comma delimited) (*.csv)** and keep the filename `services.csv`.
4. For a new device, fill `category_slug`, `brand_slug`, `model_slug` and `page_url`, then run
   the generator below so its indexable device page is created.

To regenerate SEO device pages from the current CSV without changing the CSV file, run:

```bash
node tools/generate-pages.mjs
```

Do not open catalog pages directly through `file://`: browsers block loading the local CSV.
Use the local HTTP server below.

## Form recipient

All website forms use the FormSubmit table email template. Change `formRecipient` in
`data/site-config.json` to redirect every form to another email address.

## Verify locally

```bash
node tools/local-server.mjs 8084
node tools/verify-browser.mjs http://127.0.0.1:8084
```
