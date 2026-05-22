<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap masthead-settings">
	<h1>✍️ <?php esc_html_e( 'Masthead Settings', 'masthead' ); ?></h1>

	<form id="masthead-settings-form">

		<!-- Suite Modules -->
		<div class="masthead-card">
			<h2><?php esc_html_e( 'Suite Modules', 'masthead' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each Masthead module is a standalone plugin. Install and activate them independently, or let Masthead handle it.', 'masthead' ); ?></p>
			<table class="form-table">
				<tbody>
				<?php foreach ( $modules as $id => $module ) : ?>
				<tr>
					<th><?php echo esc_html( $module['label'] ); ?></th>
					<td>
						<?php if ( $module['active'] ) : ?>
							<span class="masthead-badge masthead-badge-active">✓ Active</span>
						<?php elseif ( $module['installed'] ) : ?>
							<span class="masthead-badge masthead-badge-installed">Installed</span>
							<button type="button"
								class="button button-primary button-small masthead-activate-btn"
								data-module="<?php echo esc_attr( $id ); ?>"
							><?php esc_html_e( 'Activate', 'masthead' ); ?></button>
						<?php else : ?>
							<span class="masthead-badge masthead-badge-missing">Not installed</span>
							<button type="button"
								class="button button-primary button-small masthead-install-btn"
								data-module="<?php echo esc_attr( $id ); ?>"
							><?php esc_html_e( 'Install', 'masthead' ); ?></button>
						<?php endif; ?>
						<p class="description"><?php echo esc_html( $module['description'] ); ?></p>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- AI Status -->
		<div class="masthead-card">
			<h2><?php esc_html_e( 'AI Features', 'masthead' ); ?></h2>
			<?php
			$ai_status = Masthead_AI::get_instance()->get_status();
			?>
			<div class="masthead-ai-status">
				<div class="masthead-ai-status-indicator <?php echo $ai_status['available'] ? 'masthead-ai-connected' : 'masthead-ai-disconnected'; ?>">
					<span class="masthead-ai-dot"></span>
					<strong><?php echo esc_html( $ai_status['message'] ); ?></strong>
				</div>
				<?php if ( ! $ai_status['available'] ) : ?>
				<p class="description">
					<?php printf(
						/* translators: %s: URL to Connectors settings */
						__( 'To enable AI features, <a href="%s">configure an AI provider</a> in Settings → Connectors.', 'masthead' ),
						esc_url( admin_url( 'options-general.php?page=connectors' ) )
					); ?>
				</p>
				<?php endif; ?>
			</div>

			<table class="form-table masthead-ai-features-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Revision Summaries', 'masthead' ); ?></th>
						<td>
							<span class="masthead-badge <?php echo $ai_status['available'] ? 'masthead-badge-active' : 'masthead-badge-missing'; ?>">
								<?php echo $ai_status['available'] ? esc_html__( 'Available', 'masthead' ) : esc_html__( 'Needs Provider', 'masthead' ); ?>
							</span>
							<p class="description"><?php esc_html_e( 'Auto-generate plain-English summaries when revisions are submitted.', 'masthead' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Editorial Review', 'masthead' ); ?></th>
						<td>
							<span class="masthead-badge <?php echo $ai_status['available'] ? 'masthead-badge-active' : 'masthead-badge-missing'; ?>">
								<?php echo $ai_status['available'] ? esc_html__( 'Available', 'masthead' ) : esc_html__( 'Needs Provider', 'masthead' ); ?>
							</span>
							<p class="description"><?php esc_html_e( 'AI-powered grammar, style, and tone analysis before publishing.', 'masthead' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Headline Suggestions', 'masthead' ); ?></th>
						<td>
							<span class="masthead-badge <?php echo $ai_status['available'] ? 'masthead-badge-active' : 'masthead-badge-missing'; ?>">
								<?php echo $ai_status['available'] ? esc_html__( 'Available', 'masthead' ) : esc_html__( 'Needs Provider', 'masthead' ); ?>
							</span>
							<p class="description"><?php esc_html_e( 'Generate alternative headline options for any post.', 'masthead' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Alt Text Generation', 'masthead' ); ?></th>
						<td>
							<span class="masthead-badge <?php echo $ai_status['available'] ? 'masthead-badge-active' : 'masthead-badge-missing'; ?>">
								<?php echo $ai_status['available'] ? esc_html__( 'Available', 'masthead' ) : esc_html__( 'Needs Provider', 'masthead' ); ?>
							</span>
							<p class="description"><?php esc_html_e( 'Automatically generate alt text for images missing descriptions.', 'masthead' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Tone & Readability', 'masthead' ); ?></th>
						<td>
							<span class="masthead-badge <?php echo $ai_status['available'] ? 'masthead-badge-active' : 'masthead-badge-missing'; ?>">
								<?php echo $ai_status['available'] ? esc_html__( 'Available', 'masthead' ) : esc_html__( 'Needs Provider', 'masthead' ); ?>
							</span>
							<p class="description"><?php esc_html_e( 'Analyze content tone, reading level, and audience fit.', 'masthead' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Cross-Plugin Integrations -->
		<div class="masthead-card">
			<h2><?php esc_html_e( 'Cross-Plugin Integrations', 'masthead' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These features activate automatically when the required modules are both installed and active.', 'masthead' ); ?></p>
			<table class="form-table">
				<tbody>
				<?php foreach ( $avail_integ as $key => $setting ) :
					$requires_labels = array_map( fn( $id ) => $modules[ $id ]['label'] ?? $id, $setting['requires'] );
					$all_active = array_reduce( $setting['requires'], fn( $carry, $id ) => $carry && ( $modules[ $id ]['active'] ?? false ), true );
				?>
				<tr class="<?php echo $all_active ? '' : 'masthead-integration-inactive'; ?>">
					<th scope="row">
						<label for="integ_<?php echo esc_attr( $key ); ?>">
							<?php echo esc_html( $setting['label'] ); ?>
						</label>
					</th>
					<td>
						<label>
							<input type="checkbox"
								id="integ_<?php echo esc_attr( $key ); ?>"
								name="integrations[<?php echo esc_attr( $key ); ?>]"
								value="1"
								<?php checked( ! empty( $integrations[ $key ] ) ); ?>
								<?php disabled( ! $all_active ); ?>
							>
							<?php echo esc_html( $setting['description'] ); ?>
						</label>
						<?php if ( ! $all_active ) : ?>
						<p class="description masthead-requires-notice">
							<?php printf(
								/* translators: %s: list of required modules */
								esc_html__( 'Requires: %s', 'masthead' ),
								esc_html( implode( ' + ', $requires_labels ) )
							); ?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<button type="button" class="button button-primary" data-masthead-save="integrations">
					<?php esc_html_e( 'Save Integrations', 'masthead' ); ?>
				</button>
			</p>
		</div>

		<!-- Masthead Features -->
		<div class="masthead-card">
			<h2><?php esc_html_e( 'Features', 'masthead' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Core Masthead features. These are built into the plugin and don\'t require additional modules.', 'masthead' ); ?></p>
			<table class="form-table">
				<tbody>
				<?php foreach ( $avail_features as $key => $feature ) : ?>
				<tr>
					<th scope="row">
						<label for="feature_<?php echo esc_attr( $key ); ?>">
							<?php echo esc_html( $feature['label'] ); ?>
						</label>
					</th>
					<td>
						<label>
							<input type="checkbox"
								id="feature_<?php echo esc_attr( $key ); ?>"
								name="features[<?php echo esc_attr( $key ); ?>]"
								value="1"
								<?php checked( ! empty( $features[ $key ] ) ); ?>
							>
							<?php echo esc_html( $feature['description'] ); ?>
						</label>
						<?php if ( ! empty( $feature['requires'] ) ) : ?>
						<p class="description"><?php printf( esc_html__( 'Requires: %s', 'masthead' ), esc_html( implode( ', ', $feature['requires'] ) ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<button type="button" class="button button-primary" data-masthead-save="features">
					<?php esc_html_e( 'Save Features', 'masthead' ); ?>
				</button>
			</p>
		</div>

		<!-- Publication Checklist -->
		<?php if ( ! empty( $features['publication_checklist'] ) ) : ?>
		<div class="masthead-card">
			<h2><?php esc_html_e( 'Publication Checklist', 'masthead' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Authors must complete this checklist before publishing. Drag to reorder.', 'masthead' ); ?></p>

			<ul id="masthead-checklist-items" class="masthead-sortable">
				<?php foreach ( $checklist_items as $i => $item ) : ?>
				<li class="masthead-checklist-item" data-index="<?php echo (int) $i; ?>">
					<span class="dashicons dashicons-menu masthead-drag-handle"></span>
					<input type="text" name="items[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>" class="regular-text">
					<label>
						<input type="checkbox" name="items[<?php echo (int) $i; ?>][required]" value="1" <?php checked( ! empty( $item['required'] ) ); ?>>
						<?php esc_html_e( 'Required', 'masthead' ); ?>
					</label>
					<button type="button" class="button-link masthead-remove-item" aria-label="Remove">✕</button>
				</li>
				<?php endforeach; ?>
			</ul>

			<p>
				<button type="button" class="button" id="masthead-add-checklist-item">
					+ <?php esc_html_e( 'Add Item', 'masthead' ); ?>
				</button>
			</p>
			<p class="submit">
				<button type="button" class="button button-primary" data-masthead-save="checklist">
					<?php esc_html_e( 'Save Checklist', 'masthead' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

	</form>
</div>

<style>
.masthead-settings h1 { margin-bottom: 20px; }
.masthead-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 20px 24px; margin-bottom: 20px; }
.masthead-card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #f0f0f0; }
.masthead-badge { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-right: 8px; }
.masthead-badge-active { background: #d7f0d7; color: #1a7a1a; }
.masthead-badge-installed { background: #fff3cd; color: #856404; }
.masthead-badge-missing { background: #f0f0f0; color: #666; }
.masthead-integration-inactive { opacity: .6; }
.masthead-requires-notice { color: #999; margin-top: 4px; }
.masthead-sortable { list-style: none; margin: 0; padding: 0; }
.masthead-checklist-item { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f5f5f5; }
.masthead-drag-handle { cursor: grab; color: #aaa; }
.masthead-remove-item { color: #cc0000 !important; text-decoration: none; font-size: 16px; }
</style>

<script>
jQuery(function($) {
	// Sortable checklist.
	$('#masthead-checklist-items').sortable({ handle: '.masthead-drag-handle' });

	// Add checklist item.
	$('#masthead-add-checklist-item').on('click', function() {
		const idx = $('#masthead-checklist-items li').length;
		$('#masthead-checklist-items').append(`
			<li class="masthead-checklist-item" data-index="${idx}">
				<span class="dashicons dashicons-menu masthead-drag-handle"></span>
				<input type="text" name="items[${idx}][label]" value="" class="regular-text" placeholder="Checklist item…">
				<label><input type="checkbox" name="items[${idx}][required]" value="1"> Required</label>
				<button type="button" class="button-link masthead-remove-item" aria-label="Remove">✕</button>
			</li>
		`);
	});

	// Remove item.
	$(document).on('click', '.masthead-remove-item', function() {
		$(this).closest('li').remove();
	});

	// Save buttons.
	$('[data-masthead-save]').on('click', function() {
		const $btn = $(this);
		const section = $btn.data('masthead-save');
		const $card = $btn.closest('.masthead-card');

		$btn.text(mastheadAdmin.strings.saving).prop('disabled', true);

		const data = { action: 'masthead_save_settings', nonce: mastheadAdmin.nonce, section };

		if (section === 'features') {
			data.features = {};
			$card.find('input[type=checkbox]').each(function() {
				const name = $(this).attr('name').match(/features\[(.+)\]/)?.[1];
				if (name) data.features[name] = this.checked ? 1 : 0;
			});
		} else if (section === 'integrations') {
			data.integrations = {};
			$card.find('input[type=checkbox]:not(:disabled)').each(function() {
				const name = $(this).attr('name').match(/integrations\[(.+)\]/)?.[1];
				if (name) data.integrations[name] = this.checked ? 1 : 0;
			});
		} else if (section === 'checklist') {
			data.items = [];
			$('#masthead-checklist-items li').each(function(i) {
				data.items.push({
					label: $(this).find('input[type=text]').val(),
					required: $(this).find('input[type=checkbox]').is(':checked') ? 1 : 0,
				});
			});
		}

		$.post(ajaxurl, data)
			.done(function(res) {
				$btn.text(res.success ? mastheadAdmin.strings.saved : mastheadAdmin.strings.error);
			})
			.fail(function() {
				$btn.text(mastheadAdmin.strings.error);
			})
			.always(function() {
				setTimeout(() => $btn.text(section === 'features' ? 'Save Features' : section === 'integrations' ? 'Save Integrations' : 'Save Checklist').prop('disabled', false), 2000);
			});
	});
	// Install module.
	$(document).on('click', '.masthead-install-btn', function() {
		const $btn = $(this);
		const module = $btn.data('module');
		const $row = $btn.closest('tr');

		$btn.text('Installing…').prop('disabled', true);

		$.post(ajaxurl, {
			action: 'masthead_install_module',
			nonce: mastheadAdmin.nonce,
			module,
		})
		.done(function(res) {
			if (res.success) {
				$btn.replaceWith(`
					<span class="masthead-badge masthead-badge-installed">Installed</span>
					<button type="button" class="button button-primary button-small masthead-activate-btn" data-module="${module}">Activate</button>
				`);
			} else {
				$btn.text('Install failed').addClass('masthead-btn-error');
				console.error(res.data?.message);
			}
		})
		.fail(function() {
			$btn.text('Install failed').addClass('masthead-btn-error').prop('disabled', false);
		});
	});

	// Activate module.
	$(document).on('click', '.masthead-activate-btn', function() {
		const $btn = $(this);
		const module = $btn.data('module');

		$btn.text('Activating…').prop('disabled', true);

		$.post(ajaxurl, {
			action: 'masthead_activate_module',
			nonce: mastheadAdmin.nonce,
			module,
		})
		.done(function(res) {
			if (res.success) {
				$btn.closest('td').find('.masthead-badge').replaceWith('<span class="masthead-badge masthead-badge-active">✓ Active</span>');
				$btn.remove();
			} else {
				$btn.text('Activation failed').addClass('masthead-btn-error').prop('disabled', false);
				console.error(res.data?.message);
			}
		})
		.fail(function() {
			$btn.text('Activation failed').addClass('masthead-btn-error').prop('disabled', false);
		});
	});
});
</script>
