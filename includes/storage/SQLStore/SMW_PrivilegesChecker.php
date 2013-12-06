<?php

class SMWPrivilegesChecker {

	public static function canReadWikiPage( $DIWikiPage ) {
		$list = array( $DIWikiPage );
		return static::canReadWikiPages( $list );
	}

	public static function canReadWikiPages( &$diWikiPageList ) {
		$dbr = wfGetDB( DB_SLAVE );
		$result = true;
		if ( !empty( $diWikiPageList ) ) {
			$where = array();
			$map = array();
			foreach ( $diWikiPageList as $i => $wikiPage ) {
				if ( $wikiPage instanceof SMWDIWikiPage ) {
					$key = $wikiPage->getNamespace() . '_' . $wikiPage->getDBkey();
					$map[$key] = $i;
					$where[] = 'page_title = ' . $dbr->addQuotes( $wikiPage->getDBkey() ) . ' AND page_namespace = ' . $dbr->addQuotes( $wikiPage->getNamespace() );
				}
			}
			if ( !empty( $where ) ) {
				$res = $dbr->select( 'page', '*', implode( ' OR ', $where ), 'SMW::canReadWikiPages' );
				foreach ( $res as $row ) {
					$title = Title::newFromRow( $row );
					if ( !$title || !$title->userCanRead() ) {
						$key = $row->page_namespace . '_' . $row->page_title;
						unset( $diWikiPageList[$map[$key]] );
						$result = false;
					}
				}
			}
		}
		return $result;
	}

}
