<?php
/**
 * Updater for link tracking tables after a page edit.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStoreRecord;
use Wikimedia\Rdbms\LBFactory;

/**
 * Job to add recent change entries mentioning category membership changes
 *
 * This allows users to easily scan categories for recent page membership changes
 *
 * Parameters include:
 *   - pageId : page ID
 *   - revTimestamp : timestamp of the triggering revision
 *
 * Category changes will be mentioned for revisions at/after the timestamp for this page
 *
 * @since 1.27
 */
class CategoryMembershipChangeJob extends Job {
	/** @var int|null */
	private $ticket;

	private const ENQUEUE_FUDGE_SEC = 60;

	/**
	 * @param Title $title The title of the page for which to update category membership.
	 * @param string $revisionTimestamp The timestamp of the new revision that triggered the job.
	 * @return JobSpecification
	 */
	public static function newSpec( Title $title, $revisionTimestamp ) {
		return new JobSpecification(
			'categoryMembershipChange',
			[
				'pageId' => $title->getArticleID(),
				'revTimestamp' => $revisionTimestamp,
			],
			[
				'removeDuplicates' => true,
				'removeDuplicatesIgnoreParams' => [ 'revTimestamp' ]
			],
			$title
		);
	}

	/**
	 * Constructor for use by the Job Queue infrastructure.
	 * @note Don't call this when queueing a new instance, use newSpec() instead.
	 * @param Title $title Title of the categorized page.
	 * @param array $params Such latest revision instance of the categorized page.
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'categoryMembershipChange', $title, $params );
		// Only need one job per page. Note that ENQUEUE_FUDGE_SEC handles races where an
		// older revision job gets inserted while the newer revision job is de-duplicated.
		$this->removeDuplicates = true;
	}

	public function run() {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB();
		$dbw = $lb->getConnectionRef( DB_MASTER );

		$this->ticket = $lbFactory->getEmptyTransactionTicket( __METHOD__ );

		$page = WikiPage::newFromID( $this->params['pageId'], WikiPage::READ_LATEST );
		if ( !$page ) {
			$this->setLastError( "Could not find page #{$this->params['pageId']}" );
			return false; // deleted?
		}

		// Cut down on the time spent in waitForMasterPos() in the critical section
		$dbr = $lb->getConnectionRef( DB_REPLICA, [ 'recentchanges' ] );
		if ( !$lb->waitForMasterPos( $dbr ) ) {
			$this->setLastError( "Timed out while pre-waiting for replica DB to catch up" );
			return false;
		}

		// Use a named lock so that jobs for this page see each others' changes
		$lockKey = "{$dbw->getDomainID()}:CategoryMembershipChange:{$page->getId()}"; // per-wiki
		$scopedLock = $dbw->getScopedLockAndFlush( $lockKey, __METHOD__, 3 );
		if ( !$scopedLock ) {
			$this->setLastError( "Could not acquire lock '$lockKey'" );
			return false;
		}

		// Wait till replica DB is caught up so that jobs for this page see each others' changes
		if ( !$lb->waitForMasterPos( $dbr ) ) {
			$this->setLastError( "Timed out while waiting for replica DB to catch up" );
			return false;
		}
		// Clear any stale REPEATABLE-READ snapshot
		$dbr->flushSnapshot( __METHOD__ );

		$cutoffUnix = wfTimestamp( TS_UNIX, $this->params['revTimestamp'] );
		// Using ENQUEUE_FUDGE_SEC handles jobs inserted out of revision order due to the delay
		// between COMMIT and actual enqueueing of the CategoryMembershipChangeJob job.
		$cutoffUnix -= self::ENQUEUE_FUDGE_SEC;

		// Get the newest page revision that has a SRC_CATEGORIZE row.
		// Assume that category changes before it were already handled.
		$row = $dbr->selectRow(
			'revision',
			[ 'rev_timestamp', 'rev_id' ],
			[
				'rev_page' => $page->getId(),
				'rev_timestamp >= ' . $dbr->addQuotes( $dbr->timestamp( $cutoffUnix ) ),
				'EXISTS (' . $dbr->selectSQLText(
					'recentchanges',
					'1',
					[
						'rc_this_oldid = rev_id',
						'rc_source' => RecentChange::SRC_CATEGORIZE,
					]
				) . ')'
			],
			__METHOD__,
			[ 'ORDER BY' => [ 'rev_timestamp DESC', 'rev_id DESC' ] ]
		);
		// Only consider revisions newer than any such revision
		if ( $row ) {
			$cutoffUnix = wfTimestamp( TS_UNIX, $row->rev_timestamp );
			$lastRevId = (int)$row->rev_id;
		} else {
			$lastRevId = 0;
		}

		// Find revisions to this page made around and after this revision which lack category
		// notifications in recent changes. This lets jobs pick up were the last one left off.
		$encCutoff = $dbr->addQuotes( $dbr->timestamp( $cutoffUnix ) );
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$revQuery = $revisionStore->getQueryInfo();
		$res = $dbr->select(
			$revQuery['tables'],
			$revQuery['fields'],
			[
				'rev_page' => $page->getId(),
				"rev_timestamp > $encCutoff" .
					" OR (rev_timestamp = $encCutoff AND rev_id > $lastRevId)"
			],
			__METHOD__,
			[ 'ORDER BY' => [ 'rev_timestamp ASC', 'rev_id ASC' ] ],
			$revQuery['joins']
		);

		// Apply all category updates in revision timestamp order
		foreach ( $res as $row ) {
			$this->notifyUpdatesForRevision( $lbFactory, $page, $revisionStore->newRevisionFromRow( $row ) );
		}

		return true;
	}

	/**
	 * @param LBFactory $lbFactory
	 * @param WikiPage $page
	 * @param RevisionRecord $newRev
	 * @throws MWException
	 */
	protected function notifyUpdatesForRevision(
		LBFactory $lbFactory, WikiPage $page, RevisionRecord $newRev
	) {
		$config = RequestContext::getMain()->getConfig();
		$title = $page->getTitle();

		// Get the new revision
		if ( $newRev->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
			return;
		}

		// Get the prior revision (the same for null edits)
		if ( $newRev->getParentId() ) {
			$oldRev = MediaWikiServices::getInstance()
				->getRevisionLookup()
				->getRevisionById( $newRev->getParentId(), RevisionLookup::READ_LATEST );
			if ( !$oldRev || $oldRev->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
				return;
			}
		} else {
			$oldRev = null;
		}

		// Parse the new revision and get the categories
		$categoryChanges = $this->getExplicitCategoriesChanges( $page, $newRev, $oldRev );
		list( $categoryInserts, $categoryDeletes ) = $categoryChanges;
		if ( !$categoryInserts && !$categoryDeletes ) {
			return; // nothing to do
		}

		$catMembChange = new CategoryMembershipChange( $title, $newRev );
		$catMembChange->checkTemplateLinks();

		$batchSize = $config->get( 'UpdateRowsPerQuery' );
		$insertCount = 0;

		foreach ( $categoryInserts as $categoryName ) {
			$categoryTitle = Title::makeTitle( NS_CATEGORY, $categoryName );
			$catMembChange->triggerCategoryAddedNotification( $categoryTitle );
			if ( $insertCount++ && ( $insertCount % $batchSize ) == 0 ) {
				$lbFactory->commitAndWaitForReplication( __METHOD__, $this->ticket );
			}
		}

		foreach ( $categoryDeletes as $categoryName ) {
			$categoryTitle = Title::makeTitle( NS_CATEGORY, $categoryName );
			$catMembChange->triggerCategoryRemovedNotification( $categoryTitle );
			if ( $insertCount++ && ( $insertCount++ % $batchSize ) == 0 ) {
				$lbFactory->commitAndWaitForReplication( __METHOD__, $this->ticket );
			}
		}
	}

	private function getExplicitCategoriesChanges(
		WikiPage $page, RevisionRecord $newRev, RevisionRecord $oldRev = null
	) {
		// Inject the same timestamp for both revision parses to avoid seeing category changes
		// due to time-based parser functions. Inject the same page title for the parses too.
		// Note that REPEATABLE-READ makes template/file pages appear unchanged between parses.
		$parseTimestamp = $newRev->getTimestamp();
		// Parse the old rev and get the categories. Do not use link tables as that
		// assumes these updates are perfectly FIFO and that link tables are always
		// up to date, neither of which are true.
		$oldCategories = $oldRev
			? $this->getCategoriesAtRev( $page, $oldRev, $parseTimestamp )
			: [];
		// Parse the new revision and get the categories
		$newCategories = $this->getCategoriesAtRev( $page, $newRev, $parseTimestamp );

		$categoryInserts = array_values( array_diff( $newCategories, $oldCategories ) );
		$categoryDeletes = array_values( array_diff( $oldCategories, $newCategories ) );

		return [ $categoryInserts, $categoryDeletes ];
	}

	/**
	 * @param WikiPage $page
	 * @param RevisionRecord $rev
	 * @param string $parseTimestamp TS_MW
	 *
	 * @return string[] category names
	 */
	private function getCategoriesAtRev( WikiPage $page, RevisionRecord $rev, $parseTimestamp ) {
		$services = MediaWikiServices::getInstance();
		$options = $page->makeParserOptions( 'canonical' );
		$options->setTimestamp( $parseTimestamp );

		$output = $rev instanceof RevisionStoreRecord && $rev->isCurrent()
			? $services->getParserCache()->get( $page, $options )
			: null;

		if ( !$output || $output->getCacheRevisionId() !== $rev->getId() ) {
			$output = $services->getRevisionRenderer()->getRenderedRevision( $rev, $options )
				->getRevisionParserOutput();
		}

		// array keys will cast numeric category names to ints
		// so we need to cast them back to strings to avoid breaking things!
		return array_map( 'strval', array_keys( $output->getCategories() ) );
	}

	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		unset( $info['params']['revTimestamp'] ); // first job wins

		return $info;
	}
}
