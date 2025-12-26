import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/due-date', {
  edit,
  save: () => null  // dynamic block
} );
