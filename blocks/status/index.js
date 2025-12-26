import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/status', {
  edit,
  save: () => null  // dynamic block
} );
