import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/line-item-tax', {
  edit,
  save: () => null  // dynamic block
} );
