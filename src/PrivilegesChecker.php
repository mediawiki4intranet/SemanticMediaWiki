<?php

namespace SMW;

class PrivilegesChecker {

	public static function canReadWikiPage( $DIWikiPage ) {
		$list = array( $DIWikiPage );
		$r = static::canReadWikiPages( $list );
		return reset( $r );
	}

	public static function canReadWikiPages( &$diWikiPageList ) {
		$dbr = wfGetDB( DB_SLAVE );
		$readability = array();
		if ( !empty( $diWikiPageList ) ) {
			$where = array();
			$map = array();
			foreach ( $diWikiPageList as $i => $wikiPage ) {
				if ( $wikiPage instanceof \SMW\DIWikiPage ) {
					$key = $wikiPage->getNamespace() . ':' . $wikiPage->getDBkey();
					$map[$key] = $i;
					$readability[$key] = true;
					$where[] = 'page_title = ' . $dbr->addQuotes( $wikiPage->getDBkey() ) .
						' AND page_namespace = ' . $wikiPage->getNamespace();
				}
			}
			if ( !empty( $where ) ) {
				$res = $dbr->select( 'page', '*', implode( ' OR ', $where ), __METHOD__ );
				foreach ( $res as $row ) {
					$key = $row->page_namespace . ':' . $row->page_title;
					$title = \Title::newFromRow( $row );
					$readability[$key] = $r = $title->userCan( 'read' );
					if ( !$r ) {
						unset( $diWikiPageList[$map[$key]] );
					}
				}
			}
		}
		return $readability;
	}

}
