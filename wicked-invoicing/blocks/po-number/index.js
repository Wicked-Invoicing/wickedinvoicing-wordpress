import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/po-number', {
  edit,
  save: () => null  // dynamic block
} );
