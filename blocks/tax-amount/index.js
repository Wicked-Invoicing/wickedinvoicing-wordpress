import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/tax-amount', {
  edit,
  save: () => null  // dynamic block
} );
