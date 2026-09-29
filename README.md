# 10113

This branch contains the working WordPress migration of Service 101, based on production
commit `a41a4a5`, with the existing design preserved. WordPress runs on a separate Beget
[staging site](https://wp-stage.xn--101-eddot8cge.xn--p1ai/).
Public viewing needs no password; the [admin area](https://wp-stage.xn--101-eddot8cge.xn--p1ai/wp-admin/)
requires a WordPress account. Credentials are kept outside Git. Production remains on the
existing static site.

For WordPress, the database is the catalog source. Edit devices, categories, brands and
prices through **Каталог** in the admin area; use its XLSX export/import for bulk changes.
Imports are checked before applying, and existing device addresses remain stable.
Editing `data/services.csv` does not update WordPress.

- [Editor guide](docs/wordpress-editor-guide.md): admin editing, ordering and missing-device requests.
- [Excel import contract](docs/excel-import-contract.md): workbook fields, validation and update rules.
- [WordPress setup and deployment](wordpress/README.md): theme build, Beget runtime and staging controls.
- [Migration plan](docs/wordpress-migration.md): rationale, architecture and acceptance criteria.

## WordPress checks

Run the JavaScript test from the repository root. Run PHP integration checks against the
isolated staging installation, using its actual path and an administrator login:

```sh
node wordpress/tools/test-phone.mjs
wp --path=/path/to/staging eval-file wordpress/tools/test-admin-catalog.php --user=ADMIN_LOGIN
wp --path=/path/to/staging eval-file wordpress/tools/test-missing-requests.php --user=ADMIN_LOGIN
wp --path=/path/to/staging eval-file wordpress/tools/test-workbook-security.php --user=ADMIN_LOGIN
wp --path=/path/to/staging eval-file wordpress/tools/test-http.php --user=ADMIN_LOGIN
```

On Beget, replace `wp` with `/usr/local/bin/php8.3 /usr/local/bin/wp-cli.phar`; the default
wrapper selects an unsuitable PHP version. Take a staging snapshot and coordinate with
editors before running tests that create temporary fixtures.

## Static site reference

The remaining instructions describe the original static implementation. Its CSV and
`tools/build-production.mjs` are reference/legacy tooling, not the WordPress data or
deployment workflow. For WordPress assets, use `node wordpress/tools/build-theme.mjs`
and follow the WordPress deployment guide linked above.

Service 101 concept with a blue navigation header, responsive repair catalog, B2B page,
stable service counters, booking forms, an on-site technician request flow, payment methods,
category-specific repair guidance, repair status widget and review platform summaries.

### Update static-site prices

`data/services.csv` is the catalog source used by the static implementation. Keep the header names
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

### Static-site form recipient

Production forms are sent by `api/send-request.php` through the hosting server. Change the
`recipient` value in `api/mail-config.php` to update the destination for every form. The
current recipient is `101kms@mail.ru`. The configuration file cannot be opened over HTTP.

### Static production build

The temporary production build intentionally excludes the unfinished device catalog and
`data/services.csv`. Requests to `/remont/` are redirected to the contact form with a
temporary HTTP 302 response.

```bash
node tools/build-production.mjs
```

The deployable files are written to `.production-build/`. Do not switch the production
domain before the PHP handler and delivery to the recipient mailbox have been tested on an
isolated Beget site or technical subdomain.

### Verify the static site locally

```bash
node tools/local-server.mjs 8084
node tools/verify-browser.mjs http://127.0.0.1:8084
```
