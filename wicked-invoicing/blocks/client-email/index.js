import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/client-email', {
  edit,
  save: () => null  // dynamic block
} );
