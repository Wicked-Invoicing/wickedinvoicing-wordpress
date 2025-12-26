import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/total-discount-amount', {
  edit,
  save: () => null  // dynamic block
} );
