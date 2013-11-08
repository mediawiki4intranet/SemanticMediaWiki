<?php

class SMWQueryOptimizer {

	protected $cacheTemporaryTables = array();
	protected $cacheTemporaryTablesSubqueries = array();

	protected $enabled = false;

	protected $sizeReduce = 0;
	protected $depth = 0;

	public function __construct() {
		global $smwgQueryOptimizerEnabled;
		$this->enabled = $smwgQueryOptimizerEnabled;
	}

	public function isEnabled() {
		return $this->enabled;
	}

	/**
	 * Add relation between description and query
	 * @param SMWDescription $description
	 * @param SMWSQLStore3Query $query
	 */
	public function add( $description, &$query ) {
		if ( !$this->enabled ) {
			return;
		}
		$query->descriptionHash = $description->getHash();
	}

	/**
	 * Add temporary table for query with $queryNumber and $descriptionHash
	 * @param int $queryNumber
	 * @param string $descriptionHash
	 */
	public function addTemporaryTableQuery( $queryNumber, $descriptionHash ) {
		if ( !$this->enabled || $descriptionHash == null) {
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
		if ( !$this->enabled || $descriptionHash == null) {
			return null;
		}
		if ( isset( $this->cacheTemporaryTables[$descriptionHash] ) ) {
			return $this->cacheTemporaryTables[$descriptionHash];
		}
		return null;
	}

	/**
	 * Add temporary table for subquery with $queryNumber and $descriptionHash for parent query with $parentQueryNumber
	 * @param int $parentQueryNumber
	 * @param int $queryNumber
	 * @param string $descriptionHash
	 */
	public function addTemporaryTableSubquery( $parentQueryNumber, $queryNumber, $descriptionHash ) {
		if ( !$this->enabled || $descriptionHash == null) {
			return;
		}
		if ( !isset( $this->cacheTemporaryTablesSubqueries[$parentQueryNumber] ) ) {
			$this->cacheTemporaryTablesSubqueries[$parentQueryNumber] = array();
		}
		if ( !isset( $this->cacheTemporaryTablesSubqueries[$parentQueryNumber][$descriptionHash] ) ) {
			$this->cacheTemporaryTablesSubqueries[$parentQueryNumber][$descriptionHash] = $queryNumber;
		}
	}

	/**
	 * Add temporary table for subquery with $descriptionHash for parent query with $parentQueryNumber
	 * @param int $parentQueryNumber
	 * @param string $descriptionHash
	 */
	public function getTemporaryTableSubquery( $parentQueryNumber, $descriptionHash ) {
		if ( !$this->enabled || $descriptionHash == null) {
			return null;
		}
		if ( isset( $this->cacheTemporaryTablesSubqueries[$parentQueryNumber] ) &&
				isset( $this->cacheTemporaryTablesSubqueries[$parentQueryNumber][$descriptionHash] ) ) {
			return $this->cacheTemporaryTablesSubqueries[$parentQueryNumber][$descriptionHash];
		}
		return null;
	}

	/**
	 * Set destination query from sorce one
	 * @param SMWSQLStore3Query $dest
	 * @param SMWSQLStore3Query $source
	 */
	public function setQuery( SMWSQLStore3Query &$dest, SMWSQLStore3Query $source) {
		if ( !$this->enabled ) {
			return;
		}
		$dest->type = $source->type;
		$dest->jointable = $source->jointable;
		$dest->joinfield = $source->joinfield;
		$dest->from = $source->from;
		$dest->where = $source->where;
		$dest->components = $source->components;
		$dest->sortfields = $source->sortfields;
		if ( !empty( $dest->alias ) ) {
			if ( is_string( $dest->joinfield ) && strpos( $dest->joinfield, $source->alias ) !== false ) {
				$dest->joinfield = str_replace( $source->alias, $dest->alias, $dest->joinfield );
			}
			if ( strpos( $dest->where, $source->alias ) !== false ) {
				$dest->where = str_replace( $source->alias, $dest->alias, $dest->where );
			}
		}
		if ( isset( $source->jointype ) ) {
			$dest->jointype = $source->jointype;
		}
	}
}
