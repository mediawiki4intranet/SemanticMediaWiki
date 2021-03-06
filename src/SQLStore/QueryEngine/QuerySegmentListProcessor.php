<?php

namespace SMW\SQLStore\QueryEngine;

use SMW\MediaWiki\Database;
use SMW\SQLStore\TemporaryIdTableCreator;
use SMWQuery as Query;
use RuntimeException;
use SMWSql3SmwIds;

/**
 * @license GNU GPL v2+
 * @since 2.2
 *
 * @author Markus Krötzsch
 * @author Jeroen De Dauw
 * @author mwjames
 */
class QuerySegmentListProcessor {

	/**
	 * @var Database
	 */
	private $connection;

	/**
	 * @var TemporaryIdTableCreator
	 */
	private $tempIdTableCreator;

	/**
	 * @var HierarchyTempTableBuilder
	 */
	private $hierarchyTempTableBuilder;

	/**
	 * Array of arrays of executed queries, indexed by the temporary table names
	 * results were fed into.
	 *
	 * @var array
	 */
	private $executedQueries = array();

	/**
	 * Cache of computed hierarchy queries for reuse ("catetgory/property value
	 * string" => "tablename").
	 *
	 * @var string[]
	 */
	private $hierarchyCache = array();

	/**
	 * Query mode copied from given query. Some submethods act differently when
	 * in Query::MODE_DEBUG.
	 *
	 * @var int
	 */
	private $queryMode;

	/**
	 * @var array
	 */
	private $querySegmentList = array();

	/**
	 * @param Database $connection
	 * @param TemporaryIdTableCreator $temporaryIdTableCreator
	 * @param HierarchyTempTableBuilder $hierarchyTempTableBuilder
	 */
	public function __construct( Database $connection, TemporaryIdTableCreator $temporaryIdTableCreator, HierarchyTempTableBuilder $hierarchyTempTableBuilder ) {
		$this->connection = $connection;
		$this->tempIdTableCreator = $temporaryIdTableCreator;
		$this->hierarchyTempTableBuilder = $hierarchyTempTableBuilder;
		$this->queryOptimizer = new \SMW\Query\Optimizer();
	}

	/**
	 * @since 2.2
	 *
	 * @return array
	 */
	public function getListOfResolvedQueries() {
		return $this->executedQueries;
	}

	/**
	 * @since 2.2
	 *
	 * @param &$querySegmentList
	 */
	public function setQuerySegmentList( &$querySegmentList ) {
		$this->querySegmentList =& $querySegmentList;
	}

	/**
	 * @since 2.2
	 *
	 * @param integer
	 */
	public function setQueryMode( $queryMode ) {
		$this->queryMode = $queryMode;
	}

	/**
	 * Process stored queries and change store accordingly. The query obj is modified
	 * so that it contains non-recursive description of a select to execute for getting
	 * the actual result.
	 *
	 * @param integer $id
	 * @throws RuntimeException
	 */
	public function doExecuteSubqueryJoinDependenciesFor( $id ) {

		$this->hierarchyTempTableBuilder->emptyHierarchyCache();
		$this->executedQueries = array();

		// Should never happen
		if ( !isset( $this->querySegmentList[$id] ) ) {
			throw new RuntimeException( "$id doesn't exist" );
		}

		$this->doResolveBySegment( $this->querySegmentList[$id] );
	}

	private function doResolveBySegment( QuerySegment &$query ) {

		$db = $this->connection;

		switch ( $query->type ) {
			case QuerySegment::Q_TABLE: // Normal query with conjunctive subcondition.
				foreach ( $query->components as $qid => $joinField ) {
					$subQuery = $this->querySegmentList[$qid];
					$this->doResolveBySegment( $subQuery );

					if ( $subQuery->joinTable !== '' ) { // Join with jointable.joinfield
						$joinType = $subQuery->jointype ? $subQuery->jointype : 'INNER';
						$t = $db->tableName( $subQuery->joinTable )." AS $subQuery->alias";
						if ( $subQuery->from ) {
							$t = "($t $subQuery->from)";
						}
						$query->from .= " $joinType JOIN $t ON $joinField=" . $subQuery->joinfield;
						if ( $joinType === 'LEFT' ) {
							$query->where .= ( ( $query->where === '' ) ? '' : ' AND ' ) . '(' . $subQuery->joinfield . ' IS NULL)';
						}
					} elseif ( $subQuery->joinfield !== '' ) { // Require joinfield as "value" via WHERE.
						$condition = '';

						foreach ( $subQuery->joinfield as $value ) {
							$condition .= ( $condition ? ' OR ' : '' ) . "$joinField=" . $db->addQuotes( $value );
						}

						if ( count( $subQuery->joinfield ) > 1 ) {
							$condition = "($condition)";
						}

						$query->where .= ( ( $query->where === '' ) ? '':' AND ' ) . $condition;
						$query->from .= $subQuery->from;
					} else { // interpret empty joinfields as impossible condition (empty result)
						$query->joinfield = ''; // make whole query false
						$query->joinTable = '';
						$query->where = '';
						$query->from = '';
						break;
					}

					if ( $subQuery->where !== '' ) {
						if ( $subQuery->jointype === 'LEFT' || $subQuery->jointype == 'LEFT OUTER' ) {
							$query->from .= ' AND (' . $subQuery->where . ')';
						} else {
							$query->where .= ( ( $query->where === '' ) ? '' : ' AND ' ) . '(' . $subQuery->where . ')';
						}
					}
				}

				$query->components = array();
			break;
			case QuerySegment::Q_CONJUNCTION:
				reset( $query->components );
				$key = false;

				// Pick one subquery as anchor point ...
				$ok = false;
				$components = $query->components;
				foreach ( $components as $key => $qid ) {
					$result = $this->querySegmentList[$key];
					if ( $result->type == QuerySegment::Q_CONJUNCTION ||
						$result->type == QuerySegment::Q_TABLE && $result->jointype !== 'LEFT' ) {
						$ok = true;
						break;
					}
				}

				if ( $ok ) {
					// Execute it first (may change jointable and joinfield, e.g. when making temporary tables)
					$this->doResolveBySegment( $result );
					unset( $components[$key] );
					// Copy it to our query (we don't want to damage the cached subquery)
					// But we must NOT change the alias because it may result in incorrect sortfields in upper queries
					$query->alias = $result->alias;
					$this->queryOptimizer->setQuery( $query, $result );
				} else {
					// Create a simple anchor query
					$query->type = QuerySegment::Q_TABLE;
					$query->joinTable = $db->tableName( SMWSql3SmwIds::TABLE_NAME );
					$query->joinfield = $query->alias.'.smw_id';
				}

				// ... and append to this query the remaining queries.
				foreach ( $components as $qid => $joinfield ) {
					$query->components[$qid] = $query->joinfield;
				}

				// Second execute, now incorporating remaining conditions.
				$this->doResolveBySegment( $query );
			break;
			case QuerySegment::Q_DISJUNCTION:
				if ( $this->queryMode !== Query::MODE_NONE ) {
					$db->query(
						$this->getCreateTempIDTableSQL( $db->tableName( $query->alias ) ),
						__METHOD__
					);
				}

				$this->executedQueries[$query->alias] = array();

				foreach ( $query->components as $qid => $joinField ) {
					$subQuery = $this->querySegmentList[$qid];

					if ( empty( $subQuery->descriptionHash ) ) {
						die( 'BUG: descriptionHash of subquery is empty' );
					}
					$cachedQueryId = $this->queryOptimizer->getTemporaryTableQuery( $subQuery->descriptionHash );
					if ( $cachedQueryId ) {
						$this->queryOptimizer->setQuery( $subQuery, $this->querySegmentList[$cachedQueryId] );
					} else {
						$this->doResolveBySegment( $subQuery );
						$this->queryOptimizer->addTemporaryTableQuery( $subQuery->queryNumber, $subQuery->descriptionHash );
					}
					$sql = '';

					if ( $subQuery->joinTable !== '' ) {
						if ( $subQuery->jointype === 'LEFT' ) {
							// Negation as an disjunction argument
							$sub = $subQuery->anchor;
							$sql = 'INSERT ' . 'IGNORE ' . 'INTO ' . $db->tableName( $query->alias ) .
								   " SELECT DISTINCT $sub.smw_id FROM " . $db->tableName( SMWSql3SmwIds::TABLE_NAME ) .
								   " AS $sub LEFT JOIN " . $db->tableName( $subQuery->joinTable ) .
								   " AS $subQuery->alias ON $subQuery->joinfield = $sub.smw_id" .
								   ( $subQuery->where ? " AND $subQuery->where" : '' ) . " WHERE $subQuery->joinfield IS NULL";
						} else {
							$sql = 'INSERT ' . 'IGNORE ' . 'INTO ' .
								   $db->tableName( $query->alias ) .
								   " SELECT DISTINCT $subQuery->joinfield FROM " . $db->tableName( $subQuery->joinTable ) .
								   " AS $subQuery->alias $subQuery->from" . ( $subQuery->where ? " WHERE $subQuery->where":'' );
						}
					} elseif ( $subQuery->joinfield !== '' ) {
						// NOTE: this works only for single "unconditional" values without further
						// WHERE or FROM. The execution must take care of not creating any others.
						$values = '';

						// This produces an error on postgres with
						// pg_query(): Query failed: ERROR:  duplicate key value violates
						// unique constraint "sunittest_t3_pkey" DETAIL:  Key (id)=(274) already exists.

						foreach ( $subQuery->joinfield as $value ) {
							$values .= ( $values ? ',' : '' ) . '(' . $db->addQuotes( $value ) . ')';
						}

						$sql = 'INSERT ' . 'IGNORE ' .  'INTO ' . $db->tableName( $query->alias ) . " (id) VALUES $values";
					} // else: // interpret empty joinfields as impossible condition (empty result), ignore
					if ( $sql ) {
						$this->executedQueries[$query->alias][] = $sql;

						if ( $this->queryMode !== Query::MODE_NONE ) {
							$db->query(
								$sql,
								__METHOD__
							);
						}
					}
				}

				$query->type = QuerySegment::Q_TABLE;
				$query->where = '';
				$query->components = array();

				$query->joinTable = $query->alias;
				$query->joinfield = "$query->alias.id";
				$query->sortfields = array(); // Make sure we got no sortfields.
				// TODO: currently this eliminates sortkeys, possibly keep them (needs different temp table format though, maybe not such a good thing to do)
			break;
			case QuerySegment::Q_NEGATION:
				if ( !empty( $query->anchor ) ) {
					break;
				}
				reset( $query->components );
				list( $key, $tmp ) = each( $query->components );
				$result = $this->querySegmentList[$key];
				unset( $query->components[$key] );
				if ( $query->components ) {
					die( 'BUG: negation contains more than 1 component' );
				}

				// Execute it first (may change jointable and joinfield, e.g. when making temporary tables)
				$this->doResolveBySegment( $result );

				if ( $result->joinTable === '' && $result->joinfield !== '' ) {
					// NOTE: this works only for single "unconditional" values without further
					// WHERE or FROM. The execution must take care of not creating any others.
					$values = '';
					foreach ( $result->joinfield as $value ) {
						$values .= ( $values ? ',' : '' ) . '(' . $db->addQuotes( $value ) . ')';
					}
					$sql = 'INSERT ' . 'IGNORE ' . 'INTO ' . $db->tableName( $query->alias ) . " (id) VALUES $values";
				} else {
					// $result is now a Q_TABLE (and it couldn't be another negation!)
					$this->queryOptimizer->setQuery( $query, $result );
				}

				// Free alias for the anchor table (used only in bad case when negation is part of a disjunction)
				$query->anchor = $result->alias;
				$query->type = QuerySegment::Q_TABLE;
				$query->jointype = 'LEFT';
				$query->sortfields = array(); // Make sure we got no sortfields.
				// FIXME: Remove sort joins from $query->from
			break;
			case QuerySegment::Q_PROP_HIERARCHY:
			case QuerySegment::Q_CLASS_HIERARCHY: // make a saturated hierarchy
				$this->resolveHierarchyForSegment( $query );
			break;
			case QuerySegment::Q_VALUE:
			break; // nothing to do
		}
	}

	/**
	 * Find subproperties or subcategories. This may require iterative computation,
	 * and temporary tables are used in many cases.
	 *
	 * @param QuerySegment $query
	 */
	private function resolveHierarchyForSegment( QuerySegment &$query ) {

		switch ( $query->type ) {
			case QuerySegment::Q_PROP_HIERARCHY:
				$type = 'property';
				break;
			case QuerySegment::Q_CLASS_HIERARCHY:
				$type = 'class';
				break;
		}

		list( $smwtable, $depth ) = $this->hierarchyTempTableBuilder->getHierarchyTableDefinitionForType(
			$type
		);

		if ( $depth <= 0 ) { // treat as value, no recursion
			$query->type = QuerySegment::Q_VALUE;
			return;
		}

		$values = '';
		$valuecond = '';

		$db = $this->connection;

		foreach ( $query->joinfield as $value ) {
			$values .= ( $values ? ',':'' ) . '(' . $db->addQuotes( $value ) . ')';
			$valuecond .= ( $valuecond ? ' OR ':'' ) . 'o_id=' . $db->addQuotes( $value );
		}

		// Try to safe time (SELECT is cheaper than creating/dropping 3 temp tables):
		$res = $db->select( $smwtable, 's_id', $valuecond, __METHOD__, array( 'LIMIT' => 1 ) );

		if ( !$db->fetchObject( $res ) ) { // no subobjects, we are done!
			$db->freeResult( $res );
			$query->type = QuerySegment::Q_VALUE;
			return;
		}

		$db->freeResult( $res );
		$tablename = $db->tableName( $query->alias );
		$this->executedQueries[$query->alias] = array( "Recursively computed hierarchy for element(s) $values." );
		$query->joinTable = $query->alias;
		$query->joinfield = "$query->alias.id";

		$db->query(
			$this->getCreateTempIDTableSQL( $tablename ),
			__METHOD__
		);

		$this->hierarchyTempTableBuilder->createHierarchyTempTableFor(
			$type,
			$tablename,
			$values
		);
	}

	/**
	 * After querying, make sure no temporary database tables are left.
	 * @todo I might be better to keep the tables and possibly reuse them later
	 * on. Being temporary, the tables will vanish with the session anyway.
	 */
	public function cleanUp() {

		foreach ( $this->executedQueries as $table => $log ) {
			$this->connection->query(
				"DROP TEMPORARY TABLE " . $this->connection->tableName( $table ),
				__METHOD__
			);
		}
	}

	/**
	 * Get SQL code suitable to create a temporary table of the given name, used to store ids.
	 * MySQL can do that simply by creating new temporary tables. PostgreSQL first checks if such
	 * a table exists, so the code is ready to reuse existing tables if the code was modified to
	 * keep them after query answering. Also, PostgreSQL tables will use a RULE to achieve built-in
	 * duplicate elimination. The latter is done using INSERT IGNORE in MySQL.
	 *
	 * @param string $tableName
	 *
	 * @return string
	 */
	private function getCreateTempIDTableSQL( $tableName ) {
		return $this->tempIdTableCreator->getSqlToCreate( $tableName );
	}

}
