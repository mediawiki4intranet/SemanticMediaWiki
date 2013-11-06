<?php

class SMWQueryOptimizer {

	/**
	 * @var SMWDescription[]
	 */
	protected $storage = array();
	protected $cacheTemporalyTables = array();
	protected $cacheTemporalyTablesSubqueries = array();

	protected $enabled = false;

	protected $sizeReduce = 0;
	protected $depth = 0;

	public function __construct() {
		global $smwgQueryOptimazerEnabled;
		$this->enabled = $smwgQueryOptimazerEnabled;
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
		$key = $this->getKey( $description );
		if ( !isset( $this->storage[$key] ) ) {
			$this->storage[$key] = $description;
		}
		$query->descriptionHash = $key;
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
		if ( !isset( $this->cacheTemporalyTables[$descriptionHash] ) ) {
			if ( isset( $this->storage[$descriptionHash] ) ) {
				$this->depth = max ( $this->depth, $this->storage[$descriptionHash]->getDepth() );
			}
			$this->cacheTemporalyTables[$descriptionHash] = $queryNumber;
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
		if ( isset( $this->cacheTemporalyTables[$descriptionHash] ) ) {
			if ( isset( $this->storage[$descriptionHash] ) ) {
				$this->sizeReduce += $this->storage[$descriptionHash]->getSize();
			}
			return $this->cacheTemporalyTables[$descriptionHash];
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
		if ( !isset( $this->cacheTemporalyTablesSubqueries[$parentQueryNumber] ) ) {
			$this->cacheTemporalyTablesSubqueries[$parentQueryNumber] = array();
		}
		if ( !isset( $this->cacheTemporalyTablesSubqueries[$parentQueryNumber][$descriptionHash] ) ) {
			$this->cacheTemporalyTablesSubqueries[$parentQueryNumber][$descriptionHash] = $queryNumber;
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
		if ( isset( $this->cacheTemporalyTablesSubqueries[$parentQueryNumber] ) &&
				isset( $this->cacheTemporalyTablesSubqueries[$parentQueryNumber][$descriptionHash] ) ) {
			return $this->cacheTemporalyTablesSubqueries[$parentQueryNumber][$descriptionHash];
		}
		return null;
	}

	/**
	 * Set destination query from sorce one
	 * @param SMWSQLStore3Query $dest
	 * @param SMWSQLStore3Query $sorce
	 */
	public function setQuery( SMWSQLStore3Query &$dest, SMWSQLStore3Query $sorce) {
		if ( !$this->enabled ) {
			return;
		}
		$dest->type = $sorce->type;
		$dest->jointable = $sorce->jointable;
		$dest->joinfield = $sorce->joinfield;
		$dest->from = $sorce->from;
		$dest->where = $sorce->where;
		$dest->components = $sorce->components;
		$dest->sortfields = $sorce->sortfields;
		if ( !empty( $dest->alias ) ) {
			if ( is_string( $dest->joinfield ) && strpos( $dest->joinfield, $sorce->alias ) !== false ) {
				$dest->joinfield = str_replace( $sorce->alias, $dest->alias, $dest->joinfield );
			}
			if ( strpos( $dest->where, $sorce->alias ) !== false ) {
				$dest->where = str_replace( $sorce->alias, $dest->alias, $dest->where );
			}
		}
		if ( isset( $sorce->jointype ) ) {
			$dest->jointype = $sorce->jointype;
		}
	}

	public function getMetrics( $descriptionSize ) {
		return array( $descriptionSize - $this->sizeReduce, $this->depth );
	}

	/**
	 * Hash-key in storage
	 * @param SMWDescription $description
	 * @return string
	 */
	protected function getKey( $description ) {
		$hash = $description->getHash();
		if ( isset( $this->storage[$hash] ) ) {
			return $hash;
		}
		foreach ( $this->storage as $h => $row ) {
			if ( $description->equals( $row ) ) {
				$hash = $h;
				break;
			}
		}
		return $hash;
	}
}
