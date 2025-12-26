import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
  // Use a tiny chip as the drag handle (keep it minimal)
  return (
    <div {...useBlockProps({ className: 'wi-cell-chip' })}>
      <span className="wi-cell-chip__label">Qty</span>
    </div>
  );
}
