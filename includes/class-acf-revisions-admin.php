<?php
/**
 * Admin UI for ACF Revisions.
 *
 * @package ACF_Revisions
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Admin settings and tools for managing ACF revisions.
 */
class ACFR_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Add admin menu pages.
	 */
	public function add_admin_menu(): void {
		$hook = add_management_page(
			__( 'ACF Revisions', 'acf-revisions' ),
			__( 'ACF Revisions', 'acf-revisions' ),
			'manage_options',
			'acf-revisions',
			array( $this, 'render_admin_page' )
		);

		add_action( "load-{$hook}", array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'acfr_action' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'integrity_check' === $action ) {
			$result = acfr_get_bridge()->integrity_check( array(
				'fix' => isset( $_GET['fix'] ) && '1' === $_GET['fix'],
			) );

			set_transient( 'acfr_result', $result, 60 );
			wp_safe_redirect( remove_query_arg( array( '_wpnonce', 'action', 'fix' ) ) );
			exit;
		}
	}

	/**
	 * Render the admin settings page.
	 */
	public function render_admin_page(): void {
		$result = get_transient( 'acfr_result' );
		delete_transient( 'acfr_result' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ACF Revisions', 'acf-revisions' ); ?></h1>
			<p><?php echo esc_html__( 'Manage ACF Flexible Content field revisioning and integrity.', 'acf-revisions' ); ?></p>

			<?php if ( $result ) : ?>
				<div class="notice notice-<?php echo empty( $result['issues'] ) ? 'success' : 'warning'; ?> is-dismissible">
					<p>
						<strong><?php echo esc_html__( 'Integrity Check Results', 'acf-revisions' ); ?></strong>
					</p>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Posts checked: %d', 'acf-revisions' ), $result['total'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Issues found: %d', 'acf-revisions' ), count( $result['issues'] ) ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Auto-fixed: %d', 'acf-revisions' ), $result['fixed'] ) ); ?></li>
					</ul>
					<?php if ( ! empty( $result['issues'] ) ) : ?>
						<details>
							<summary><?php echo esc_html__( 'Show details', 'acf-revisions' ); ?></summary>
							<ul>
								<?php foreach ( $result['issues'] as $issue ) : ?>
									<li>
										<strong>[<?php echo esc_html( $issue['severity'] ); ?>]</strong>
										<?php echo esc_html( $issue['message'] ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<hr>

			<h2><?php echo esc_html__( 'Tools', 'acf-revisions' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Integrity Check', 'acf-revisions' ); ?></th>
					<td>
						<p><?php echo esc_html__( 'Verify that all pages have the correct ACF flexible content meta structure. Detects orphaned meta keys, missing reference keys, and data inconsistencies.', 'acf-revisions' ); ?></p>
						<?php
						$check_url = wp_nonce_url(
							add_query_arg( array( 'action' => 'integrity_check' ) ),
							'acfr_action'
						);
						$fix_url = wp_nonce_url(
							add_query_arg( array( 'action' => 'integrity_check', 'fix' => '1' ) ),
							'acfr_action'
						);
						?>
						<a href="<?php echo esc_url( $check_url ); ?>" class="button">
							<?php echo esc_html__( 'Run Integrity Check', 'acf-revisions' ); ?>
						</a>
						<a href="<?php echo esc_url( $fix_url ); ?>" class="button button-primary">
							<?php echo esc_html__( 'Check & Auto-Fix', 'acf-revisions' ); ?>
						</a>
					</td>
				</tr>
			</table>

			<hr>

			<h2><?php echo esc_html__( 'How It Works', 'acf-revisions' ); ?></h2>
			<p><?php echo esc_html__( 'ACF Flexible Content fields store their data in dynamic post meta keys like sections_0_title, sections_1_image, _sections_0_title, etc. WordPress core revisions (even in 6.4+) only support meta revisioning for statically-registered keys. This plugin bridges that gap:', 'acf-revisions' ); ?></p>
			<ol>
				<li><?php echo esc_html__( 'When a revision is created, all sections_% and _sections_% meta is copied from the parent post to the revision.', 'acf-revisions' ); ?></li>
				<li><?php echo esc_html__( 'When a revision is restored, the meta is copied back and ACF\'s field cache is cleared.', 'acf-revisions' ); ?></li>
				<li><?php echo esc_html__( 'Before ACF field group imports, the current field group state is snapshotted for recovery.', 'acf-revisions' ); ?></li>
				<li><?php echo esc_html__( 'The integrity check CLI/UI command detects orphaned meta keys and missing references.', 'acf-revisions' ); ?></li>
			</ol>

			<h2><?php echo esc_html__( 'WP-CLI Commands', 'acf-revisions' ); ?></h2>
			<pre><code># Run integrity check
wp acf-revisions check

# Check and auto-fix issues
wp acf-revisions check --fix

# Check a specific post
wp acf-revisions check --post_id=123

# List field group snapshots
wp acf-revisions snapshots

# Test the bridge hooks on a post
wp acf-revisions test-bridge --post_id=123</code></pre>
		</div>
		<?php
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_styles( string $hook ): void {
		if ( 'tools_page_acf-revisions' !== $hook ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', '
			.acf-revisions-tools { margin-top: 20px; }
			.acf-revisions-tools code { display: block; padding: 10px; background: #f0f0f1; }
		' );
	}
}
