import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/invoice-id', {
  edit,
  save: () => null  // dynamic block
} );
