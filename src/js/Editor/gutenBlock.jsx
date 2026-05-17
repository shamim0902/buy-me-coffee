const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const {
    SelectControl
} = wp.components;

registerBlockType('buymecoffee/guten-block', {
    title: __('Buy Me Coffee'),
    icon: 'coffee',
    category: 'formatting',
    keywords: [
        __('Buy Me Coffee'),
        __('Gutenberg Block'),
        __('buymecoffee-gutenberg-block')
    ],
    attributes: {
       widget: {
           value: 'buymecoffee_button',
            type: 'string',
       }
    },
    edit({ attributes, setAttributes }) {
        const config = {
            widgets: [
                {
                    value: 'buymecoffee_button',
                    text: 'Button'
                },
                {
                    value: 'buymecoffee_form',
                    text: 'Form'
                },
                {
                    value: 'buymecoffee_basic',
                    text: 'Basic full template'
                }
            ]
        }

        return (
            <div className="buymecoffee-guten-wrapper" style={{padding: '23px', 'border': '1px solid #ccc'}}>
                <div className="buymecoffee-logo">
                    Buy Me Coffee
                </div>
                <SelectControl
                    label={__('Select a Widget')}
                    value={attributes.widget}
                    options={config.widgets.map(widget => ({
                        value: widget.value,
                        label: widget.text
                    }))}
                    onChange={widget => setAttributes({ widget })}
                />
            </div>
        )
    },
    save({ attributes }) {
        return '[' +  attributes.widget + ']';
    }
});
