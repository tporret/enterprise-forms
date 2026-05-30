import { createBlock } from '@wordpress/blocks';
import { BlockList, BlockTools, ObserveTyping, WritingFlow } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface CanvasProps {
	formId: string;
	blocks: unknown[];
	onChangeBlocks: ( blocks: unknown[] ) => void;
}

const Canvas = ( { formId, blocks, onChangeBlocks }: CanvasProps ): JSX.Element => {
	const insertBlock = ( blockName: string ): void => {
		const fallbackName = blockName.includes( '/' ) ? blockName.split( '/' )[1] : 'field';
		onChangeBlocks( [
			...blocks,
			createBlock( blockName, {
				name: `${ fallbackName }_${ formId }_${ Date.now() }`,
			} ),
		] );
	};

	return (
		<div className="rounded-2xl border border-slate-200 bg-gray-50 p-4">
<div className="mb-4 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-4">
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/text-input' ) }>{ __( 'Text', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/email' ) }>{ __( 'Email', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/date' ) }>{ __( 'Date', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/phone' ) }>{ __( 'Phone', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/number' ) }>{ __( 'Number', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/url' ) }>{ __( 'URL', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/textarea' ) }>{ __( 'Textarea', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/checkbox' ) }>{ __( 'Checkbox', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/checkbox-group' ) }>{ __( 'Multi Checkbox', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/consent' ) }>{ __( 'Terms', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/rating' ) }>{ __( 'Rating', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/file-upload' ) }>{ __( 'File Upload', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/hidden' ) }>{ __( 'Hidden', 'enterprise-forms' ) }</Button>
			<span className="mx-1 text-slate-300" aria-hidden="true">|</span>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/select' ) }>{ __( 'Select', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/radio' ) }>{ __( 'Radio', 'enterprise-forms' ) }</Button>
			<span className="mx-1 text-slate-300" aria-hidden="true">|</span>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/page-break' ) }>{ __( 'Page Break', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/payment-checkout' ) }>{ __( 'Payment', 'enterprise-forms' ) }</Button>
			<Button variant="secondary" onClick={ () => insertBlock( 'ep/submit' ) }>{ __( 'Submit', 'enterprise-forms' ) }</Button>
			</div>

			<div className="ef-builder-canvas min-h-[520px] rounded-xl border border-slate-200 bg-white p-4">
				<BlockTools>
					<WritingFlow>
						<ObserveTyping>
							<BlockList />
						</ObserveTyping>
					</WritingFlow>
				</BlockTools>
			</div>
		</div>
	);
};

export default Canvas;
