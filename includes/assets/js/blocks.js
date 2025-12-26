( function( wp ) {
  const { registerBlockType } = wp.blocks;
  const { __ } = wp.i18n;

  // Helper to cut boilerplate for dynamic server blocks
  const dyn = ( title, options = {} ) => ({
    title,
    category: options.category || 'widgets',
    icon: options.icon || 'editor-table',
    description: options.desc || '',
    supports: { html: false },
    parent: options.parent || undefined,
    edit: () => wp.element.createElement(
      'div',
      { className: 'wi-block-placeholder' },
      options.preview || title + ' (server-rendered)'
    ),
    save: () => null,
  });

  // Parent
  registerBlockType(
    'wicked-invoicing/line-items',
    dyn( __('Line Items (Loop)','wicked-invoicing'), {
      icon: 'list-view',
      preview: __('Renders one row per invoice line item','wicked-invoicing'),
    } )
  );

  // Children
  const parent = [ 'wicked-invoicing/line-items' ];

  registerBlockType(
    'wicked-invoicing/line-item-description',
    dyn( __('Line Item: Description','wicked-invoicing'), { parent } )
  );

  registerBlockType(
    'wicked-invoicing/line-item-quantity',
    dyn( __('Line Item: Quantity','wicked-invoicing'), { parent } )
  );

  registerBlockType(
    'wicked-invoicing/line-item-rate',
    dyn( __('Line Item: Rate','wicked-invoicing'), { parent } )
  );

  registerBlockType(
    'wicked-invoicing/line-item-discount',
    dyn( __('Line Item: Discount','wicked-invoicing'), { parent } )
  );

  registerBlockType(
    'wicked-invoicing/line-item-tax',
    dyn( __('Line Item: Tax','wicked-invoicing'), { parent } )
  );

  registerBlockType(
    'wicked-invoicing/line-item-total',
    dyn( __('Line Item: Total','wicked-invoicing'), {
      parent,
      icon: 'money',
    } )
  );

  // Invoice-level blocks
  registerBlockType(
    'wicked-invoicing/start-date',
    dyn( __('Invoice Start Date','wicked-invoicing') )
  );

  registerBlockType(
    'wicked-invoicing/notes',
    dyn( __('Invoice Notes','wicked-invoicing') )
  );

  registerBlockType(
    'wicked-invoicing/subtotal',
    dyn( __('Invoice Subtotal','wicked-invoicing') )
  );

  registerBlockType(
    'wicked-invoicing/total',
    dyn( __('Invoice Total','wicked-invoicing') )
  );

  registerBlockType(
    'wicked-invoicing/tax-amount',
    dyn( __('Invoice Tax Amount','wicked-invoicing') )
  );

  registerBlockType(
    'wicked-invoicing/invoice-payment',
    dyn( __('Invoice Payment Buttons','wicked-invoicing'), {
      icon: 'money',
      desc: __('Display payment buttons for this invoice from active payment processors.', 'wicked-invoicing'),
      preview: __('Invoice payment buttons will be rendered on the front-end by active processors (Stripe, etc.).', 'wicked-invoicing'),
    } )
  );

} )( window.wp );
