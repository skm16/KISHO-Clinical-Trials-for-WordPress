/**
 * Clinical Trials block — editor registration.
 *
 * @package kisho-clinical-trials
 * @license GPL-2.0-or-later
 */

import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from './block.json';

registerBlockType( metadata, {
	/**
	 * The block is server-rendered via PHP render_callback.
	 * The save function returns null so WordPress does not store
	 * block HTML in the database — live data is always fetched server-side.
	 */
	save: () => null,
	edit: Edit,
} );
