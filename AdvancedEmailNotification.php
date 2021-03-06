<?php
$wgExtensionFunctions[] = 'wfSetupAdvancedEmailNotification';

$wgAutoloadClasses['DifferenceEngineUndev'] = __DIR__ . '/' . 'DifferenceEngineUndev.php';
$wgAutoloadClasses['TableDiffFormatterUndev'] = __DIR__ . '/' . 'TableDiffFormatterUndev.php';

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'AdvancedEmailNotification',
	'url' => 'https://github.com/Undev/MediaWiki-AdvancedEmailNotification',
	'description' => 'Adds the ability to watch for categories and nested pages. Replaces the standard message template to a more comfortable with inline diffs.',
	'version' => 1.0,
);
$wgMessagesDirs['AdvancedEmailNotification'] = __DIR__ . '/i18n';
//$wgExtensionMessagesFiles['AdvancedEmailNotification'] = __DIR__ . '/AdvancedEmailNotification.i18n.php';

class AdvancedEmailNotification
{
	const AEN_TABLE = 'watchlist';
	const AEN_TABLE_EXTENDED = 'watchlist_subpages';
	const AEN_VIEW = 'watchlist_extended';
	/**
	 * Used to prevent standard mail notifications
	 * @var boolean
	 */
	private $isOurUserMailer = false;
	/**
	 * Contains all page categories
	 * @var array
	 */
	private $pageCategories = array();
	/**
	 * All watchers who followed by category for this page
	 * @var array multidimensional
	 */
	private $categoryWatchers = array();
	/**
	 * All watchers who immediately followed for this page
	 * @var array
	 */
	private $pageWatchers = array();

	/**
	 * @var Revision
	 */
	private $newRevision;
	/**
	 * @var Revision
	 */
	private $oldRevision;
	/**
	 * @var Title
	 */
	private $title;

	private $editor;
	private $isNewArticle = false;

	function __construct()
	{
		global $wgHooks;

		$wgHooks['AlternateUserMailer'][] = $this;
		$wgHooks['SpecialWatchlistQuery'][] = $this;
		$wgHooks['ArticleSave'][] = $this;
		$wgHooks['ArticleSaveComplete'][] = $this;

		$wgHooks['GetPreferences'][] = $this;
	}

	function __toString()
	{
		return __CLASS__;
	}

	public function onGetPreferences(User $user, array &$preferences)
	{

		$preferences['AdvancedEmailNotification-diff-align'] = array(
			'type' => 'toggle',
			'label-message' => 'advancedemailnotification_settingsdiff', // a system message
			'section' => 'personal/email',
		);

		return true;
	}

	/**
	 * Prevent user from native mail notification using the mediator.
	 *
	 * @param $headers
	 * @param $to
	 * @param $from
	 * @param $subject
	 * @param $body
	 * @return bool
	 */
	public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
	{
		if (RequestContext::getMain()->getOutput()->isArticle() or
			RequestContext::getMain()->getOutput()->isArticleRelated() or
			RequestContext::getMain()->getTitle()->getDBkey() == SpecialPage::getTitleFor('Movepage')->getDBkey()
		) {
			if ($this->isOurUserMailer) {
				return true;
			} else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extend "Special:Watchlist" by adding changed pages from category.
	 *
	 * Replace standard watchlist table by mysql-view to provide observing
	 * changing pages in categories.
	 *
	 * @param $conds
	 * @param $tables
	 * @param $join_conds
	 * @param $fields
	 * @return bool
	 */
	public function onSpecialWatchlistQuery(&$conds, &$tables, &$join_conds, &$fields)
	{
		// Search in $tables array for watchlist position
		$position = array_search(self::AEN_TABLE, $tables);

		// Replace by View
		$tables[$position] = self::AEN_VIEW;

		// Don't forget to change alias in $join_conds array
		$join_conds[self::AEN_VIEW] = $join_conds[self::AEN_TABLE];
		// Remove old alias
		unset($join_conds[self::AEN_TABLE]);

		return true;
	}

	public function onArticleSave(WikiPage &$article, &$editor)
	{
		if (!$this->init()) {
			$this->isNewArticle = true;
		}

		return true;
	}

	public function init()
	{
		try {
			$this->newRevision = RequestContext::getMain()->getWikiPage()->getRevision();
		} catch (Exception $e) {
			return false;
		}

		if (is_null($this->newRevision)) {
			return false;
		}

		$this->oldRevision = $this->newRevision->getPrevious();
		$this->title = $this->newRevision->getTitle();
		$this->editor = User::newFromId($this->newRevision->getUser());

		return true;
	}

	private function getCategories(Title $title)
	{
		$categoriesTree = $this->array_values_recursive($title->getParentCategoryTree());
		$categoriesTree = array_unique($categoriesTree);
		$categories = array();
		foreach ($categoriesTree as $category) {
			if (strpos($category, ':')) {
				$category = explode(':', $category);
				$categories[] = $category[1];
			}
		}

		return $categories;
	}

	/**
	 * Recursive function returns all values from tree of categories.
	 *
	 * @param $array
	 * @return array
	 */
	private function array_values_recursive($array)
	{
		$arrayKeys = array();

		foreach ($array as $key => $value) {
			$arrayKeys[] = $key;
			if (!empty($value)) {
				$arrayKeys = array_merge($arrayKeys, $this->array_values_recursive($value));
			}
		}

		return $arrayKeys;
	}

	public function onArticleSaveComplete(&$article, &$editor)
	{
		if (!$this->init()) {
			return true;
		}

		// Getting all page categories
		$this->pageCategories = $this->getCategories($this->title);

		// Initialize Database
		$dbw = wfGetDB(DB_MASTER);

		// Search for all users who subscribed this page by received categories
		foreach ($this->pageCategories as $pageCategory) {
			$res = $dbw->select(array(self::AEN_TABLE), array('wl_user'),
				array(
					'wl_title' => $pageCategory,
					'wl_namespace' => NS_CATEGORY,
				), __METHOD__
			);

			// Collect user id and category name which he followed
			foreach ($res as $row) {
				$this->categoryWatchers[intval($row->wl_user)][] = $pageCategory;
			}

			$dbw->freeResult($res);
		}

		// Search for all users who subscribed this page by direct subscription.
		$res = $dbw->select(array(self::AEN_TABLE), array('wl_user'),
			array(
				'wl_namespace' => $this->title->getNamespace(),
				'wl_title' => $this->title->getDBkey(),
				'wl_notificationtimestamp IS NULL',
			), __METHOD__
		);

		foreach ($res as $row) {
			$this->pageWatchers[] = intval($row->wl_user);
		}

		$dbw->freeResult($res);

		if (!empty($this->pageWatchers)) {
			foreach ($this->pageWatchers as $userId) {
				$user = User::newFromId($userId);
				if ($this->isUserNotified($user)) {
					$this->notifyByMail($user);
				}
			}

			$this->updateTimestamp($this->pageWatchers);
		}

		if (!empty($this->categoryWatchers)) {
			foreach ($this->categoryWatchers as $userId => $watchedCategories) {
				$user = User::newFromId($userId);
				if ($this->isUserNotified($user)) {
					$this->notifyByMail($user, $watchedCategories);
				}
				$this->notifyByWatchlist($user);
			}
		}

		return true;
	}

	/**
	 * User has defined options in preferences which describe if user notified or not.
	 * Look for $this->editor to check if need to send to user a copy of email to other users.
	 *
	 * @param User $user
	 * @return bool
	 */
	private function isUserNotified(User $user)
	{
		global $wgEnotifWatchlist, // Email notifications can be sent for the first change on watched pages (user preference is shown and user needs to opt-in)
		       $wgShowUpdatedMarker; // Show "Updated (since my last visit)" marker in RC view, watchlist and history


		if (!$user->isEmailConfirmed()) {
			return false;
		}

		if (!$wgEnotifWatchlist and !$wgShowUpdatedMarker) {
			return false;
		}

		// Supporting feature "Email me when a page or file on my watchlist is changed"
		if (!$user->getOption('enotifwatchlistpages')) {
			return false;
		}

		// Supporting feature "Send me copies of emails I send to other users"
		if (!is_null($this->editor) and $user->getId() == $this->editor->getId()) {
			if (!$user->getOption('ccmeonemails')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param User $user
	 * @param $watchedType string
	 */
	private function notifyByMail(User $user, $watchedType = null)
	{
		global $wgSitename,
		       $wgPasswordSender;

		// Prevent standard mail notification
		$this->isOurUserMailer = true;

		// Create link for editor page
		$editorPageTitle = Title::makeTitle(NS_USER, $this->editor->getName());
		$editorLink = Linker::link($editorPageTitle, $this->editor->getName(), array(), array(), array('http'));

		// Create link for edit user watchlist
		$editWatchlistTitle = Title::makeTitle(NS_SPECIAL, 'Watchlist/Edit');
		$editWatchlistLink = Linker::link($editWatchlistTitle, wfMessage('advancedemailnotification_watchlisteditlink')->plain(), array(), array(), array('http'));

		// Create link to this page
		$pageLink = Linker::link($this->title, null, array(), array(), array('http'));
		$diffLink = Html::element('a', array('href' => $this->title->getCanonicalUrl('diff=' . $this->newRevision->getId())), 'diff');

		foreach ($this->pageCategories as $category) {
			$categoryTitle = Title::makeTitle(NS_CATEGORY, $category);
			$pageCategories[] = Linker::link($categoryTitle, $category, array(), array(), array('http'));
		}

		if (!empty($pageCategories)) {
			$pageCategories = implode(', ', $pageCategories);
		} else {
			$pageCategories = wfMessage('advancedemailnotification_categoriesempty')->plain();
		}

		if (is_null($watchedType)) {
			$subscribeCondition = wfMessage('advancedemailnotification_page')->plain();
		} else {
			foreach ($this->categoryWatchers[$user->getId()] as $category) {
				$categoryTitle = Title::makeTitle(NS_CATEGORY, $category);
				$categoryWatch[] = Linker::link($categoryTitle, $category, array(), array(), array('http'));
			}
			$subscribeCondition = wfMessage('advancedemailnotification_category')->plain() . implode(', ', $categoryWatch) . '.';
		}

		$dateofrev = RequestContext::getMain()->getLanguage()->userDate(time(), RequestContext::getMain()->getUser());
		$timeofrev = RequestContext::getMain()->getLanguage()->userTime(time(), RequestContext::getMain()->getUser());

		$content = $this->isNewArticle ? $this->newRevision->getContent()->getTextForSummary(100) :
			$this->getDiff($user->getOption('AdvancedEmailNotification-diff-align'));

		$keys = array(
			// For subject
			'{{siteName}}' => $wgSitename,
			'{{editorName}}' => $this->editor->getName(),
			'{{pageTitle}}' => $this->title->getText(),

			// For body
			'{{editorLink}}' => $editorLink,
			'{{pageLink}}' => $pageLink,
			'{{timestamp}}' => $dateofrev . ' ' . $timeofrev,
			'{{pageCategories}}' => $pageCategories,
			'{{diffLink}}' => $diffLink,
			'{{content}}' => $content,
			'{{subscribeCondition}}' => $subscribeCondition,
			'{{editWatchlistLink}}' => $editWatchlistLink,
		);

		$to = new MailAddress($user);
		$from = new MailAddress($this->editor);
		$subject = strtr(wfMessage('advancedemailnotification_emailsubject')->plain(), $keys);

		$css = file_get_contents('resources/src/mediawiki.action/mediawiki.action.history.diff.css', FILE_USE_INCLUDE_PATH);
		$body = strtr(wfMessage('advancedemailnotification_enotif_body')->plain(), $keys);
		$body .= "<style>{$css}</style>";

		$status = UserMailer::send($to, $from, $subject, $body, null, 'text/html; charset=UTF-8');

		if (!empty($status->errors)) {
			return false;
		}


		return true;
	}

	private function getDiff($isRearranged = null)
	{
		global $wgServer;

		if (!$this->oldRevision or !$this->newRevision) {
			return false;
		}

		$differenceEngine = new DifferenceEngineUndev(null, $this->oldRevision->getId(), $this->newRevision->getId());
		$differenceEngine->setOrder($isRearranged);
		$differenceEngine->showDiffPage(true);

		$html = RequestContext::getMain()->getOutput()->getHTML();
		$pattern = "/(?<=href=(\"|'))[^\"']+(?=(\"|'))/";
		$diff = preg_replace($pattern, "$wgServer$0", $html);

		return $diff;
	}

	private function updateTimestamp(array $watchers)
	{
		if (is_null($this->title)) {
			return false;
		}

		$dbw = wfGetDB(DB_MASTER);
		$fName = __METHOD__;
		$table = self::AEN_TABLE;
		$title = $this->title;

		foreach ($watchers as $watcher) {
			$dbw->onTransactionIdle(
				function () use ($table, $watcher, $title, $dbw, $fName) {
					$dbw->begin($fName);
					$dbw->update($table,
						array('wl_notificationtimestamp' => $dbw->timestamp()),
						array(
							'wl_user' => $watcher,
							'wl_namespace' => $title->getNamespace(),
							'wl_title' => $title->getDBkey(),
						), $fName
					);
					$dbw->commit($fName);
				}
			);
		}

		return true;
	}

	private function notifyByWatchlist(User $user)
	{
		$dbw = wfGetDB(DB_MASTER);

		foreach ($this->categoryWatchers[$user->getId()] as $category) {
			$res = $dbw->select(array(self::AEN_TABLE_EXTENDED),
				array(
					'wls_user',
					'wls_category',
					'wls_title',
				),
				array(
					'wls_user' => $user->getId(),
					'wls_category' => $category,
					'wls_title' => $this->title->getDBkey(),
				), __METHOD__
			);

			if ($res->numRows()) {
				$dbw->freeResult($res);
				continue;
			}

			$dbw->freeResult($res);

			$res = $dbw->insert('watchlist_subpages',
				array(
					'wls_user' => $user->getId(),
					'wls_namespace' => NS_MAIN,
					'wls_category' => $category,
					'wls_title' => $this->title->getDBkey(),
				), __METHOD__
			);

			if (!$res) {
				return false;
			}
		}

		return true;
	}
}

function wfSetupAdvancedEmailNotification()
{
	global $wgAdvancedEmailNotification;

	$wgAdvancedEmailNotification = new AdvancedEmailNotification;
}