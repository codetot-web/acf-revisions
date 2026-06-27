# Changelog

All notable changes to ACF Revisions are documented in this file.

## [1.0.0] — 2026-06-27

### Added
- **Bridge Hook 1:** `_wp_put_post_revision` — Copy all `sections_%` and `_sections_%` meta from parent post to revision when a revision is created.
- **Bridge Hook 2:** `wp_restore_post_revision` — Restore all ACF flexible content meta from revision to parent when a revision is restored. Automatically clears ACF field cache.
- **Bridge Hook 3:** `acf/import_field_group` — Snapshot the current field group state before import/modification, with up to 10 historical snapshots stored in options.
- **Bridge Hook 4:** `updated_post_meta` — Track direct meta updates to ACF section fields (for WP-CLI and bulk edit scenarios).
- **Bridge Hook 5:** `acf/save_post` — Pre-save snapshot for diff/rollback comparison.
- **Integrity Check:** Detect orphaned meta keys, missing `_sections` reference keys, and data inconsistencies. Available via WP-CLI and admin UI.
- **WP-CLI Commands:**
  - `wp acf-revisions check` — Full integrity check with optional `--fix` and `--verbose`
  - `wp acf-revisions snapshots` — List field group import snapshots
  - `wp acf-revisions test-bridge` — Test bridge hooks on a specific post
- **Admin Tools Page:** Tools → ACF Revisions with one-click integrity check and auto-fix.
- **Uninstall Cleanup:** Removes all plugin data (snapshots, transients, per-post meta).
- **Activation Guard:** Verifies ACF Pro 6.0+ is active; deactivates with clear error if not.
