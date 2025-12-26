import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/company-email', {
  edit,
  save: () => null  // dynamic block
} );
