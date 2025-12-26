import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/billing-address', {
  edit,
  save: () => null  // dynamic block
} );
