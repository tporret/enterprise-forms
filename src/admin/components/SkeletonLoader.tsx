interface SkeletonLoaderProps {
	rows?: number;
}

const SkeletonLoader = ( { rows = 4 }: SkeletonLoaderProps ): JSX.Element => {
	return (
		<div className="animate-pulse p-6">
			<div className="mb-6 h-7 w-48 rounded bg-slate-200" />
			<div className="space-y-3">
				{ Array.from( { length: rows } ).map( ( _, index ) => (
					<div key={ index } className="h-16 rounded-xl border border-slate-200 bg-white" />
				) ) }
			</div>
		</div>
	);
};

export default SkeletonLoader;
