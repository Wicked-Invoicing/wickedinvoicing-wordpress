import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/total', {
  edit,
  save: () => null  // dynamic block
} );
