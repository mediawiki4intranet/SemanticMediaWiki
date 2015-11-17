<?php

namespace SMW\Query;

use SMW\SQLStore\QueryEngine\QuerySegment;

class Optimizer {
	protected $cacheTemporaryTables = array();

	protected $sizeReduce = 0;
	protected $depth = 0;

	/**
	 * Add temporary table for query with $queryNumber and $descriptionHash
	 * @param int $queryNumber
	 * @param string $descriptionHash
	 */
	public function addTemporaryTableQuery( $queryNumber, $descriptionHash ) {
		if ( !$descriptionHash ) {
			return;
		}
		if ( !isset( $this->cacheTemporaryTables[$descriptionHash] ) ) {
			$this->cacheTemporaryTables[$descriptionHash] = $queryNumber;
		}
	}

	/**
	 * Get temporary table for query with $descriptionHash if it exists else null
	 * @param string $descriptionHash
	 * @return null|int
	 */
	public function getTemporaryTableQuery( $descriptionHash ) {
		if ( !$descriptionHash ) {
			return null;
		}
		if ( isset( $this->cacheTemporaryTables[$descriptionHash] ) ) {
			return $this->cacheTemporaryTables[$descriptionHash];
		}
		return null;
	}

	/**
	 * Copy $source query to $dest
	 * @param $dest
	 * @param $source
	 */
	public function setQuery( QuerySegment &$dest, QuerySegment $source ) {
		$dest->type = $source->type;
		$dest->joinTable = $source->joinTable;
		$dest->joinfield = $source->joinfield;
		$dest->from = $source->from;
		$dest->where = $source->where;
		$dest->components = $source->components;
		$dest->sortfields = $source->sortfields;
		$dest->jointype = $source->jointype;
		if ( !empty( $dest->alias ) ) {
			if ( is_string( $dest->joinfield ) ) {
				$dest->joinfield = preg_replace( '/\b'.$source->alias.'\./s', $dest->alias.'.', $dest->joinfield );
			}
			$dest->where = preg_replace( '/\b'.$source->alias.'\./s', $dest->alias.'.', $dest->where );
			$dest->from = preg_replace( '/\b'.$source->alias.'\./s', $dest->alias.'.', $dest->from );
			foreach ( $dest->components as $qid => &$joinfield ) {
				$joinfield = preg_replace( '/\b'.$source->alias.'\./s', $dest->alias.'.', $joinfield );
			}
		}
	}
}
