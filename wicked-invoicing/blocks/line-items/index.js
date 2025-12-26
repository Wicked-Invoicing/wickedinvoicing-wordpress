import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';

registerBlockType( 'wicked-invoicing/line-items', {
  edit: Edit,
  save: () => null, // dynamic
} );
