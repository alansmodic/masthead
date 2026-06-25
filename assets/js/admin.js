/**
 * Admin JavaScript for Masthead
 */

(function($) {
    'use strict';
    
    const MastheadAdmin = {
        init: function() {
            this.bindEvents();
            this.initSortable();
        },
        
        bindEvents: function() {
            // Features form
            $('#features-form').on('submit', this.handleFeaturesSubmit);
            $('#reset-features').on('click', this.handleFeaturesReset);
            
            // Checklist form
            $('#checklist-form').on('submit', this.handleChecklistSubmit);
            $('#add-checklist-item').on('click', this.addChecklistItem);
            $(document).on('click', '.checklist-delete', this.deleteChecklistItem);
            
            // General form
            $('#general-form').on('submit', this.handleGeneralSubmit);
        },
        
        initSortable: function() {
            if ($('#checklist-items').length && typeof $.ui !== 'undefined' && $.ui.sortable) {
                $('#checklist-items').sortable({
                    handle: '.checklist-handle',
                    axis: 'y',
                    placeholder: 'checklist-item-placeholder',
                    tolerance: 'pointer'
                });
            }
        },
        
        handleFeaturesSubmit: function(e) {
            e.preventDefault();
            
            const form = $(this);
            const submitButton = form.find('button[type="submit"]');
            const statusSpan = form.find('.settings-status');
            
            submitButton.prop('disabled', true).text(mastheadAdmin.strings.saving);
            statusSpan.removeClass('success error warning').text('');
            
            const formData = new FormData(form[0]);
            formData.append('action', 'masthead_save_settings');
            formData.append('section', 'features');
            formData.append('nonce', mastheadAdmin.nonce);
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        statusSpan.addClass('success').text(response.data.message);
                        
                        // Update UI with any changes made due to dependencies
                        if (response.data.features) {
                            Object.keys(response.data.features).forEach(function(feature) {
                                const checkbox = form.find(`input[name="features[${feature}]"]`);
                                if (checkbox.length) {
                                    checkbox.prop('checked', response.data.features[feature]);
                                }
                            });
                        }
                        
                        // Show warnings if any
                        if (response.data.warnings && response.data.warnings.length > 0) {
                            const warningMsg = response.data.warnings.join(', ');
                            statusSpan.addClass('warning').text(statusSpan.text() + ' ' + warningMsg);
                        }
                    } else {
                        statusSpan.addClass('error').text(response.data.message || mastheadAdmin.strings.error);
                    }
                },
                error: function() {
                    statusSpan.addClass('error').text(mastheadAdmin.strings.error);
                },
                complete: function() {
                    submitButton.prop('disabled', false).text(mastheadAdmin.strings.save);
                }
            });
        },
        
        handleFeaturesReset: function(e) {
            e.preventDefault();
            
            if (!confirm(mastheadAdmin.strings.confirmReset)) {
                return;
            }
            
            const button = $(this);
            const statusSpan = $('.settings-status');
            
            button.prop('disabled', true);
            statusSpan.removeClass('success error warning').text('');
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'masthead_reset_features',
                    nonce: mastheadAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        statusSpan.addClass('success').text(response.data.message);
                        
                        // Update checkboxes with default values
                        if (response.data.features) {
                            Object.keys(response.data.features).forEach(function(feature) {
                                const checkbox = $(`input[name="features[${feature}]"]`);
                                if (checkbox.length) {
                                    checkbox.prop('checked', response.data.features[feature]);
                                }
                            });
                        }
                        
                        // Reload page after delay to refresh everything
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        statusSpan.addClass('error').text(response.data.message || mastheadAdmin.strings.error);
                    }
                },
                error: function() {
                    statusSpan.addClass('error').text(mastheadAdmin.strings.error);
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        },
        
        handleChecklistSubmit: function(e) {
            e.preventDefault();
            
            const form = $(this);
            const submitButton = form.find('button[type="submit"]');
            const statusSpan = form.find('.settings-status');
            
            submitButton.prop('disabled', true).text(mastheadAdmin.strings.saving);
            statusSpan.removeClass('success error').text('');
            
            // Collect checklist items
            const items = [];
            $('#checklist-items .checklist-item').each(function() {
                const label = $(this).find('.checklist-label').val().trim();
                const required = $(this).find('.checklist-required input').prop('checked');
                
                if (label) {
                    items.push({
                        label: label,
                        required: required
                    });
                }
            });
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'masthead_save_settings',
                    section: 'checklist',
                    items: items,
                    nonce: mastheadAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        statusSpan.addClass('success').text(response.data.message);
                    } else {
                        statusSpan.addClass('error').text(response.data.message || mastheadAdmin.strings.error);
                    }
                },
                error: function() {
                    statusSpan.addClass('error').text(mastheadAdmin.strings.error);
                },
                complete: function() {
                    submitButton.prop('disabled', false).text(mastheadAdmin.strings.save);
                }
            });
        },
        
        handleGeneralSubmit: function(e) {
            e.preventDefault();
            
            const form = $(this);
            const submitButton = form.find('button[type="submit"]');
            const statusSpan = form.find('.settings-status');
            
            submitButton.prop('disabled', true).text(mastheadAdmin.strings.saving);
            statusSpan.removeClass('success error').text('');
            
            const formData = new FormData(form[0]);
            formData.append('action', 'masthead_save_settings');
            formData.append('section', 'general');
            formData.append('nonce', mastheadAdmin.nonce);
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        statusSpan.addClass('success').text(response.data.message);
                    } else {
                        statusSpan.addClass('error').text(response.data.message || mastheadAdmin.strings.error);
                    }
                },
                error: function() {
                    statusSpan.addClass('error').text(mastheadAdmin.strings.error);
                },
                complete: function() {
                    submitButton.prop('disabled', false).text(mastheadAdmin.strings.save);
                }
            });
        },
        
        addChecklistItem: function(e) {
            e.preventDefault();
            
            const container = $('#checklist-items');
            const newIndex = container.children().length;
            
            const newItem = $(`
                <div class="checklist-item" data-index="${newIndex}">
                    <span class="checklist-handle dashicons dashicons-menu"></span>
                    <input type="text" class="checklist-label regular-text" placeholder="${mastheadAdmin.strings.checklistPlaceholder || 'Checklist item text...'}" />
                    <label class="checklist-required">
                        <input type="checkbox" />
                        Required
                    </label>
                    <button type="button" class="button checklist-delete" title="Delete">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            `);
            
            container.append(newItem);
            newItem.find('.checklist-label').focus();
        },
        
        deleteChecklistItem: function(e) {
            e.preventDefault();
            
            if (!confirm(mastheadAdmin.strings.confirmDelete)) {
                return;
            }
            
            $(this).closest('.checklist-item').fadeOut(300, function() {
                $(this).remove();
            });
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        MastheadAdmin.init();
    });
    
})(jQuery);
