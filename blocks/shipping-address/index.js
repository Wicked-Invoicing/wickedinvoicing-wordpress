import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/shipping-address', {
  edit,
  save: () => null  // dynamic block
} );
