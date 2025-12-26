import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/notes', {
  edit,
  save: () => null  // dynamic block
} );
