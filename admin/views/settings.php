<?php
/**
 * Settings view for Editorial.io
 *
 * @package EditorialIO
 * @var array $enabled_features
 * @var array $available_features
 * @var array $checklist_items
 * @var array $general_options
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap editorial-io-settings">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Editorial.io Settings', 'editorial-io' ); ?>
	</h1>

	<div class="editorial-io-settings-tabs">
		<nav class="nav-tab-wrapper">
			<a href="#features" class="nav-tab nav-tab-active" data-tab="features">
				<?php esc_html_e( 'Features', 'editorial-io' ); ?>
			</a>
			<a href="#checklist" class="nav-tab" data-tab="checklist">
				<?php esc_html_e( 'Publication Checklist', 'editorial-io' ); ?>
			</a>
			<a href="#general" class="nav-tab" data-tab="general">
				<?php esc_html_e( 'General Settings', 'editorial-io' ); ?>
			</a>
		</nav>

		<!-- Features Tab -->
		<div id="features-tab" class="tab-content active">
			<div class="editorial-io-card">
				<h2><?php esc_html_e( 'Feature Management', 'editorial-io' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Enable or disable specific Editorial.io features. Some features depend on others and will be automatically disabled if their dependencies are not met.', 'editorial-io' ); ?>
				</p>

				<form id="features-form">
					<div class="features-grid">
						<?php foreach ( $available_features as $key => $feature ) : ?>
						<div class="feature-item" data-feature="<?php echo esc_attr( $key ); ?>">
							<div class="feature-toggle">
								<label class="toggle-switch">
									<input type="checkbox" 
										   name="features[<?php echo esc_attr( $key ); ?>]" 
										   value="1" 
										   <?php checked( $enabled_features[ $key ] ?? false ); ?>
										   <?php if ( ! empty( $feature['requires'] ) ) : ?>
										   data-requires="<?php echo esc_attr( implode( ',', $feature['requires'] ) ); ?>"
										   <?php endif; ?>
									>
									<span class="toggle-slider"></span>
								</label>
							</div>
							<div class="feature-info">
								<h3 class="feature-title"><?php echo esc_html( $feature['label'] ); ?></h3>
								<p class="feature-description"><?php echo esc_html( $feature['description'] ); ?></p>
								
								<?php if ( ! empty( $feature['requires'] ) ) : ?>
								<div class="feature-dependencies">
									<small>
										<?php 
										/* translators: %s: comma-separated list of required features */
										printf( esc_html__( 'Requires: %s', 'editorial-io' ), 
											esc_html( implode( ', ', array_map( function( $req ) use ( $available_features ) {
												return $available_features[ $req ]['label'] ?? $req;
											}, $feature['requires'] ) ) )
										); 
										?>
									</small>
								</div>
								<?php endif; ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>

					<div class="settings-actions">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Features', 'editorial-io' ); ?>
						</button>
						<button type="button" id="reset-features" class="button button-secondary">
							<?php esc_html_e( 'Reset to Defaults', 'editorial-io' ); ?>
						</button>
						<span class="settings-status"></span>
					</div>
				</form>
			</div>
		</div>

		<!-- Checklist Tab -->
		<div id="checklist-tab" class="tab-content">
			<div class="editorial-io-card">
				<h2><?php esc_html_e( 'Publication Checklist', 'editorial-io' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure the checklist that appears when updating published posts. Required items must be checked before publishing immediately.', 'editorial-io' ); ?>
				</p>

				<form id="checklist-form">
					<div id="checklist-items" class="checklist-editor">
						<?php foreach ( $checklist_items as $index => $item ) : ?>
						<div class="checklist-item" data-index="<?php echo esc_attr( $index ); ?>">
							<span class="checklist-handle dashicons dashicons-menu"></span>
							<input type="text" 
								   class="checklist-label regular-text" 
								   value="<?php echo esc_attr( $item['label'] ); ?>" 
								   placeholder="<?php esc_attr_e( 'Checklist item text...', 'editorial-io' ); ?>">
							<label class="checklist-required">
								<input type="checkbox" <?php checked( $item['required'] ); ?>>
								<?php esc_html_e( 'Required', 'editorial-io' ); ?>
							</label>
							<button type="button" class="button checklist-delete" title="<?php esc_attr_e( 'Delete', 'editorial-io' ); ?>">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</div>
						<?php endforeach; ?>
					</div>

					<p>
						<button type="button" id="add-checklist-item" class="button">
							<span class="dashicons dashicons-plus-alt2"></span>
							<?php esc_html_e( 'Add Item', 'editorial-io' ); ?>
						</button>
					</p>

					<div class="settings-actions">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Checklist', 'editorial-io' ); ?>
						</button>
						<span class="settings-status"></span>
					</div>
				</form>
			</div>
		</div>

		<!-- General Tab -->
		<div id="general-tab" class="tab-content">
			<div class="editorial-io-card">
				<h2><?php esc_html_e( 'General Settings', 'editorial-io' ); ?></h2>

				<form id="general-form">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="timeline-per-page"><?php esc_html_e( 'Revisions Per Page', 'editorial-io' ); ?></label>
							</th>
							<td>
								<input type="number" 
									   id="timeline-per-page" 
									   name="general[timeline_per_page]" 
									   value="<?php echo esc_attr( $general_options['timeline_per_page'] ?? 50 ); ?>" 
									   min="10" 
									   max="100" 
									   class="small-text">
								<p class="description">
									<?php esc_html_e( 'Number of revisions to show per page in the timeline view.', 'editorial-io' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="timeline-show-autosaves"><?php esc_html_e( 'Show Autosaves', 'editorial-io' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" 
										   id="timeline-show-autosaves" 
										   name="general[timeline_show_autosaves]" 
										   value="1" 
										   <?php checked( $general_options['timeline_show_autosaves'] ?? false ); ?>>
									<?php esc_html_e( 'Include autosave revisions in timeline by default', 'editorial-io' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="diff-context-lines"><?php esc_html_e( 'Diff Context Lines', 'editorial-io' ); ?></label>
							</th>
							<td>
								<input type="number" 
									   id="diff-context-lines" 
									   name="general[diff_context_lines]" 
									   value="<?php echo esc_attr( $general_options['diff_context_lines'] ?? 3 ); ?>" 
									   min="1" 
									   max="10" 
									   class="small-text">
								<p class="description">
									<?php esc_html_e( 'Number of context lines to show around changes in diffs.', 'editorial-io' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cleanup-old-revisions"><?php esc_html_e( 'Automatic Cleanup', 'editorial-io' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" 
										   id="cleanup-old-revisions" 
										   name="general[cleanup_old_revisions]" 
										   value="1" 
										   <?php checked( $general_options['cleanup_old_revisions'] ?? false ); ?>>
									<?php esc_html_e( 'Automatically clean up old staged revisions', 'editorial-io' ); ?>
								</label>
							</td>
						</tr>
						<tr class="cleanup-days-row" <?php echo empty( $general_options['cleanup_old_revisions'] ) ? 'style="display: none;"' : ''; ?>>
							<th scope="row">
								<label for="cleanup-days"><?php esc_html_e( 'Cleanup After Days', 'editorial-io' ); ?></label>
							</th>
							<td>
								<input type="number" 
									   id="cleanup-days" 
									   name="general[cleanup_days]" 
									   value="<?php echo esc_attr( $general_options['cleanup_days'] ?? 30 ); ?>" 
									   min="7" 
									   max="365" 
									   class="small-text">
								<p class="description">
									<?php esc_html_e( 'Remove staged revisions older than this many days (only pending/rejected revisions).', 'editorial-io' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<div class="settings-actions">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save General Settings', 'editorial-io' ); ?>
						</button>
						<span class="settings-status"></span>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<style>
.editorial-io-settings {
	max-width: 1200px;
}

.editorial-io-settings-tabs {
	margin-top: 20px;
}

.nav-tab-wrapper {
	margin-bottom: 0;
	border-bottom: 1px solid #c3c4c7;
}

.tab-content {
	display: none;
	padding-top: 20px;
}

.tab-content.active {
	display: block;
}

.editorial-io-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 20px;
	box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.editorial-io-card h2 {
	margin: 0 0 15px 0;
	font-size: 18px;
}

.features-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	grid-gap: 20px;
	margin: 20px 0;
}

.feature-item {
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 20px;
	display: flex;
	align-items: flex-start;
	gap: 15px;
}

.feature-item.disabled {
	opacity: 0.6;
}

.toggle-switch {
	position: relative;
	display: inline-block;
	width: 48px;
	height: 24px;
	flex-shrink: 0;
}

.toggle-switch input {
	opacity: 0;
	width: 0;
	height: 0;
}

.toggle-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: #ccc;
	transition: .4s;
	border-radius: 24px;
}

.toggle-slider:before {
	position: absolute;
	content: "";
	height: 18px;
	width: 18px;
	left: 3px;
	bottom: 3px;
	background-color: white;
	transition: .4s;
	border-radius: 50%;
}

input:checked + .toggle-slider {
	background-color: #2271b1;
}

input:checked + .toggle-slider:before {
	transform: translateX(24px);
}

.feature-info {
	flex: 1;
	min-width: 0;
}

.feature-title {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
}

.feature-description {
	margin: 0 0 8px 0;
	color: #646970;
	font-size: 14px;
	line-height: 1.4;
}

.feature-dependencies {
	color: #d63638;
	font-size: 12px;
}

.checklist-editor {
	max-width: 600px;
	margin: 20px 0;
}

.checklist-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	margin-bottom: 4px;
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
}

.checklist-handle {
	cursor: move;
	color: #999;
}

.checklist-label {
	flex: 1;
}

.checklist-required {
	white-space: nowrap;
	font-size: 12px;
}

.checklist-delete {
	padding: 4px 8px;
	border: none;
	background: transparent;
	color: #d63638;
	cursor: pointer;
}

.checklist-delete:hover {
	background: #f0f0f1;
}

.checklist-delete .dashicons {
	width: 16px;
	height: 16px;
	font-size: 16px;
}

.settings-actions {
	margin-top: 20px;
	padding-top: 20px;
	border-top: 1px solid #f0f0f1;
}

.settings-status {
	margin-left: 10px;
	font-weight: 600;
}

.settings-status.success {
	color: #00a32a;
}

.settings-status.error {
	color: #d63638;
}

.settings-status.warning {
	color: #f0ad4e;
}

#add-checklist-item .dashicons {
	vertical-align: middle;
	margin-top: -2px;
}

.cleanup-days-row {
	transition: opacity 0.3s ease;
}

@media (max-width: 782px) {
	.features-grid {
		grid-template-columns: 1fr;
	}
	
	.feature-item {
		flex-direction: column;
		align-items: stretch;
		text-align: center;
	}
	
	.toggle-switch {
		align-self: center;
		margin-bottom: 10px;
	}
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Tab switching
	document.querySelectorAll('.nav-tab').forEach(tab => {
		tab.addEventListener('click', function(e) {
			e.preventDefault();
			
			// Remove active class from all tabs and content
			document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
			document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
			
			// Add active class to clicked tab
			this.classList.add('nav-tab-active');
			
			// Show corresponding content
			const targetTab = this.getAttribute('data-tab');
			document.getElementById(targetTab + '-tab').classList.add('active');
		});
	});
	
	// Feature dependency handling
	function checkFeatureDependencies() {
		const featureToggles = document.querySelectorAll('input[name^="features["]');
		
		featureToggles.forEach(toggle => {
			const requires = toggle.getAttribute('data-requires');
			if (requires) {
				const requiredFeatures = requires.split(',');
				const featureItem = toggle.closest('.feature-item');
				let allRequiredEnabled = true;
				
				requiredFeatures.forEach(requiredFeature => {
					const requiredToggle = document.querySelector(`input[name="features[${requiredFeature}]"]`);
					if (!requiredToggle || !requiredToggle.checked) {
						allRequiredEnabled = false;
					}
				});
				
				if (!allRequiredEnabled && toggle.checked) {
					toggle.checked = false;
					featureItem.classList.add('disabled');
				} else {
					featureItem.classList.remove('disabled');
				}
			}
		});
	}
	
	// Listen for feature toggle changes
	document.querySelectorAll('input[name^="features["]').forEach(toggle => {
		toggle.addEventListener('change', checkFeatureDependencies);
	});
	
	// Initial dependency check
	checkFeatureDependencies();
	
	// Handle cleanup days visibility
	const cleanupToggle = document.getElementById('cleanup-old-revisions');
	const cleanupDaysRow = document.querySelector('.cleanup-days-row');
	
	if (cleanupToggle) {
		cleanupToggle.addEventListener('change', function() {
			cleanupDaysRow.style.display = this.checked ? '' : 'none';
		});
	}
	
	// Form submissions handled by admin.js
});
</script>