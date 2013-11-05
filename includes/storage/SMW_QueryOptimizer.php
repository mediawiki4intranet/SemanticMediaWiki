<?php

class SMWQueryOptimizer {

    /**
     * @var SMWDescription[]
     */
	protected $storage = array();
	protected $cacheTemporalyTables = array();
	protected $cacheTemporalyTablesSubqueries = array();

	protected $enabled = false;
    
    const SIZE_LOG_PREFIX = 'size_';
    const DEPTH_LOG_PREFIX = 'depth_';
    protected $size = 0;
    protected $depth = 0;
    protected $log = array();

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
			$this->cacheTemporalyTables[$descriptionHash] = $queryNumber;

            $this->size += isset( $this->storage[$descriptionHash] ) ? $this->storage[$descriptionHash]->getSize() : 0;
            $this->depth += isset( $this->storage[$descriptionHash] ) ? $this->storage[$descriptionHash]->getDepth() : 0;
            $this->log[static::SIZE_LOG_PREFIX . $this->size]   = isset( $this->storage[$descriptionHash] ) ? $this->storage[$descriptionHash]->getQueryString() : '';
            $this->log[static::DEPTH_LOG_PREFIX . $this->depth] = &$this->log[static::SIZE_LOG_PREFIX . $this->depth];
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
    
    public function checkRestrictions( $maxsize, $maxdepth, $log ) {
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
