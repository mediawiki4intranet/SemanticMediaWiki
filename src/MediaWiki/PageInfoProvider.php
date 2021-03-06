<?php

namespace SMW\MediaWiki;

use Revision;
use SMW\PageInfo;
use User;
use WikiPage;

/**
 * Provide access to MediaWiki objects relevant for the predefined property
 * annotation process
 *
 * @ingroup SMW
 *
 * @license GNU GPL v2+
 * @since 1.9
 *
 * @author mwjames
 */
class PageInfoProvider implements PageInfo {

	/**
	 * @var WikiPage
	 */
	private $wikiPage = null;

	/**
	 * @var Revision
	 */
	private $revision = null;

	private $majorRev = null;

	/**
	 * @var User
	 */
	private $user = null;

	/**
	 * @since 1.9
	 *
	 * @param WikiPage $wikiPage
	 * @param Revision|null $revision
	 * @param User|null $user
	 */
	public function __construct( WikiPage $wikiPage, Revision $revision = null, User $user = null ) {
		$this->wikiPage = $wikiPage;
		$this->revision = $revision;
		$this->user = $user;
	}

	/**
	 * @since 1.9
	 *
	 * @return integer
	 */
	public function getModificationDate() {
		return $this->wikiPage->getTimestamp();
	}

	protected function getMajorRev() {
		if ( $this->majorRev ) {
			return $this->majorRev;
		}
		$pageId = $this->wikiPage->getTitle()->getArticleID();
		if ( $pageId ) {
			$db = wfGetDB( DB_SLAVE ); // FIXME DB_MASTER?
			$row = $db->selectRow( 'revision', Revision::selectFields(),
				array( 'rev_page' => $pageId, 'rev_minor_edit' => 0 ),
				__METHOD__,
				array( 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1 )
			);
			if ( $row ) {
				return ( $this->majorRev = new Revision( $row ) );
			}
		}
		return NULL;
	}

	public function getMajorModificationDate() {
		$rev = $this->getMajorRev();
		if ( $rev ) {
			return $rev->getTimestamp();
		}
		return $this->wikiPage->getTimestamp();
	}

	public function getMajorRevComment() {
		$rev = $this->getMajorRev();
		if ( $rev ) {
			return $rev->getComment();
		}
		return $this->wikiPage->getComment();
	}

	public function getComment() {
		return $this->wikiPage->getComment();
	}

	/**
	 * @note getFirstRevision() is expensive as it initiates a read on the
	 * revision table which is not cached
	 *
	 * @since 1.9
	 *
	 * @return integer
	 */
	public function getCreationDate() {
		return $this->wikiPage->getTitle()->getFirstRevision()->getTimestamp();
	}

	/**
	 * @note Using isNewPage() is expensive due to access to the database
	 *
	 * @since 1.9
	 *
	 * @return boolean
	 */
	public function isNewPage() {

		if ( $this->isFilePage() ) {
			return isset( $this->wikiPage->smwFileReUploadStatus ) ? !$this->wikiPage->smwFileReUploadStatus : false;
		}

		if ( $this->revision ) {
			return $this->revision->getParentId() === null;
		}

		return $this->wikiPage->getRevision()->getParentId() === null;
	}

	/**
	 * @since 1.9
	 *
	 * @return Title
	 */
	public function getLastEditor() {
		return $this->user ? $this->user->getUserPage() : null;
	}

	/**
	 * @since 1.9.1
	 *
	 * @return boolean
	 */
	public function isFilePage() {
		return $this->wikiPage instanceof \WikiFilePage;
	}

	/**
	 * @since 1.9.1
	 *
	 * @return string|null
	 */
	public function getMediaType() {
		return $this->isFilePage() ? $this->wikiPage->getFile()->getMediaType() : null;
	}

	/**
	 * @since 1.9.1
	 *
	 * @return string|null
	 */
	public function getMimeType() {
		return $this->isFilePage() ? $this->wikiPage->getFile()->getMimeType() : null;
	}

}
