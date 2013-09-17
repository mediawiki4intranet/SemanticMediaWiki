<?php

namespace SMW\SQLStore\QueryEngine\Interpreter;

use SMW\Query\Language\Description;
use SMW\Query\Language\Negation;
use SMW\SQLStore\QueryEngine\QuerySegmentListBuilder;
use SMW\SQLStore\QueryEngine\DescriptionInterpreter;
use SMW\SQLStore\QueryEngine\QuerySegment;

/**
 * @license GNU GPL v2+
 * @since 2.2
 *
 * @author Vitaliy Filippov
 */
class NegationInterpreter implements DescriptionInterpreter {

	/**
	 * @var QuerySegmentListBuilder
	 */
	private $querySegmentListBuilder;

	/**
	 * @since 2.2
	 *
	 * @param QuerySegmentListBuilder $querySegmentListBuilder
	 */
	public function __construct( QuerySegmentListBuilder $querySegmentListBuilder ) {
		$this->querySegmentListBuilder = $querySegmentListBuilder;
	}

	/**
	 * @since 2.2
	 *
	 * @return boolean
	 */
	public function canInterpretDescription( Description $description ) {
		return $description instanceof Negation;
	}

	/**
	 * @since 2.2
	 *
	 * @param Description $description
	 *
	 * @return QuerySegment
	 */
	public function interpretDescription( Description $description ) {

		$query = new QuerySegment();
		$query->type = QuerySegment::Q_NEGATION;

		$subQueryId = $this->querySegmentListBuilder->buildQuerySegmentFor( $description->getDescription() );

		if ( $subQueryId >= 0 ) {
			$query->components[$subQueryId] = true;
		}

		// All subconditions failed, drop this as well.
		if ( count( $query->components ) == 0 ) {
			$query->type = QuerySegment::Q_NOQUERY;
		}

		return $query;
	}

}
