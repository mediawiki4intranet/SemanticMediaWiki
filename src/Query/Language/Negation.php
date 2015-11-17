<?php

namespace SMW\Query\Language;

/**
 * Description of a negation of description.
 *
 * @license GNU GPL v2+
 * @author Vladimir Koptev, Vitaliy Filippov
 */

class Negation extends Description {
	protected $m_description;
	protected $m_true = false;
	protected $m_negated = true;

	public function __construct( Description $description = null ) {
		if ( !is_null( $description ) ) {
			$this->addDescription( $description );
		}
	}

	public function getDescription() {
		return $this->m_description;
	}

	public function isNegated() {
		return $this->m_negated;
	}

	public function addDescription( Description $description ) {
		if ( $description instanceof ThingDescription ) {
			$this->m_true = true;
			$this->m_description = null;
		}

		if ( !$this->m_true ) {
			if ( $description instanceof Negation ) { // absorb sub-conjunctions
				$this->m_negated = !$description->isNegated();
				$this->m_description = $description->getDescription();
			} else {
				$this->m_description = $description;
			}

			// move print descriptions downwards
			///TODO: This may not be a good solution, since it does modify $description and since it does not react to future changes
			$this->m_printreqs = array_merge( $this->m_printreqs, $description->getPrintRequests() );
			$description->setPrintRequests( array() );
		}
	}

	public function getQueryString( $asvalue = false ) {
		if ( $this->m_true ) {
			return '+';
		}

		$pre = $this->m_negated ? '!' : '';
		$result = $this->m_description->getQueryString( $asvalue );
		if ( !$this->m_description->isSingleton() ) {
			$result = '<q>' . $result . '</q>';
		}
		$result = $pre . $result;

		if ( $asvalue ) {
			$result =  ' <q>[[' . $result . ']]</q> ';
		} else {
			$result = ' <q>' . $result . '</q> ';
		}
		return $result;
	}

	public function isSingleton() {
		return true;
	}

	public function getSize() {
		if ( $this->m_negated ) {
			// Equal negation queries are cached because they're executed using temporary tables
			if ( isset( Description::$optimizedSizes[$this->getQueryString()] ) ) {
				return 0;
			}
			Description::$optimizedSizes[$this->getQueryString()] = true;
		}
		return $this->m_description->getSize();
	}

	public function getDepth() {
		return $this->m_description->getDepth();
	}

	public function getQueryFeatures() {
		return SMW_NEGATION_QUERY | $this->m_description->getQueryFeatures();
	}

	public function prune( &$maxsize, &$maxdepth, &$log ) {
		if ( $maxsize <= 0 ) {
			$log[] = $this->getQueryString();
			return new ThingDescription();
		}

		$prunelog = array();
		$newdepth = $maxdepth;
		$result = new Negation();

		$restdepth = $maxdepth;
		$result->addDescription( $this->m_description->prune( $maxsize, $restdepth, $prunelog ) );
		$newdepth = min( $newdepth, $restdepth );

		$log = array_merge( $log, $prunelog );
		$maxdepth = $newdepth;

		$desc = $result->getDescription();
		$desc->setPrintRequests( $this->getPrintRequests() );

		return $result;
	}
}
