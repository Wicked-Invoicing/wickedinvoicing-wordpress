import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/subtotal', {
  edit,
  save: () => null  // dynamic block
} );
