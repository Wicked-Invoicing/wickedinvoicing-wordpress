import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/line-item-quantity', {
  edit,
  save: () => null  // dynamic block
} );
