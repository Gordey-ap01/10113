# WordPress migration

Isolated worktree: `feature/wordpress-migration`, based on live source commit `a41a4a5`. The legacy build remains the reference and is not deployed to the WordPress folder.

## Components

- `wp-content/themes/service101`: original presentation with server-rendered catalog pages.
- `wp-content/plugins/service101-catalog`: devices, service prices, admin editing, XLSX preview/apply/export/history/rollback, preserved lead forms.
- `tools/build-theme.mjs`: copies original assets/CSS and extracts the reference HTML. Run again only when deliberately updating the reference design. It does not replace page content in the WordPress database.
- `tools/seed-pages.php`: initial page setup, run as an administrator; previously seeded pages are preserved.
- Composer dependencies and runtime/media/secrets are excluded from Git.

## Build and staging deployment

Requirements: Node.js, PHP 8.3+, Composer 2, WordPress, MySQL with InnoDB, and the PHP extensions required by PhpSpreadsheet. The tested host has PHP 8.3.20 and MySQL 8.4.8; installed WordPress is 7.1 ru_RU.

```sh
node wordpress/tools/build-theme.mjs
cd wordpress/wp-content/plugins/service101-catalog
composer install --no-dev --prefer-dist --optimize-autoloader
```

Copy the theme and plugin into an isolated WordPress installation. Include the 6 files from `assets/catalog-originals/` in the plugin's `originals/` folder for the initial import. Activate the plugin, then seed pages with `wp eval-file wordpress/tools/seed-pages.php --user=<administrator>`. The seed script deliberately accepts staging only.

On Beget, the default `php` is unsuitable and the `wp` shell wrapper selects another PHP version. Use `/usr/local/bin/php8.3 /usr/local/bin/wp-cli.phar` explicitly. Select PHP 8.3 for the actual web domain separately. Requests to `127.0.0.1:80` do not necessarily use the same PHP version as the public frontend.

Current staging: `wp-stage.сервис101.рф`, folder `/home/b/b9141846/service101-wp-stage/public_html`, separate database. HTTPS was requested through Beget and is still pending. No production files or database were replaced. Access credentials are kept outside Git.

In Beget, explicitly select `wp-stage.сервис101.рф` in the additional-subdomains field of the parent domain's SSL page. Opening an SSL page with the subdomain in its heading was insufficient: the first request renewed only the parent and `www`. Verify the served certificate SANs and the staging HTTPS response, not just the panel's request-success message.

Set `WP_ENVIRONMENT_TYPE=staging`, `blog_public=0`, `DISALLOW_FILE_EDIT=true`, private temp/log paths, and install `deploy/staging-safety.php` as a must-use plugin. HTTP Basic protection belongs to the staging host only. On Beget its password file must be inside the site's isolated directory but outside `public_html`. The ACME challenge path may be exempted while every other URL stays protected.

## Validation

The integration scripts require the isolated seeded catalog and a staging administrator. They are not production utilities.

- `tools/test-catalog.php`: initial/repeated imports, exact amounts, invalid rows, partial updates, stale preview, rollback and XLSX roundtrip.
- `tools/test-lifecycle.php`: case-insensitive code collisions, new model with copied service, publication, image clearing, hiding services, permissions and unsafe image URLs. Temporary changes are restored.
- `tools/test-workbook-security.php`: actual XLSX fixtures containing a formula, macro entry, external link or embedded file must be rejected before any catalog change.
- `tools/test-http.php`: all 124 current catalog routes with administrator and visitor access, source HTML prices, home/B2B forms and old URL redirects. It uses an ephemeral local QA session and private host credentials, which are never part of a deployment.
- Browser: desktop/mobile layout, selecting multiple services, modal and simulated request. HTTP admin upload and export were tested against the actual Beget PHP 8.3 frontend.

Do not run rollback-capable tests after editors begin changing the staging catalog without first taking a snapshot and coordinating the test window.

## Production cutover

Not performed. Before a later cutover, review prices/content and mobile behavior with the owner, finish HTTPS and map verification, back up the production files and staging database, replace staging URLs using WordPress serialization-aware tooling, enable real mail and indexing intentionally, and verify published URLs/sitemap and all forms. Keep the old static directory and database snapshot for rollback. Remove all temporary QA scripts and sessions before opening the site publicly.

Ordinary home/B2B content currently uses preserved HTML in WordPress pages. A dedicated settings screen and expanded SEO editing are follow-up work, not features claimed by this migration build.
