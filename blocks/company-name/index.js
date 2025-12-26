import { registerBlockType } from '@wordpress/blocks';
import edit from './edit';

registerBlockType( 'wicked-invoicing/company-name', {
  edit,
  save: () => null
} );
