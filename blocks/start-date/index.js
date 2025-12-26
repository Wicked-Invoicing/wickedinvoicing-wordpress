import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/start-date', {
  edit,
  save: () => null  // dynamic block
} );
