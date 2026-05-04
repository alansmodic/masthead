<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap masthead-dashboard">
	<h1>
		<span class="masthead-logo">✍️</span>
		<?php esc_html_e( 'Masthead', 'masthead' ); ?>
		<span class="masthead-version">v<?php echo esc_html( MASTHEAD_VERSION ); ?></span>
		<button type="button" class="button" id="masthead-check-updates" style="margin-left:12px;font-size:12px;">
			<?php esc_html_e( 'Check for updates', 'masthead' ); ?>
		</button>
	</h1>

	<?php $update_status = Masthead_GitHub_Updater::get_instance()->get_update_status(); ?>

	<div class="masthead-suite-grid">

		<?php foreach ( $modules as $id => $module ) : ?>
		<div class="masthead-module-card <?php echo $module['active'] ? 'is-active' : ( $module['installed'] ? 'is-installed' : 'is-missing' ); ?>" data-module="<?php echo esc_attr( $id ); ?>">
			<div class="masthead-module-header">
				<h2><?php echo esc_html( $module['label'] ); ?></h2>
				<div class="masthead-module-badges">
				<?php if ( $module['active'] ) : ?>
					<span class="masthead-badge masthead-badge-active">Active</span>
					<?php if ( ! empty( $update_status[ $id ]['has_update'] ) ) : ?>
						<span class="masthead-badge masthead-badge-update">
							↑ <?php echo esc_html( $update_status[ $id ]['remote'] ); ?> available
						</span>
					<?php endif; ?>
				<?php elseif ( $module['installed'] ) : ?>
					<span class="masthead-badge masthead-badge-installed">Installed</span>
				<?php else : ?>
					<span class="masthead-badge masthead-badge-missing">Not installed</span>
				<?php endif; ?>
				</div>
			</div>
			<p class="masthead-module-desc"><?php echo esc_html( $module['description'] ); ?></p>
			<div class="masthead-module-footer">
				<?php if ( $module['active'] ) : ?>
					<span class="masthead-module-ok">✓ Active</span>
				<?php elseif ( $module['installed'] ) : ?>
					<button type="button" class="button button-small masthead-activate-btn" data-module="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Activate', 'masthead' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-primary button-small masthead-install-btn" data-module="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Install', 'masthead' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>

	</div>

	<?php if ( $this->settings->is_feature_enabled( 'staged_revisions' ) && class_exists( 'Masthead_Staged_Revisions' ) ) :
		$pending = Masthead_Staged_Revisions::get_recent( 10 );
		if ( ! empty( $pending ) ) : ?>
	<div class="masthead-card masthead-card-queue">
		<h2><?php esc_html_e( 'Revision Queue', 'masthead' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post', 'masthead' ); ?></th>
					<th><?php esc_html_e( 'Author', 'masthead' ); ?></th>
					<th><?php esc_html_e( 'Status', 'masthead' ); ?></th>
					<th><?php esc_html_e( 'Submitted', 'masthead' ); ?></th>
					<?php if ( get_post_meta( $pending[0]->ID, '_masthead_revision_summary', true ) ) : ?>
					<th><?php esc_html_e( 'AI Summary', 'masthead' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pending as $item ) :
					$author  = get_userdata( $item->staged_author_id );
					$summary = get_post_meta( $item->ID, '_masthead_revision_summary', true );
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $item->post_parent ) ); ?>"><?php echo esc_html( $item->post_title ); ?></a></td>
					<td><?php echo esc_html( $author ? $author->display_name : '—' ); ?></td>
					<td><span class="masthead-status masthead-status-<?php echo esc_attr( $item->staged_status ); ?>"><?php echo esc_html( ucfirst( $item->staged_status ) ); ?></span></td>
					<td><?php echo esc_html( human_time_diff( strtotime( $item->post_modified ), time() ) . ' ago' ); ?></td>
					<?php if ( $summary ) : ?>
					<td class="masthead-ai-summary"><?php echo esc_html( $summary ); ?></td>
					<?php endif; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=masthead-staged' ) ); ?>"><?php esc_html_e( 'View all staged revisions →', 'masthead' ); ?></a></p>
	</div>
	<?php endif; endif; ?>

</div>

<style>
.masthead-dashboard h1 { display: flex; align-items: center; gap: 8px; }
.masthead-logo { font-size: 24px; }
.masthead-version { font-size: 13px; color: #999; font-weight: 400; margin-left: 4px; }
.masthead-suite-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin: 24px 0; }
.masthead-module-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px; border-top: 3px solid #ddd; }
.masthead-module-card.is-active { border-top-color: #2271b1; }
.masthead-module-card.is-missing { opacity: .7; }
.masthead-module-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.masthead-module-header h2 { margin: 0; font-size: 15px; }
.masthead-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.masthead-badge-active { background: #d7f0d7; color: #1a7a1a; }
.masthead-badge-installed { background: #fff3cd; color: #856404; }
.masthead-badge-missing { background: #f0f0f0; color: #666; }
.masthead-module-desc { color: #666; font-size: 13px; margin: 0 0 12px; }
.masthead-module-footer { font-size: 13px; }
.masthead-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px; margin-bottom: 16px; }
.masthead-card h2 { margin-top: 0; }
.masthead-status { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; }
.masthead-status-pending { background: #fff3cd; color: #856404; }
.masthead-status-approved { background: #d7f0d7; color: #1a7a1a; }
.masthead-status-rejected { background: #fde8e8; color: #9e1c1c; }
.masthead-status-scheduled { background: #e8f4fd; color: #0e6fb5; }
.masthead-ai-summary { font-size: 12px; color: #555; max-width: 300px; }
.masthead-module-ok { color: #1a7a1a; font-weight: 600; font-size: 13px; }
.masthead-btn-error { background: #fde8e8 !important; color: #9e1c1c !important; border-color: #f5a0a0 !important; }
.masthead-module-badges { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; }
.masthead-badge-update { background: #fff3cd; color: #856404; cursor: pointer; }
</style>

<script>
jQuery(function($) {
	// Check for updates.
	$('#masthead-check-updates').on('click', function() {
		const $btn = $(this);
		$btn.text('Checking…').prop('disabled', true);
		$.post(ajaxurl, { action: 'masthead_check_updates', nonce: mastheadAdmin.nonce })
			.done(function(res) {
				if (res.success) {
					let found = 0;
					$.each(res.data.modules, function(id, info) {
						if (info.update) {
							found++;
							$(`.masthead-module-card[data-module="${id}"] .masthead-module-badges`).append(
								`<span class="masthead-badge masthead-badge-update">↑ ${info.remote} available</span>`
							);
						}
					});
					$btn.text(found > 0 ? `${found} update(s) found` : 'Up to date').prop('disabled', false);
				}
			})
			.fail(function() { $btn.text('Check failed').prop('disabled', false); });
	});

	$(document).on('click', '.masthead-install-btn', function() {
		const $btn = $(this);
		const module = $btn.data('module');
		$btn.text('Installing…').prop('disabled', true);
		$.post(ajaxurl, { action: 'masthead_install_module', nonce: mastheadAdmin.nonce, module })
			.done(function(res) {
				if (res.success) {
					$btn.replaceWith(`<button type="button" class="button button-small masthead-activate-btn" data-module="${module}">Activate</button>`);
				} else {
					$btn.text('Failed').addClass('masthead-btn-error').prop('disabled', false);
				}
			});
	});

	$(document).on('click', '.masthead-activate-btn', function() {
		const $btn = $(this);
		const module = $btn.data('module');
		$btn.text('Activating…').prop('disabled', true);
		$.post(ajaxurl, { action: 'masthead_activate_module', nonce: mastheadAdmin.nonce, module })
			.done(function(res) {
				if (res.success) {
					$btn.replaceWith('<span class="masthead-module-ok">✓ Active</span>');
					$btn.closest('.masthead-module-card').removeClass('is-missing is-installed').addClass('is-active');
				} else {
					$btn.text('Failed').addClass('masthead-btn-error').prop('disabled', false);
				}
			});
	});
});
</script>
