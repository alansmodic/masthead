/**
 * Block editor integration for Editorial.io
 */

(function() {
    'use strict';
    
    const { registerPlugin } = wp.plugins;
    const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
    const { useSelect, useDispatch, subscribe } = wp.data;
    const { useState, useEffect, useCallback } = wp.element;
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
        SelectControl,
        Flex,
        FlexItem,
        __experimentalDivider as Divider,
    } = wp.components;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;
    
    // Get configuration
    const config = window.editorialIOData || {};
    const { postId, features, strings } = config;
    
    /**
     * Main Editorial.io Sidebar Component
     */
    function EditorialIOSidebar() {
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState(null);
        const [stagedRevision, setStagedRevision] = useState(null);
        const [showChecklistModal, setShowChecklistModal] = useState(false);
        
        const { currentPost, isDirty, isSaving } = useSelect(select => ({
            currentPost: select('core/editor').getCurrentPost(),
            isDirty: select('core/editor').isEditedPostDirty(),
            isSaving: select('core/editor').isSavingPost()
        }));
        
        // Load staged revision data
        useEffect(() => {
            if (features.staged_revisions && postId) {
                loadStagedRevision();
            }
        }, [postId]);
        
        const loadStagedRevision = useCallback(async () => {
            if (!features.staged_revisions) return;
            
            setLoading(true);
            setError(null);
            
            try {
                const response = await apiFetch({
                    path: `editorial/v1/posts/${postId}/staged`
                });
                setStagedRevision(response);
            } catch (err) {
                if (err.code !== 'not_found') {
                    setError(err.message || strings.error);
                }
            } finally {
                setLoading(false);
            }
        }, [postId]);
        
        const createStagedRevision = useCallback(async () => {
            if (!features.staged_revisions || isSaving) return;
            
            setLoading(true);
            setError(null);
            
            try {
                const { getEditedPostAttribute } = wp.data.select('core/editor');
                
                const response = await apiFetch({
                    path: `editorial/v1/posts/${postId}/staged`,
                    method: 'POST',
                    data: {
                        title: getEditedPostAttribute('title'),
                        content: getEditedPostAttribute('content'),
                        excerpt: getEditedPostAttribute('excerpt'),
                        notes: ''
                    }
                });
                
                setStagedRevision(response);
                
                // Save the post to create a revision point
                wp.data.dispatch('core/editor').savePost();
                
            } catch (err) {
                setError(err.message || strings.error);
            } finally {
                setLoading(false);
            }
        }, [postId, isSaving]);
        
        if (!postId) {
            return (
                <PanelBody title={strings.pluginTitle}>
                    <Notice status="info" isDismissible={false}>
                        {__('Save the post first to use Editorial.io features.', 'editorial-io')}
                    </Notice>
                </PanelBody>
            );
        }
        
        return (
            <>
                <PanelBody title={strings.pluginTitle} initialOpen={true}>
                    {loading && (
                        <div style={{ textAlign: 'center', padding: '20px' }}>
                            <Spinner />
                        </div>
                    )}
                    
                    {error && (
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                    )}
                    
                    {!loading && !error && (
                        <>
                            {/* Staged Revisions Section */}
                            {features.staged_revisions && (
                                <StagedRevisionsPanel 
                                    stagedRevision={stagedRevision}
                                    onCreateRevision={createStagedRevision}
                                    onRefresh={loadStagedRevision}
                                    isDirty={isDirty}
                                />
                            )}
                            
                            {/* Revision Timeline Section */}
                            {features.revision_timeline && (
                                <RevisionTimelinePanel postId={postId} />
                            )}
                        </>
                    )}
                </PanelBody>
                
                {/* Publication Checklist Modal */}
                {features.publication_checklist && showChecklistModal && (
                    <PublicationChecklistModal 
                        onClose={() => setShowChecklistModal(false)}
                        onComplete={() => setShowChecklistModal(false)}
                    />
                )}
            </>
        );
    }
    
    /**
     * Staged Revisions Panel Component
     */
    function StagedRevisionsPanel({ stagedRevision, onCreateRevision, onRefresh, isDirty }) {
        return (
            <div className="editorial-io-staged-panel">
                {stagedRevision ? (
                    <div className="staged-revision-info">
                        <Notice status="info" isDismissible={false}>
                            <strong>{__('Staged Revision Active', 'editorial-io')}</strong><br />
                            {stagedRevision.status === 'pending' && __('Awaiting review', 'editorial-io')}
                            {stagedRevision.status === 'approved' && __('Approved for publishing', 'editorial-io')}
                            {stagedRevision.status === 'scheduled' && __('Scheduled for publishing', 'editorial-io')}
                        </Notice>
                        
                        <div className="staged-revision-meta">
                            <p>
                                <strong>{__('Created:', 'editorial-io')}</strong> {stagedRevision.modified}
                            </p>
                            {stagedRevision.notes && (
                                <p>
                                    <strong>{__('Notes:', 'editorial-io')}</strong> {stagedRevision.notes}
                                </p>
                            )}
                        </div>
                        
                        <div className="staged-revision-actions">
                            <Button isPrimary onClick={onRefresh} disabled={isDirty}>
                                {__('Update Staged Revision', 'editorial-io')}
                            </Button>
                            
                            {stagedRevision.status === 'approved' && (
                                <Button isSecondary>
                                    {__('Publish Now', 'editorial-io')}
                                </Button>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="no-staged-revision">
                        <p>{__('No staged revision exists for this post.', 'editorial-io')}</p>
                        <Button 
                            isPrimary 
                            onClick={onCreateRevision}
                            disabled={!isDirty}
                        >
                            {strings.saveAsRewrite || __('Save as Rewrite', 'editorial-io')}
                        </Button>
                        {!isDirty && (
                            <p className="description">
                                {__('Make changes to create a staged revision.', 'editorial-io')}
                            </p>
                        )}
                    </div>
                )}
            </div>
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
            <PanelBody title={strings.revisionHistory} initialOpen={false}>
                {loading && <Spinner />}
                
                {error && (
                    <Notice status="error" isDismissible={false}>
                        {error}
                    </Notice>
                )}
                
                {!loading && !error && revisions.length === 0 && (
                    <p>{strings.noRevisions}</p>
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
                                        {revision.date_relative} {strings.ago}
                                    </div>
                                </div>
                                
                                {revision.changes.length > 0 && (
                                    <div className="revision-changes">
                                        <small>
                                            {strings.changed} {revision.changes.join(', ')}
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
    
    /**
     * Publication Checklist Modal Component
     */
    function PublicationChecklistModal({ onClose, onComplete }) {
        const [checkedItems, setCheckedItems] = useState({});
        const [error, setError] = useState(null);
        const [saving, setSaving] = useState(false);
        
        const checklistItems = config.config?.checklist?.items || [];
        
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
                setError(strings.requiredItems);
                return;
            }
            setError(null);
            // Proceed with publishing
            onComplete();
        };
        
        return (
            <Modal
                title={strings.checklistTitle}
                onRequestClose={onClose}
                className="editorial-io-checklist-modal"
                isDismissible={!saving}
            >
                <div className="checklist-content">
                    <p className="checklist-subtitle">
                        {strings.checklistSubtitle}
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
                                />
                            </div>
                        ))}
                    </div>
                    
                    <Flex justify="flex-end" gap={2}>
                        <FlexItem>
                            <Button 
                                isSecondary 
                                onClick={onClose}
                                disabled={saving}
                            >
                                {strings.cancel}
                            </Button>
                        </FlexItem>
                        <FlexItem>
                            <Button 
                                isPrimary 
                                onClick={handlePublish}
                                disabled={saving || !allRequiredChecked()}
                            >
                                {saving ? strings.publishing : strings.confirmAndPublish}
                            </Button>
                        </FlexItem>
                    </Flex>
                </div>
            </Modal>
        );
    }
    
    // Register the plugin
    registerPlugin('editorial-io', {
        render: function() {
            return (
                <>
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
                        <EditorialIOSidebar />
                    </PluginSidebar>
                </>
            );
        }
    });
    
})();