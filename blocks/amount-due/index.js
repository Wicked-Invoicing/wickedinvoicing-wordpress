import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/amount-due', {
  edit,
  save: () => null  // dynamic block
} );
