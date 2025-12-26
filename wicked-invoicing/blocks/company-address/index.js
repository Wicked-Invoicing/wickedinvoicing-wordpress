import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/company-address', {
  edit,
  save: () => null  // dynamic block
} );
