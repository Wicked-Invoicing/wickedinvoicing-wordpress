import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/company-phone', {
  edit,
  save: () => null  // dynamic block
} );
