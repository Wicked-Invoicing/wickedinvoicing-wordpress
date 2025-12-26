import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/client-name', {
  edit,
  save: () => null  // dynamic block
} );
