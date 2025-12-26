import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/line-item-description', {
  edit,
  save: () => null  // dynamic block
} );
