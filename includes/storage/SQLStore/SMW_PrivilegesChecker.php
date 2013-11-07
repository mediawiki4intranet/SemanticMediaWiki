<?php

class SMWPrivilegesChecker {

	public static function canReadWikiPage( $DIWikiPage ) {
		$list = array( $DIWikiPage );
		return static::canReadWikiPages( $list );
	}

	public static function canReadWikiPages( &$diWikiPageList ) {
		$dbs = wfGetDB( DB_SLAVE );
		$result = true;
		if ( !empty( $diWikiPageList ) ) {
			$where = array();
			$map = array();
			foreach ( $diWikiPageList as $i => $wikiPage ) {
				if ( $wikiPage instanceof SMWDIWikiPage ) {
					$key = md5( $wikiPage->getNamespace() . '_' . $wikiPage->getDBkey() );
					$map[$key] = $i;
					$where[] = 'page_title = ' . $dbs->addQuotes( $wikiPage->getDBkey() ) . ' AND page_namespace = ' . $dbs->addQuotes( $wikiPage->getNamespace() );
				}
			}
			if ( !empty( $where ) ) {
				$res = $dbs->select( $dbs->tableName( 'page' ), '*', implode(' OR ', $where), 'SMW::canReadWikiPages' );
				while ( $row = $dbs->fetchObject( $res ) ) {
					$title = Title::newFromRow( $row );
					if ( !$title || !$title->userCanRead() ) {
						$key = md5( $title->getNamespace() . '_' . $title->getText() );
						unset( $diWikiPageList[$map[$key]] );
						$result = false;
					}
				}
			}
		}
		return $result;
	}
}
