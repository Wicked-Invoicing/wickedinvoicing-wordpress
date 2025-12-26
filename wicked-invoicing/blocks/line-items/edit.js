import { __ } from '@wordpress/i18n';
import {
  useBlockProps,
  InnerBlocks,
  InspectorControls,
  RichText,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl, RangeControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo, useCallback } from 'react';


const ALLOWED = [
  'wicked-invoicing/line-item-description',
  'wicked-invoicing/line-item-quantity',
  'wicked-invoicing/line-item-rate',
  'wicked-invoicing/line-item-discount',
  'wicked-invoicing/line-item-tax',
  'wicked-invoicing/line-item-total',
];

const TEMPLATE = [
  [ 'core/columns', { isStackedOnMobile: true }, [
    [ 'core/column', { width: '30%' }, [ [ 'wicked-invoicing/line-item-description' ] ] ],
    [ 'core/column', { width: '10%' }, [ [ 'wicked-invoicing/line-item-quantity' ] ] ],
    [ 'core/column', { width: '15%' }, [ [ 'wicked-invoicing/line-item-rate' ] ] ],
    [ 'core/column', { width: '15%' }, [ [ 'wicked-invoicing/line-item-discount' ] ] ],
    [ 'core/column', { width: '15%' }, [ [ 'wicked-invoicing/line-item-tax' ] ] ],
    [ 'core/column', { width: '15%' }, [ [ 'wicked-invoicing/line-item-total' ] ] ],
  ] ],
];

const DEFAULT_HEADERS = {
  'wicked-invoicing/line-item-description': __('Description','wicked-invoicing'),
  'wicked-invoicing/line-item-quantity':    __('Qty','wicked-invoicing'),
  'wicked-invoicing/line-item-rate':        __('Rate','wicked-invoicing'),
  'wicked-invoicing/line-item-discount':    __('Discount','wicked-invoicing'),
  'wicked-invoicing/line-item-tax':         __('Tax','wicked-invoicing'),
  'wicked-invoicing/line-item-total':       __('Total','wicked-invoicing'),
};

const DEFAULT_WIDTHS = {
  description: '30%',
  quantity: '10%',
  rate: '15%',
  discount: '15%',
  tax: '15%',
  total: '15%',
};

const NUMERIC_KEYS = new Set(['quantity','rate','discount','tax','total']);

function gridFromWidths(order, widthsObj = {}) {
  return order.map((name) => {
    const key = name.replace('wicked-invoicing/line-item-','');
    const w = (widthsObj && widthsObj[key]) || DEFAULT_WIDTHS[key] || '1fr';
    return `minmax(0, ${w})`;
  }).join(' ');
}

function getCellOrder(blocks) {
  const out = [];
  const walk = (nodes=[]) => {
    nodes.forEach((n) => {
      const name = n?.name || n?.blockName;
      if (name && ALLOWED.includes(name)) out.push(name);
      if (n?.innerBlocks?.length) walk(n.innerBlocks);
    });
  };
  walk(blocks);
  return out.length ? out : ALLOWED;
}

function fakeRows(n = 2) {
  const rows = [];
  for (let i = 0; i < n; i++) {
    rows.push({
      description: i === 0 ? __('Build Website for Customer', 'wicked-invoicing') : __('Secondary Descriptions for billed items', 'wicked-invoicing'),
      quantity: i === 0 ? 199 : 200,
      rate: 1,
      discount: i === 0 ? 1 : 0,
      tax: 0,
      total: i === 0 ? 198 : 200,
    });
  }
  return rows;
}

export default function Edit({ attributes, setAttributes, clientId }) {
  const {
    headers = DEFAULT_HEADERS,
    showPreview = true,
    previewRows = 2,
    //  define columnWidths with a safe default
    columnWidths = DEFAULT_WIDTHS,
  } = attributes;

  const blockProps = useBlockProps({ className: 'wi-line-items--editor' });

  const innerBlocks = useSelect(
    (select) => select('core/block-editor').getBlocks(clientId),
    [clientId]
  );

  const order = useMemo(() => getCellOrder(innerBlocks), [innerBlocks]);

  //  use widths safely
  const gridTemplate = useMemo(
    () => gridFromWidths(order, columnWidths),
    [order, columnWidths]
  );

  const onHeaderInput = useCallback((name, ev) => {
    const next = ev.currentTarget.textContent;
    setAttributes({ headers: { ...headers, [name]: next } });
  }, [headers, setAttributes]);

const preview = useMemo(() => {
  if (!showPreview) return null;
  const rows = fakeRows(previewRows);

  return (
    <div className="wi-line-items__preview">
      {rows.map((r, i) => (
        <div
          className="wi-line-items__row"
          data-index={i}
          key={i}
          style={{
            display: 'grid',
            gridTemplateColumns: gridTemplate,
            columnGap: '1rem',
            alignItems: 'start',
            marginBottom: '.5rem',
          }}
        >
          {order.map((name) => {
            const key = name.replace('wicked-invoicing/line-item-', '');

            let val = '';
            switch (key) {
              case 'description': val = r.description; break;
              case 'quantity':    val = r.quantity; break;
              case 'rate':        val = r.rate.toFixed(2); break;
              case 'discount':    val = r.discount.toFixed(2); break;
              case 'tax':         val = r.tax.toFixed(2); break;
              case 'total':       val = r.total.toFixed(2); break;
            }

            // styles
            const base = { minWidth: '0' };
            const numeric = { textAlign: 'right', whiteSpace: 'nowrap' };
            const desc = {
              whiteSpace: 'normal',
              overflowWrap: 'anywhere',
              wordBreak: 'break-word',
              hyphens: 'auto',
            };

            const style = key === 'description'
              ? { ...base, ...desc }
              : NUMERIC_KEYS.has(key) ? { ...base, ...numeric } : base;

            return (
              <div
                className={`wi-line-items__cell wi-line-items__cell--${key}`}
                key={`${name}-${i}`}
                style={style}
              >
                {val}
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}, [order, showPreview, previewRows, gridTemplate]);


  return (
    <div {...blockProps}>
      <InspectorControls>
        <PanelBody title={ __('Preview', 'wicked-invoicing') } initialOpen={true}>
          <ToggleControl
            label={ __('Show dummy rows in editor', 'wicked-invoicing') }
            checked={ !!showPreview }
            onChange={(v) => setAttributes({ showPreview: !!v })}
          />
          { showPreview && (
            <RangeControl
              label={ __('Dummy rows', 'wicked-invoicing') }
              min={1}
              max={6}
              value={ previewRows }
              onChange={(v) => setAttributes({ previewRows: v })}
            />
          ) }
        </PanelBody>

        <PanelBody title={ __('Column widths', 'wicked-invoicing') } initialOpen={ true }>
          { order.map((name) => {
            const key = name.replace('wicked-invoicing/line-item-','');
            const label = DEFAULT_HEADERS[name] || key;
            const widths = columnWidths || DEFAULT_WIDTHS; //  guard
            const raw = (widths[key] || DEFAULT_WIDTHS[key]).toString().replace('%','');
            const current = parseInt(raw, 10) || 0;
            return (
              <RangeControl
                key={name}
                label={`${label} (%)`}
                min={5}
                max={70}
                value={ current }
                onChange={(v) =>
                  setAttributes({
                    columnWidths: {
                      ...DEFAULT_WIDTHS,
                      ...(columnWidths || {}),
                      [key]: `${v}%`,
                    },
                  })
                }
                help={ __('Adjust width of this column', 'wicked-invoicing') }
              />
            );
          })}
        </PanelBody>
      </InspectorControls>

      <InnerBlocks
        allowedBlocks={ ALLOWED }
        template={ TEMPLATE }
        templateLock={ false }
      />

      <div className="wi-line-items__hint">
        { __('Drag the chips above to change column order. Use “+” to re-add a column.', 'wicked-invoicing') }
      </div>

      <div
        className="wi-line-items__header"
        style={{
          display:'grid',
          gridTemplateColumns: gridTemplate,
          columnGap:'1rem',
          alignItems:'start',
          fontWeight:600,
          paddingBottom:'.5rem',
          marginTop:'.75rem',
          marginBottom:'.5rem',
          borderBottom:'1px solid rgba(0,0,0,.1)'
        }}
      >
        { order.map((name) => {
          const key = name.replace('wicked-invoicing/line-item-','');
          const numericStyle = NUMERIC_KEYS.has(key) ? { textAlign:'right' } : undefined;
          return (
            <div className="wi-line-items__header-cell" key={name} style={ numericStyle }>
              <div
                className={`wi-line-items__cell wi-line-items__cell--${key}`}
                contentEditable
                suppressContentEditableWarning
                onInput={(e) => onHeaderInput(name, e)}
                spellCheck={false}
                style={{ outline:'none' }}
              >
                { headers?.[name] ?? DEFAULT_HEADERS[name] ?? '' }
              </div>
            </div>
          );
        }) }
      </div>

      { preview }
    </div>
  );
}
