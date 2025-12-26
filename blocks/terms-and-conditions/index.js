import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/terms-and-conditions', {
  edit,
  save: () => null  // dynamic block
} );
