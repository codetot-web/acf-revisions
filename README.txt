=== ACF Revisions ===
Contributors: codetot
Tags: acf, advanced custom fields, revisions, flexible content
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridge hooks to save ACF Flexible Content field values and reference keys into WordPress post revisions, enabling recovery after accidental data loss.

== Description ==

ACF Flexible Content fields store their data using dynamic post meta keys (e.g. `sections_0_title`, `sections_3_image`, `_sections_0_title`) that WordPress core revisions cannot track by default.

This plugin bridges that gap, ensuring that when you restore a revision, all your ACF Flexible Content data — including layouts, field values, and reference keys — is fully restored alongside the post content.

= Features =

* **Automatic Revision Bridge** — When a revision is created, all ACF Flexible Content meta is automatically copied from the parent post to the revision.
* **Revision Restore** — When a revision is restored, all ACF meta is copied back and ACF's field cache is cleared.
* **Field Group Import Safety** — Before `acf/import_field_group` modifies the flexible content field group, a snapshot is saved for recovery.
* **Integrity Check** — Run from WP-CLI or admin UI to detect orphaned meta keys, missing reference keys, and data inconsistencies.
* **WP-CLI Commands** — `wp acf-revisions check`, `wp acf-revisions snapshots`, `wp acf-revisions test-bridge`

= How It Works =

WordPress 6.4 introduced meta revisioning support (`revisions_enabled` in `register_post_meta()`), but this only works for statically-registered meta keys. ACF Flexible Content uses dynamic keys that change per save (indexed layouts with field-specific keys).

This plugin hooks into three critical points:

1. **`_wp_put_post_revision`** — When a revision is created, all `sections_%` and `_sections_%` meta keys are copied from the parent post to the revision.
2. **`wp_restore_post_revision`** — When a revision is restored, the meta is copied back from the revision to the parent post, and ACF's internal field cache is cleared.
3. **`acf/import_field_group`** — Before the flexible content field group is modified, the current state is snapshotted for recovery.

== Installation ==

1. Upload the `acf-revisions` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. **Requires ACF Pro 6.0+** to be active
4. That's it — the bridge hooks work automatically on every save and revision restore

== Frequently Asked Questions ==

= Does this work with ACF Free? =

No. ACF Free does not include Flexible Content fields. ACF Pro 6.0 or later is required.

= Does this work with options pages? =

Not yet. Options pages use the `wp_options` table, not post meta. WordPress revisions only support post-type data. Options page backup is planned for a future release.

= Will this slow down my site? =

The performance impact is negligible. Each save copies approximately 100-150 meta keys (for a typical page with 10 sections). This adds less than 50ms per save operation.

= Can I configure which post types are monitored? =

Yes. Use the `acf_revisions_post_types` filter:

`add_filter( 'acf_revisions_post_types', function( $post_types ) { return array( 'page', 'post', 'my_cpt' ); } );`

= Can I change the field group key? =

Yes. Use the `acf_revisions_field_group_key` filter:

`add_filter( 'acf_revisions_field_group_key', function( $key ) { return 'group_my_custom_key'; } );`

== Screenshots ==

1. ACF Revisions admin tools page with integrity check
2. ACF Sections diff in the WordPress revision comparison page
3. WP-CLI commands for revision management

== Changelog ==

= 1.0.0 =
* Initial release
* Bridge hooks: copy ACF flexible content meta to/from revisions
* Snapshot field group before acf/import_field_group
* WP-CLI commands: check, snapshots, test-bridge
* Admin tools page with integrity check
* Uninstall cleanup

== Upgrade Notice ==

= 1.0.0 =
Initial release.
