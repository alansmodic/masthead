/**
 * Block editor integration for Editorial.io
 *
 * Ported from Rewrites plugin with full feature parity:
 * - Checklist modal with publish button intercept
 * - "Save as Rewrite" option in checklist
 * - Staged revision create/update from sidebar
 * - Schedule picker with DateTimePicker
 * - Publish and Discard buttons
 * - Revision timeline panel
 */

(function() {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
    const { useSelect, useDispatch, subscribe } = wp.data;
    const { useState, useEffect, useCallback, useRef } = wp.element;
    const {
        Button,
        PanelBody,
        PanelRow,
        Notice,
        Spinner,
        Modal,
        CheckboxControl,
        TextareaControl,
        DateTimePicker,
        Flex,
        FlexItem,
    } = wp.components;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;

    // Get configuration
    const config = window.editorialIOData || {};
    const { postId, features, strings } = config;
    const checklistItems = config.config?.checklist?.items || [];
    const checklistEnabled = features?.publication_checklist && checklistItems.length > 0;

    /**
     * Main Editorial.io Plugin Component (renders at top level like Rewrites)
     */
    function EditorialIOPlugin() {
        const [stagedRevision, setStagedRevision] = useState(null);
        const [isLoading, setIsLoading] = useState(false);
        const [isSaving, setIsSaving] = useState(false);
        const [isPublishing, setIsPublishing] = useState(false);
        const [isScheduling, setIsScheduling] = useState(false);
        const [error, setError] = useState(null);
        const [success, setSuccess] = useState(null);
        const [notes, setNotes] = useState('');
        const [scheduleDate, setScheduleDate] = useState(null);
        const [showScheduler, setShowScheduler] = useState(false);

        // Checklist modal state.
        const [showChecklist, setShowChecklist] = useState(false);
        const [checklistSaving, setChecklistSaving] = useState(null);
        const pendingSaveRef = useRef(null);
        const bypassChecklistRef = useRef(false);

        // Track if post has become published.
        const [isPostPublished, setIsPostPublished] = useState(false);
        const previousStatusRef = useRef(null);

        const { createSuccessNotice, createErrorNotice } = useDispatch('core/notices');

        const { currentPostId, postStatus, editedTitle, editedContent, editedExcerpt, isSavingPost } =
            useSelect((select) => {
                const editor = select('core/editor');
                return {
                    currentPostId: editor.getCurrentPostId(),
                    postStatus: editor.getEditedPostAttribute('status'),
                    editedTitle: editor.getEditedPostAttribute('title'),
                    editedContent: editor.getEditedPostAttribute('content'),
                    editedExcerpt: editor.getEditedPostAttribute('excerpt'),
                    isSavingPost: editor.isSavingPost(),
                };
            });

        // Track when post becomes published.
        useEffect(() => {
            if (postStatus === 'publish' && previousStatusRef.current !== 'publish') {
                setIsPostPublished(true);
            }
            previousStatusRef.current = postStatus;
        }, [postStatus]);

        useEffect(() => {
            if (postStatus === 'publish') {
                setIsPostPublished(true);
            }
        }, []);

        // Intercept publish/update button when checklist is enabled.
        useEffect(() => {
            if (!checklistEnabled || !isPostPublished) {
                return;
            }

            const handleClick = (e) => {
                const button = e.target.closest('.editor-post-publish-button');
                if (!button) return;

                if (bypassChecklistRef.current) {
                    bypassChecklistRef.current = false;
                    return;
                }

                e.preventDefault();
                e.stopPropagation();
                setShowChecklist(true);
            };

            document.addEventListener('click', handleClick, true);

            pendingSaveRef.current = () => {
                bypassChecklistRef.current = true;
                const updateButton = document.querySelector('.editor-post-publish-button');
                if (updateButton) {
                    updateButton.click();
                }
            };

            return () => {
                document.removeEventListener('click', handleClick, true);
            };
        }, [checklistEnabled, isPostPublished]);

        // Fetch existing staged revision on mount.
        useEffect(() => {
            if (!postId || !isPostPublished || !features.staged_revisions) return;

            setIsLoading(true);
            apiFetch({ path: `editorial/v1/posts/${postId}/staged` })
                .then((response) => {
                    setStagedRevision(response);
                    setNotes(response.notes || '');
                    if (response.scheduled_date) {
                        setScheduleDate(new Date(response.scheduled_date));
                    }
                })
                .catch(() => {
                    setStagedRevision(null);
                })
                .finally(() => {
                    setIsLoading(false);
                });
        }, [postId, isPostPublished]);

        // Only show for published posts.
        if (!isPostPublished) {
            return null;
        }

        /**
         * Save as rewrite from checklist modal.
         */
        const handleChecklistSaveRewrite = async () => {
            setChecklistSaving('rewrite');

            try {
                const response = await apiFetch({
                    path: `editorial/v1/posts/${postId}/staged`,
                    method: 'POST',
                    data: {
                        title: editedTitle,
                        content: editedContent,
                        excerpt: editedExcerpt,
                        notes: '',
                    },
                });

                setStagedRevision(response);
                createSuccessNotice(__('Changes saved as rewrite for review.', 'editorial-io'), {
                    type: 'snackbar',
                });
                setShowChecklist(false);
            } catch (err) {
                createErrorNotice(err.message || __('Failed to save rewrite.', 'editorial-io'), {
                    type: 'snackbar',
                });
            } finally {
                setChecklistSaving(null);
            }
        };

        /**
         * Publish after checklist confirmation.
         */
        const handleChecklistPublish = () => {
            setChecklistSaving('publish');
            setShowChecklist(false);

            if (pendingSaveRef.current) {
                pendingSaveRef.current();
            }

            setChecklistSaving(null);
        };

        /**
         * Save changes as staged revision from sidebar.
         */
        const handleSaveStaged = async () => {
            setIsSaving(true);
            setError(null);
            setSuccess(null);

            try {
                const response = await apiFetch({
                    path: `editorial/v1/posts/${postId}/staged`,
                    method: 'POST',
                    data: {
                        title: editedTitle,
                        content: editedContent,
                        excerpt: editedExcerpt,
                        notes: notes,
                    },
                });

                setStagedRevision(response);
                setSuccess(__('Changes saved without publishing.', 'editorial-io'));
                createSuccessNotice(__('Changes saved as staged revision.', 'editorial-io'), {
                    type: 'snackbar',
                });
            } catch (err) {
                setError(err.message || __('Failed to save staged revision.', 'editorial-io'));
            } finally {
                setIsSaving(false);
            }
        };

        /**
         * Publish staged revision immediately.
         */
        const handlePublish = async () => {
            if (!stagedRevision) return;

            if (!confirm(__('Are you sure you want to publish these changes now?', 'editorial-io'))) {
                return;
            }

            setIsPublishing(true);
            setError(null);

            try {
                await apiFetch({
                    path: `editorial/v1/staged/${stagedRevision.revision_id}/publish`,
                    method: 'POST',
                });

                setStagedRevision(null);
                setNotes('');
                setScheduleDate(null);
                createSuccessNotice(__('Staged changes published successfully.', 'editorial-io'), {
                    type: 'snackbar',
                });

                window.location.reload();
            } catch (err) {
                setError(err.message || __('Failed to publish staged revision.', 'editorial-io'));
            } finally {
                setIsPublishing(false);
            }
        };

        /**
         * Schedule staged revision.
         */
        const handleSchedule = async () => {
            if (!stagedRevision || !scheduleDate) return;

            setIsScheduling(true);
            setError(null);

            try {
                const response = await apiFetch({
                    path: `editorial/v1/staged/${stagedRevision.revision_id}/schedule`,
                    method: 'POST',
                    data: {
                        publish_date: scheduleDate.toISOString(),
                    },
                });

                // Refresh staged revision data.
                const updated = await apiFetch({
                    path: `editorial/v1/posts/${postId}/staged`
                });
                setStagedRevision(updated);
                setShowScheduler(false);
                createSuccessNotice(__('Staged revision scheduled for publishing.', 'editorial-io'), {
                    type: 'snackbar',
                });
            } catch (err) {
                setError(err.message || __('Failed to schedule staged revision.', 'editorial-io'));
            } finally {
                setIsScheduling(false);
            }
        };

        /**
         * Discard staged revision.
         */
        const handleDiscard = async () => {
            if (!stagedRevision) return;

            if (!confirm(__('Are you sure you want to discard these changes? This cannot be undone.', 'editorial-io'))) {
                return;
            }

            try {
                await apiFetch({
                    path: `editorial/v1/staged/${stagedRevision.revision_id}`,
                    method: 'DELETE',
                });

                setStagedRevision(null);
                setNotes('');
                setScheduleDate(null);
                createSuccessNotice(__('Staged revision discarded.', 'editorial-io'), {
                    type: 'snackbar',
                });
            } catch (err) {
                setError(err.message || __('Failed to discard staged revision.', 'editorial-io'));
            }
        };

        /**
         * Get status badge.
         */
        const getStatusBadge = () => {
            if (!stagedRevision) return null;

            const status = stagedRevision.status || 'pending';
            const statusColors = {
                pending: '#f0b849',
                approved: '#4ab866',
                rejected: '#d63638',
                scheduled: '#2271b1',
            };
            const statusLabels = {
                pending: __('Pending Review', 'editorial-io'),
                approved: __('Approved', 'editorial-io'),
                rejected: __('Rejected', 'editorial-io'),
                scheduled: __('Scheduled', 'editorial-io'),
            };

            return (
                <span
                    style={{
                        backgroundColor: statusColors[status] || '#888',
                        color: '#fff',
                        padding: '2px 8px',
                        borderRadius: '3px',
                        fontSize: '11px',
                        fontWeight: '600',
                        textTransform: 'uppercase',
                    }}
                >
                    {statusLabels[status] || status}
                </span>
            );
        };

        // Build sidebar content.
        const sidebarContent = (
            <>
                {error && (
                    <Notice status="error" isDismissible={true} onDismiss={() => setError(null)}>
                        {error}
                    </Notice>
                )}

                {success && (
                    <Notice status="success" isDismissible={true} onDismiss={() => setSuccess(null)}>
                        {success}
                    </Notice>
                )}

                {isLoading && (
                    <div style={{ padding: '20px', textAlign: 'center' }}>
                        <Spinner />
                    </div>
                )}

                {!isLoading && features.staged_revisions && (
                    <PanelBody title={__('Save Without Publishing', 'editorial-io')} initialOpen={true}>
                        <p>
                            {__('Save your changes without making them live immediately. An editor can review and approve before publishing.', 'editorial-io')}
                        </p>
                        <TextareaControl
                            label={__('Notes for reviewers', 'editorial-io')}
                            value={notes}
                            onChange={setNotes}
                            placeholder={__('Describe your changes...', 'editorial-io')}
                        />
                        <Button
                            variant="secondary"
                            onClick={handleSaveStaged}
                            isBusy={isSaving}
                            disabled={isSaving}
                            style={{ width: '100%' }}
                        >
                            {stagedRevision
                                ? __('Update Staged Changes', 'editorial-io')
                                : __('Save as Rewrite', 'editorial-io')}
                        </Button>
                    </PanelBody>
                )}

                {!isLoading && stagedRevision && (
                    <PanelBody title={__('Pending Changes', 'editorial-io')} initialOpen={true}>
                        <Flex justify="space-between" style={{ marginBottom: '12px' }}>
                            <FlexItem>{__('Status:', 'editorial-io')}</FlexItem>
                            <FlexItem>{getStatusBadge()}</FlexItem>
                        </Flex>
                        <p>
                            {__('Last saved:', 'editorial-io')}{' '}
                            <strong>{new Date(stagedRevision.modified).toLocaleString()}</strong>
                        </p>

                        {stagedRevision.scheduled_date && (
                            <Notice status="warning" isDismissible={false}>
                                {__('Scheduled for:', 'editorial-io')}{' '}
                                {new Date(stagedRevision.scheduled_date).toLocaleString()}
                            </Notice>
                        )}

                        {features.scheduled_publishing && !showScheduler && (
                            <Button
                                variant="secondary"
                                onClick={() => setShowScheduler(true)}
                                style={{ width: '100%', marginTop: '12px' }}
                            >
                                {stagedRevision.scheduled_date
                                    ? __('Change Schedule', 'editorial-io')
                                    : __('Schedule Publication', 'editorial-io')}
                            </Button>
                        )}

                        {showScheduler && (
                            <div style={{ marginTop: '12px' }}>
                                <DateTimePicker
                                    currentDate={scheduleDate}
                                    onChange={setScheduleDate}
                                    is12Hour={true}
                                />
                                <Flex style={{ marginTop: '12px' }}>
                                    <FlexItem>
                                        <Button
                                            variant="primary"
                                            onClick={handleSchedule}
                                            isBusy={isScheduling}
                                            disabled={isScheduling || !scheduleDate}
                                        >
                                            {__('Schedule', 'editorial-io')}
                                        </Button>
                                    </FlexItem>
                                    <FlexItem>
                                        <Button
                                            variant="tertiary"
                                            onClick={() => setShowScheduler(false)}
                                        >
                                            {__('Cancel', 'editorial-io')}
                                        </Button>
                                    </FlexItem>
                                </Flex>
                            </div>
                        )}

                        <div style={{ marginTop: '16px', borderTop: '1px solid #ddd', paddingTop: '16px' }}>
                            <Flex direction="column" gap={2}>
                                <Button
                                    variant="primary"
                                    onClick={handlePublish}
                                    isBusy={isPublishing}
                                    disabled={isPublishing || stagedRevision.status === 'rejected'}
                                    style={{ width: '100%' }}
                                >
                                    {__('Publish Now', 'editorial-io')}
                                </Button>
                                <Button
                                    variant="tertiary"
                                    isDestructive={true}
                                    onClick={handleDiscard}
                                    style={{ width: '100%' }}
                                >
                                    {__('Discard Changes', 'editorial-io')}
                                </Button>
                            </Flex>
                        </div>
                    </PanelBody>
                )}

                {!isLoading && features.revision_timeline && (
                    <RevisionTimelinePanel postId={postId} />
                )}
            </>
        );

        return (
            <>
                {/* Checklist modal */}
                {checklistEnabled && showChecklist && (
                    <ChecklistModal
                        onClose={() => setShowChecklist(false)}
                        onSaveRewrite={handleChecklistSaveRewrite}
                        onPublish={handleChecklistPublish}
                        isSaving={checklistSaving}
                    />
                )}

                <PluginSidebarMoreMenuItem
                    target="editorial-io-sidebar"
                    icon="edit-large"
                >
                    {strings.pluginTitle}
                </PluginSidebarMoreMenuItem>

                <PluginSidebar
                    name="editorial-io-sidebar"
                    title={strings.pluginTitle}
                    icon="edit-large"
                >
                    {sidebarContent}
                </PluginSidebar>
            </>
        );
    }

    /**
     * Checklist Modal Component — intercepts publish, offers "Save as Rewrite" or "Confirm & Publish".
     */
    function ChecklistModal({ onClose, onSaveRewrite, onPublish, isSaving }) {
        const [checkedItems, setCheckedItems] = useState({});
        const [error, setError] = useState(null);

        const handleCheckChange = (index, checked) => {
            setCheckedItems(prev => ({ ...prev, [index]: checked }));
        };

        const allRequiredChecked = () => {
            return checklistItems.every((item, index) => {
                if (item.required) {
                    return checkedItems[index] === true;
                }
                return true;
            });
        };

        const handlePublish = () => {
            if (!allRequiredChecked()) {
                setError(strings.requiredItems || __('Please complete all required items.', 'editorial-io'));
                return;
            }
            setError(null);
            onPublish();
        };

        return (
            <Modal
                title={strings.checklistTitle || __('Publication Checklist', 'editorial-io')}
                onRequestClose={onClose}
                className="editorial-io-checklist-modal"
                isDismissible={!isSaving}
            >
                <div className="checklist-content">
                    <p className="checklist-subtitle">
                        {strings.checklistSubtitle || __('Please review the following items before publishing.', 'editorial-io')}
                    </p>

                    {error && (
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                    )}

                    <div className="checklist-items">
                        {checklistItems.map((item, index) => (
                            <div key={index} className="checklist-item">
                                <CheckboxControl
                                    label={
                                        <>
                                            {item.label}
                                            {item.required && (
                                                <span className="required-indicator"> *</span>
                                            )}
                                        </>
                                    }
                                    checked={checkedItems[index] || false}
                                    onChange={(checked) => handleCheckChange(index, checked)}
                                    disabled={!!isSaving}
                                />
                            </div>
                        ))}
                    </div>

                    <Flex justify="flex-end" gap={3}>
                        {features.staged_revisions && (
                            <FlexItem>
                                <Button
                                    variant="secondary"
                                    onClick={onSaveRewrite}
                                    isBusy={isSaving === 'rewrite'}
                                    disabled={!!isSaving}
                                >
                                    {strings.saveAsRewrite || __('Save as Rewrite', 'editorial-io')}
                                </Button>
                            </FlexItem>
                        )}
                        <FlexItem>
                            <Button
                                variant="primary"
                                onClick={handlePublish}
                                isBusy={isSaving === 'publish'}
                                disabled={!!isSaving}
                            >
                                {strings.confirmAndPublish || __('Confirm & Publish', 'editorial-io')}
                            </Button>
                        </FlexItem>
                    </Flex>
                </div>
            </Modal>
        );
    }

    /**
     * Revision Timeline Panel Component
     */
    function RevisionTimelinePanel({ postId }) {
        const [revisions, setRevisions] = useState([]);
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState(null);

        useEffect(() => {
            loadRevisions();
        }, [postId]);

        const loadRevisions = async () => {
            setLoading(true);
            setError(null);

            try {
                const response = await apiFetch({
                    path: `editorial/v1/posts/${postId}/revisions?per_page=10`
                });
                setRevisions(response);
            } catch (err) {
                setError(err.message || strings.error);
            } finally {
                setLoading(false);
            }
        };

        return (
            <PanelBody title={strings.revisionHistory || __('Revision History', 'editorial-io')} initialOpen={false}>
                {loading && <Spinner />}

                {error && (
                    <Notice status="error" isDismissible={false}>
                        {error}
                    </Notice>
                )}

                {!loading && !error && revisions.length === 0 && (
                    <p>{strings.noRevisions || __('No revisions found.', 'editorial-io')}</p>
                )}

                {!loading && !error && revisions.length > 0 && (
                    <div className="revision-timeline">
                        {revisions.slice(0, 5).map(revision => (
                            <div key={revision.id} className={`revision-item revision-type-${revision.type}`}>
                                <div className="revision-meta">
                                    <div className="revision-author">
                                        <img
                                            src={revision.author.avatar}
                                            alt={revision.author.name}
                                            className="author-avatar"
                                        />
                                        <span className="author-name">{revision.author.name}</span>
                                    </div>
                                    <div className="revision-date">
                                        {revision.date_relative} {strings.ago || __('ago', 'editorial-io')}
                                    </div>
                                </div>

                                {revision.changes.length > 0 && (
                                    <div className="revision-changes">
                                        <small>
                                            {strings.changed || __('Changed', 'editorial-io')} {revision.changes.join(', ')}
                                        </small>
                                    </div>
                                )}
                            </div>
                        ))}

                        {revisions.length > 5 && (
                            <p>
                                <Button isLink>
                                    {__('View all revisions', 'editorial-io')}
                                </Button>
                            </p>
                        )}
                    </div>
                )}
            </PanelBody>
        );
    }

    // Register the plugin.
    registerPlugin('editorial-io', {
        render: EditorialIOPlugin,
        icon: 'edit-large',
    });

})();
