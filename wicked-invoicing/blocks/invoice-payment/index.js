import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/invoice-payment', {
  edit,
  save: () => null  // dynamic block
} );
