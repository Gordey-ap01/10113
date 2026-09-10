# ADR-001: WordPress is the catalogue editor; Excel is the bulk channel

## Status

Accepted — 10 September 2026

## Context

The repair catalogue has devices, categories, brands, services and prices. It must keep the present public design and URLs while allowing staff to add ordinary entries without editing templates. Excel is useful when many prices arrive at once, but it is unsafe as the only editor: it cannot show relationships, media and publication state as clearly as WordPress.

## Decision

Use WordPress taxonomy terms as the authoritative directories for device categories and brands. Their metadata stores display settings that belong to the category: order and the catalogue text. Devices keep their stable code, path and price rows in the existing catalogue tables. The WordPress admin is the default workflow for single changes. Excel export/import remains the transaction-checked bulk workflow and uses the same device validation and stable identifiers.

New categories and brands are created in the WordPress catalogue menu first. A new device then selects that directory entry; its URL is generated from immutable category, brand and model slugs. Existing URLs are never silently changed.

## Consequences

The public catalogue discovers visible categories from the WordPress directory and available devices, so a new category does not require a template deployment. Editors can update category-specific text without touching code. A spreadsheet import remains useful for a supplier price list or dozens of edits, while day-to-day changes are faster in the admin interface.

## Sources

WordPress documents taxonomies as a native way to group post types and provides term metadata for additional term-specific fields: https://developer.wordpress.org/reference/functions/register_taxonomy/ and https://developer.wordpress.org/reference/functions/register_term_meta/.
