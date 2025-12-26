import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
  return (
    <div { ...useBlockProps() } className="wi-company-name-placeholder">
      { __( 'Company Name', 'wicked-invoicing' ) }
    </div>
  );
}
