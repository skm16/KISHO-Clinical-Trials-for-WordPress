/**
 * Clinical Trials block — editor UI.
 *
 * Provides InspectorControls for each block attribute and a
 * ServerSideRender preview so editors see live data in the block canvas.
 *
 * @package kisho-clinical-trials
 * @license GPL-2.0-or-later
 */

import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Edit component for the skmctf/trials block.
 *
 * @param {Object} props               Block props from WordPress.
 * @param {Object} props.attributes    Current attribute values.
 * @param {Function} props.setAttributes Attribute updater.
 * @return {JSX.Element} Editor UI.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { status, phase, state, perPage, showMap, columns } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Filter Settings', 'kisho-clinical-trials' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Status', 'kisho-clinical-trials' ) }
						value={ status }
						options={ [
							{ label: __( 'All statuses', 'kisho-clinical-trials' ), value: '' },
							{ label: __( 'Recruiting', 'kisho-clinical-trials' ), value: 'Recruiting' },
							{ label: __( 'Active, not recruiting', 'kisho-clinical-trials' ), value: 'Active, not recruiting' },
							{ label: __( 'Completed', 'kisho-clinical-trials' ), value: 'Completed' },
							{ label: __( 'Not yet recruiting', 'kisho-clinical-trials' ), value: 'Not yet recruiting' },
							{ label: __( 'Terminated', 'kisho-clinical-trials' ), value: 'Terminated' },
							{ label: __( 'Withdrawn', 'kisho-clinical-trials' ), value: 'Withdrawn' },
							{ label: __( 'Suspended', 'kisho-clinical-trials' ), value: 'Suspended' },
						] }
						onChange={ ( value ) => setAttributes( { status: value } ) }
					/>

					<SelectControl
						label={ __( 'Phase', 'kisho-clinical-trials' ) }
						value={ phase }
						options={ [
							{ label: __( 'All phases', 'kisho-clinical-trials' ), value: '' },
							{ label: __( 'Early Phase 1', 'kisho-clinical-trials' ), value: 'Early Phase 1' },
							{ label: __( 'Phase 1', 'kisho-clinical-trials' ), value: 'Phase 1' },
							{ label: __( 'Phase 1/Phase 2', 'kisho-clinical-trials' ), value: 'Phase 1/Phase 2' },
							{ label: __( 'Phase 2', 'kisho-clinical-trials' ), value: 'Phase 2' },
							{ label: __( 'Phase 2/Phase 3', 'kisho-clinical-trials' ), value: 'Phase 2/Phase 3' },
							{ label: __( 'Phase 3', 'kisho-clinical-trials' ), value: 'Phase 3' },
							{ label: __( 'Phase 4', 'kisho-clinical-trials' ), value: 'Phase 4' },
							{ label: __( 'Not applicable', 'kisho-clinical-trials' ), value: 'Not applicable' },
						] }
						onChange={ ( value ) => setAttributes( { phase: value } ) }
					/>

					<TextControl
						label={ __( 'State', 'kisho-clinical-trials' ) }
						help={ __( 'Filter by US state abbreviation, e.g. MA', 'kisho-clinical-trials' ) }
						value={ state }
						onChange={ ( value ) => setAttributes( { state: value } ) }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Display Settings', 'kisho-clinical-trials' ) }
					initialOpen={ false }
				>
					<RangeControl
						label={ __( 'Trials per page', 'kisho-clinical-trials' ) }
						value={ perPage }
						onChange={ ( value ) => setAttributes( { perPage: value } ) }
						min={ 1 }
						max={ 100 }
					/>

					<RangeControl
						label={ __( 'Columns', 'kisho-clinical-trials' ) }
						value={ columns }
						onChange={ ( value ) => setAttributes( { columns: value } ) }
						min={ 1 }
						max={ 4 }
					/>

					<ToggleControl
						label={ __( 'Show map', 'kisho-clinical-trials' ) }
						checked={ showMap }
						onChange={ ( value ) => setAttributes( { showMap: value } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="skmctf/trials"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
