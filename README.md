# ACF Revisions

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/acf-revisions)](https://wordpress.org/plugins/acf-revisions/)
[![Plugin Check](https://github.com/codetot-web/acf-revisions/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/codetot-web/acf-revisions/actions/workflows/plugin-check.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

Bridge hooks that save ACF Flexible Content field values and reference keys into WordPress post revisions, enabling recovery after accidental data loss.

## The Problem

ACF Flexible Content fields store data using dynamic post meta keys:

| Layer | Example | Purpose |
|-------|---------|---------|
| Field values | `sections_0_title`, `sections_1_image` | Actual content |
| Ref keys | `_sections_0_title`, `_sections_1_image` | Map field → field definition |
| Group ref | `_sections` | Field group reference |

WordPress core revisions (even 6.4+) cannot track these dynamic keys. When ACF field groups are accidentally corrupted or meta keys are deleted during maintenance, **all flexible content data becomes unrecoverable** — even though the values still exist in the database.

## The Solution

This plugin hooks into WordPress's revision system to copy ACF Flexible Content meta keys to/from revision posts:

- On **revision creation** (`_wp_put_post_revision`) — copies all `sections_%` and `_sections_%` meta
- On **revision restore** (`wp_restore_post_revision`) — restores all meta and clears ACF cache
- On **field group import** (`acf/import_field_group`) — snapshots the current state for recovery

## Features

- **Automatic** — No configuration needed. Works on every save and revision restore.
- **Integrity Check** — Detect orphaned meta keys and missing reference keys via WP-CLI or admin UI.
- **Import Safety** — Automatic snapshots before `acf/import_field_group()` modifications.
- **Clean** — Only affects `sections_%` and `_sections_%` meta keys. No custom tables.

## Requirements

- WordPress 6.4+
- ACF Pro 6.0+
- PHP 7.4+

## Installation

1. Upload `acf-revisions` to `/wp-content/plugins/`
2. Activate via Plugins screen
3. Requires ACF Pro to be active

## WP-CLI Commands

```bash
# Run integrity check on all pages
wp acf-revisions check

# Check and auto-fix issues
wp acf-revisions check --fix

# Check a specific post
wp acf-revisions check --post_id=123

# List field group snapshots
wp acf-revisions snapshots

# Test the bridge hooks
wp acf-revisions test-bridge 123
```

## Filters

```php
// Customize monitored post types (default: page, post)
add_filter( 'acf_revisions_post_types', function( $types ) {
    return array( 'page', 'post', 'my_cpt' );
} );

// Customize field group key (default: group_69577fd380786)
add_filter( 'acf_revisions_field_group_key', function( $key ) {
    return 'group_my_custom_key';
} );
```

## Changelog

### 1.0.0
- Initial release
- Bridge hooks for ACF flexible content meta revisioning
- Field group import snapshots
- WP-CLI commands: check, snapshots, test-bridge
- Admin tools page with integrity check
- Uninstall cleanup

## Author

- **Author:** CODE TOT JSC ([codetot.com](https://codetot.com))
- **Co-Author:** Khôi Nguyễn ([github.com/khoipro](https://github.com/khoipro))

## License

GPL v2 or later
