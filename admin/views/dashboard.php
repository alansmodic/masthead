<?php
/**
 * Dashboard view for Editorial.io
 *
 * @package EditorialIO
 * @var array $enabled_features
 * @var array $available_features
 * @var array $recent_activity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$feature_status = Editorial_IO_Admin::get_instance()->get_feature_status_summary();
?>

<div class="wrap editorial-io-dashboard">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Editorial.io Dashboard', 'editorial-io' ); ?>
	</h1>

	<div class="editorial-io-dashboard-grid">
		<!-- Feature Status Overview -->
		<div class="editorial-io-card">
			<h2><?php esc_html_e( 'Feature Status', 'editorial-io' ); ?></h2>
			<div class="editorial-io-stats-row">
				<div class="stat-item">
					<div class="stat-number"><?php echo esc_html( $feature_status['enabled'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Enabled', 'editorial-io' ); ?></div>
				</div>
				<div class="stat-item">
					<div class="stat-number"><?php echo esc_html( $feature_status['disabled'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Disabled', 'editorial-io' ); ?></div>
				</div>
				<div class="stat-item">
					<div class="stat-number"><?php echo esc_html( $feature_status['total'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Features', 'editorial-io' ); ?></div>
				</div>
			</div>

			<div class="feature-status-list">
				<?php foreach ( $enabled_features as $key => $is_enabled ) : ?>
					<?php $feature = $available_features[ $key ] ?? array(); ?>
					<div class="feature-status-item <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>">
						<span class="status-indicator"></span>
						<span class="feature-name"><?php echo esc_html( $feature['label'] ?? $key ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="dashboard-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=editorial-io-settings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Manage Features', 'editorial-io' ); ?>
				</a>
			</p>
		</div>

		<!-- Recent Activity -->
		<div class="editorial-io-card editorial-io-card-wide">
			<h2><?php esc_html_e( 'Recent Activity', 'editorial-io' ); ?></h2>
			<?php if ( empty( $recent_activity ) ) : ?>
				<p class="no-activity"><?php esc_html_e( 'No recent activity found.', 'editorial-io' ); ?></p>
			<?php else : ?>
				<div class="activity-timeline">
					<?php foreach ( $recent_activity as $activity ) : ?>
						<div class="activity-item activity-type-<?php echo esc_attr( $activity['type'] ); ?>">
							<div class="activity-avatar">
								<?php if ( $activity['author'] ) : ?>
									<img src="<?php echo esc_url( get_avatar_url( $activity['author']->ID, array( 'size' => 32 ) ) ); ?>" 
										 alt="<?php echo esc_attr( $activity['author']->display_name ); ?>" 
										 class="avatar">
								<?php endif; ?>
							</div>
							<div class="activity-content">
								<div class="activity-meta">
									<strong><?php echo esc_html( $activity['author']->display_name ?? __( 'Unknown', 'editorial-io' ) ); ?></strong>
									<span class="activity-type-label">
										<?php
										switch ( $activity['type'] ) {
											case 'staged_revision':
												/* translators: %s: post title */
												printf( esc_html__( 'created staged revision for %s', 'editorial-io' ), '<em>' . esc_html( $activity['title'] ) . '</em>' );
												break;
											case 'revision':
												$change_labels = array(
													'title'   => __( 'title', 'editorial-io' ),
													'content' => __( 'content', 'editorial-io' ),
													'excerpt' => __( 'excerpt', 'editorial-io' ),
												);
												$changes = array_intersect_key( $change_labels, array_flip( $activity['changes'] ?? array() ) );
												/* translators: %1$s: post title, %2$s: list of changes */
												printf( 
													esc_html__( 'updated %1$s (%2$s)', 'editorial-io' ), 
													'<em>' . esc_html( $activity['title'] ) . '</em>',
													esc_html( implode( ', ', $changes ) )
												);
												break;
										}
										?>
									</span>
									<span class="activity-status">
										<?php if ( 'staged_revision' === $activity['type'] ) : ?>
											<span class="status-badge status-<?php echo esc_attr( $activity['status'] ); ?>">
												<?php echo esc_html( ucfirst( $activity['status'] ) ); ?>
											</span>
										<?php endif; ?>
									</span>
								</div>
								<div class="activity-timestamp">
									<?php echo esc_html( Editorial_IO_Admin::format_admin_date( $activity['date'] ) ); ?>
								</div>
								<div class="activity-actions">
									<a href="<?php echo esc_url( get_edit_post_link( $activity['post_id'] ) ); ?>" class="activity-link">
										<?php esc_html_e( 'Edit', 'editorial-io' ); ?>
									</a>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $enabled_features['revision_timeline'] ) && $enabled_features['revision_timeline'] ) : ?>
				<p class="dashboard-action">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=editorial-io-activity' ) ); ?>" class="button">
						<?php esc_html_e( 'View All Activity', 'editorial-io' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<!-- Quick Stats -->
		<?php if ( isset( $enabled_features['staged_revisions'] ) && $enabled_features['staged_revisions'] ) : ?>
		<div class="editorial-io-card">
			<h2><?php esc_html_e( 'Staged Revisions', 'editorial-io' ); ?></h2>
			<div id="staged-revisions-stats" class="loading">
				<p><?php esc_html_e( 'Loading...', 'editorial-io' ); ?></p>
			</div>
			<p class="dashboard-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=editorial-io-staged' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Staged Revisions', 'editorial-io' ); ?>
				</a>
			</p>
		</div>
		<?php endif; ?>

		<!-- System Info -->
		<div class="editorial-io-card">
			<h2><?php esc_html_e( 'System Information', 'editorial-io' ); ?></h2>
			<div class="system-info">
				<div class="info-row">
					<span class="info-label"><?php esc_html_e( 'Plugin Version:', 'editorial-io' ); ?></span>
					<span class="info-value"><?php echo esc_html( EDITORIAL_IO_VERSION ); ?></span>
				</div>
				<div class="info-row">
					<span class="info-label"><?php esc_html_e( 'WordPress Version:', 'editorial-io' ); ?></span>
					<span class="info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
				</div>
				<div class="info-row">
					<span class="info-label"><?php esc_html_e( 'Supported Post Types:', 'editorial-io' ); ?></span>
					<span class="info-value"><?php echo esc_html( implode( ', ', Editorial_IO::get_supported_post_types() ) ); ?></span>
				</div>
			</div>
		</div>
	</div>
</div>

<style>
.editorial-io-dashboard {
	max-width: 1200px;
}

.editorial-io-dashboard-grid {
	display: grid;
	grid-template-columns: 1fr 2fr;
	grid-gap: 20px;
	margin-top: 20px;
}

.editorial-io-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 20px;
	box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.editorial-io-card-wide {
	grid-column: span 2;
}

.editorial-io-card h2 {
	margin: 0 0 15px 0;
	font-size: 18px;
}

.editorial-io-stats-row {
	display: flex;
	justify-content: space-between;
	margin-bottom: 20px;
}

.stat-item {
	text-align: center;
	flex: 1;
}

.stat-number {
	font-size: 32px;
	font-weight: bold;
	color: #2271b1;
	line-height: 1;
}

.stat-label {
	font-size: 12px;
	color: #646970;
	text-transform: uppercase;
	margin-top: 5px;
}

.feature-status-list {
	margin: 15px 0;
}

.feature-status-item {
	display: flex;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid #f0f0f1;
}

.feature-status-item:last-child {
	border-bottom: none;
}

.status-indicator {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-right: 10px;
}

.feature-status-item.enabled .status-indicator {
	background: #00a32a;
}

.feature-status-item.disabled .status-indicator {
	background: #ddd;
}

.feature-name {
	font-weight: 500;
}

.activity-timeline {
	max-height: 400px;
	overflow-y: auto;
}

.activity-item {
	display: flex;
	padding: 15px 0;
	border-bottom: 1px solid #f0f0f1;
}

.activity-item:last-child {
	border-bottom: none;
}

.activity-avatar {
	margin-right: 12px;
	flex-shrink: 0;
}

.activity-avatar .avatar {
	width: 32px;
	height: 32px;
	border-radius: 50%;
}

.activity-content {
	flex: 1;
	min-width: 0;
}

.activity-meta {
	margin-bottom: 5px;
	font-size: 14px;
}

.activity-type-label {
	color: #646970;
}

.activity-timestamp {
	font-size: 12px;
	color: #999;
	margin-bottom: 8px;
}

.activity-actions {
	font-size: 12px;
}

.activity-link {
	color: #2271b1;
	text-decoration: none;
}

.status-badge {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 3px;
	color: #fff;
	text-transform: uppercase;
	font-weight: bold;
	margin-left: 8px;
}

.status-pending {
	background: #f0ad4e;
}

.status-approved {
	background: #5cb85c;
}

.status-rejected {
	background: #d9534f;
}

.status-scheduled {
	background: #5bc0de;
}

.dashboard-action {
	margin: 15px 0 0 0;
	text-align: center;
}

.system-info .info-row {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid #f0f0f1;
}

.system-info .info-row:last-child {
	border-bottom: none;
}

.info-label {
	font-weight: 500;
}

.info-value {
	color: #646970;
}

.no-activity {
	text-align: center;
	color: #646970;
	font-style: italic;
	padding: 40px 0;
}

.loading {
	text-align: center;
	color: #646970;
	font-style: italic;
}

@media (max-width: 782px) {
	.editorial-io-dashboard-grid {
		grid-template-columns: 1fr;
	}
	
	.editorial-io-card-wide {
		grid-column: span 1;
	}
	
	.editorial-io-stats-row {
		flex-direction: column;
		gap: 15px;
	}
}
</style>

<script>
// Load staged revisions stats if feature is enabled
<?php if ( isset( $enabled_features['staged_revisions'] ) && $enabled_features['staged_revisions'] ) : ?>
document.addEventListener('DOMContentLoaded', function() {
	const statsContainer = document.getElementById('staged-revisions-stats');
	
	wp.apiFetch({
		path: 'editorial/v1/staged?per_page=100'
	}).then(data => {
		if (data && Array.isArray(data)) {
			const stats = {
				pending: data.filter(item => item.status === 'pending').length,
				approved: data.filter(item => item.status === 'approved').length,
				scheduled: data.filter(item => item.status === 'scheduled').length,
				total: data.length
			};
			
			statsContainer.innerHTML = `
				<div class="editorial-io-stats-row">
					<div class="stat-item">
						<div class="stat-number">${stats.pending}</div>
						<div class="stat-label"><?php esc_html_e( 'Pending', 'editorial-io' ); ?></div>
					</div>
					<div class="stat-item">
						<div class="stat-number">${stats.approved}</div>
						<div class="stat-label"><?php esc_html_e( 'Approved', 'editorial-io' ); ?></div>
					</div>
					<div class="stat-item">
						<div class="stat-number">${stats.scheduled}</div>
						<div class="stat-label"><?php esc_html_e( 'Scheduled', 'editorial-io' ); ?></div>
					</div>
				</div>
			`;
		}
	}).catch(error => {
		statsContainer.innerHTML = '<p class="error"><?php esc_html_e( 'Failed to load stats.', 'editorial-io' ); ?></p>';
		console.error('Failed to load staged revisions stats:', error);
	});
});
<?php endif; ?>
</script>