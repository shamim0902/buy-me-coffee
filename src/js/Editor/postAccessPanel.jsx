const { __ } = wp.i18n;
const { registerPlugin } = wp.plugins;
const { PluginDocumentSettingPanel } = wp.editPost;
const { PanelRow, RadioControl, CheckboxControl, TextControl, Notice } = wp.components;
const { useSelect, useDispatch } = wp.data;
const { Fragment } = wp.element;

function BuymecoffeePostAccessPanel() {
    const levels = (window.BuymecoffeePostAccess && window.BuymecoffeePostAccess.levels) || [];

    const meta = useSelect(function(select) {
        return select('core/editor').getEditedPostAttribute('meta') || {};
    });

    const { editPost } = useDispatch('core/editor');

    const access       = meta._buymecoffee_access       || 'free';
    const levelIds     = meta._buymecoffee_level_ids    || [];
    const previewWords = meta._buymecoffee_preview_words || 0;

    function setAccess(value) {
        editPost({ meta: { _buymecoffee_access: value } });
    }

    function toggleLevel(levelId, checked) {
        var current = Array.isArray(levelIds) ? levelIds.slice() : [];
        if (checked) {
            if (current.indexOf(levelId) === -1) {
                current.push(levelId);
            }
        } else {
            current = current.filter(function(id) { return id !== levelId; });
        }
        editPost({ meta: { _buymecoffee_level_ids: current } });
    }

    function setPreviewWords(value) {
        var num = parseInt(value, 10);
        editPost({ meta: { _buymecoffee_preview_words: isNaN(num) ? 0 : num } });
    }

    return (
        <PluginDocumentSettingPanel
            name="buymecoffee-post-access"
            title={ __('Content Access (Buy Me Coffee)', 'buy-me-coffee') }
            icon="lock"
        >
            <PanelRow>
                <RadioControl
                    label={ __('Access Level', 'buy-me-coffee') }
                    selected={ access }
                    options={[
                        { label: __('Free — visible to everyone', 'buy-me-coffee'), value: 'free' },
                        { label: __('Paid — members only', 'buy-me-coffee'),         value: 'paid' },
                    ]}
                    onChange={ setAccess }
                />
            </PanelRow>

            { access === 'paid' && (
                <Fragment>
                    { levels.length > 0 ? (
                        <PanelRow>
                            <div style={{ width: '100%' }}>
                                <p style={{ fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', color: '#757575', marginBottom: '8px' }}>
                                    { __('Allowed Levels', 'buy-me-coffee') }
                                </p>
                                { levels.map(function(level) {
                                    return (
                                        <CheckboxControl
                                            key={ level.id }
                                            label={ level.name + ' ($' + (level.price / 100).toFixed(2) + '/' + level.interval_type + ')' }
                                            checked={ Array.isArray(levelIds) && levelIds.indexOf(level.id) !== -1 }
                                            onChange={ function(checked) { toggleLevel(level.id, checked); } }
                                        />
                                    );
                                }) }
                            </div>
                        </PanelRow>
                    ) : (
                        <PanelRow>
                            <Notice status="warning" isDismissible={ false } style={{ width: '100%' }}>
                                { __('No active membership levels. Create one in the Memberships section.', 'buy-me-coffee') }
                            </Notice>
                        </PanelRow>
                    ) }

                    <PanelRow>
                        <TextControl
                            label={ __('Preview words (0 = global default)', 'buy-me-coffee') }
                            type="number"
                            value={ previewWords || '' }
                            min="0"
                            onChange={ setPreviewWords }
                            help={ __('Non-members see this many words before the paywall.', 'buy-me-coffee') }
                        />
                    </PanelRow>
                </Fragment>
            ) }
        </PluginDocumentSettingPanel>
    );
}

registerPlugin('buymecoffee-post-access', {
    render: BuymecoffeePostAccessPanel,
});
