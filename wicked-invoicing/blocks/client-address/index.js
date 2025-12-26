import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/client-address', {
  edit,
  save: () => null  // dynamic block
} );
