<?php

/**
 * FeedGator content-sync driver for native Joomla content (com_content).
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from plugins/com_content/com_content.php + contentmodel.php.
 * All `J_VERSION < 1.6` branches (Joomla 1.5 sections-based content) have
 * been dropped as dead code - Joomla 6 no longer has sections at all.
 *
 * RISK NOTE: com_content's Article table/model internals have changed
 * repeatedly across Joomla 3 -> 4 -> 5 -> 6 (workflow states, associations,
 * custom fields, tags). This conversion keeps to the stable, long-lived
 * #__content columns (state, catid, access, featured, alias, created_by)
 * and Joomla's public Table API rather than any internal com_content
 * model class, which is the more future-proof integration point - but it
 * has NOT been run against a live Joomla 6 site. Test article creation
 * (including featured/category assignment and duplicate-alias handling)
 * against your actual Joomla 6 + com_content before relying on this in
 * production.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Plugin;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

class plgFeedgatorContent
{
    public $title = 'Joomla Content';
    public $extension = 'com_content';
    public $table = '#__content';
    public $state = 'state';
    public $sectionid = 0;
    public $data = null;
    public $params = null;
    public bool $componentInstalled = true;

    private $row;
    private $model;

    public function __construct()
    {
        $this->model = FeedgatorFactory::getPluginModel();
        $this->model->setExt($this->extension);
    }

    public function setData($data)
    {
        $this->data      = $data;
        $this->sectionid = -1 * ($data->id ?? 0);
    }

    public function getData()
    {
        if (!$this->data) {
            $this->model->setExt($this->extension);
            $this->setData($this->model->getPluginData());
        }

        return $this->data;
    }

    public function getParams($feedId = -1)
    {
        if (!$this->params) {
            $this->params = new Registry($this->model->getParams($feedId));
        }

        return $this->params;
    }

    public function componentCheck()
    {
        return true;
    }

    private function db()
    {
        return Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
    }

    public function countContentQuery()
    {
        return 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE id IN (%s) AND (' . $this->state . ' = 1 OR ' . $this->state . ' = 0)';
    }

    public function getContentItem($id)
    {
        $db    = $this->db();
        $query = 'SELECT * FROM ' . $this->table . ' WHERE id = ' . $db->quote($id) . ' AND (' . $this->state . ' = 1 OR ' . $this->state . ' = 0)';
        $db->setQuery($query);

        return $db->loadAssoc() ?: false;
    }

    public function getContentLink($id)
    {
        return Route::_('index.php?option=' . $this->extension . '&task=article.edit&id=' . $id);
    }

    /**
     * Returns a COUNT(*) subquery expression for articles matching
     * $where, using the same FROM/JOIN structure as
     * getContentItemsQuery() (the WHERE clause built by
     * HtmlView::buildWhere() references the c./fi./fg. aliases set up
     * here, same as that method). This method was missing entirely -
     * a similarly-named countContentQuery() exists but takes no $where
     * argument and serves a different, ID-list-based purpose - calling
     * it here would have been wrong even if the name had matched.
     */
    public function countContentItems($where)
    {
        $db = $this->db();
        $this->_buildWhere($where);

        return '(SELECT COUNT(*)'
            . ' FROM ' . $this->table . ' AS c'
            . ' LEFT JOIN #__categories AS cc ON cc.id = c.catid'
            . ' LEFT JOIN #__users AS u ON u.id = c.checked_out'
            . ' LEFT JOIN #__users AS v ON v.id = c.created_by'
            . ' LEFT JOIN #__feedgator_imports AS fi ON fi.content_id = c.id AND fi.plugin = ' . $db->quote($this->extension)
            . ' LEFT JOIN #__feedgator AS fg ON fg.id = fi.feed_id'
            . $where . ')';
    }

    public function getContentItemsQuery($where)
    {
        $db = $this->db();
        $this->_buildWhere($where);

        return '(SELECT c.id AS id, c.title AS title, c.' . $this->state . ' AS state, c.created AS created,'
            . ' c.catid AS catid, c.publish_up AS publish_up, c.publish_down AS publish_down,'
            . ' c.created_by_alias AS created_by_alias, c.created_by AS created_by, c.access AS access,'
            . ' al.title AS access_level, c.ordering AS ordering,'
            . ' c.checked_out AS checked_out, cc.title AS cat_name, u.name AS editor, c.featured AS frontpage,'
            . ' \'null\' AS section_name, v.name AS author, fi.feed_id AS feedid, fg.title AS feed_title,'
            . ' fg.content_type AS content_type'
            . ' FROM ' . $this->table . ' AS c'
            . ' LEFT JOIN #__categories AS cc ON cc.id = c.catid'
            . ' LEFT JOIN #__users AS u ON u.id = c.checked_out'
            . ' LEFT JOIN #__users AS v ON v.id = c.created_by'
            . ' LEFT JOIN #__viewlevels AS al ON al.id = c.access'
            . ' LEFT JOIN #__feedgator_imports AS fi ON fi.content_id = c.id AND fi.plugin = ' . $db->quote($this->extension)
            . ' LEFT JOIN #__feedgator AS fg ON fg.id = fi.feed_id'
            . $where . ')';
    }

    public function getFeedItems($where)
    {
        $db = $this->db();
        $this->_buildWhere($where);
        $query = 'SELECT fg.*, cc.title AS cat_name, u.name AS editor FROM #__feedgator fg'
            . ' LEFT JOIN #__categories AS cc ON cc.id = fg.catid'
            . ' LEFT JOIN #__users AS u ON u.id = fg.checked_out '
            . $where;
        $db->setQuery($query);

        return $db->loadObjectList();
    }

    /**
     * @param   string       $type    'internal', or a column name (e.g. 'alias') to look up $string against.
     * @param   string|null  $string
     */
    public function findDuplicates($type, $string)
    {
        $db = $this->db();

        if ($type === 'internal') {
            $this->getParams();
            $ignore = $this->params->get('ignore');

            $query = 'SELECT ' . $db->quote($this->extension) . ' AS content_type, fg.title AS feed_title, c.title, c.alias, COUNT(*) AS num,'
                . ' GROUP_CONCAT(CONCAT_WS(\'|\',CONVERT(c.id,CHAR(11)),CONVERT(c.catid,CHAR(11)),CONVERT(c.catid,CHAR(11)),c.title) ORDER BY c.id ASC SEPARATOR \'||\') AS results'
                . ' FROM ' . $this->table . ' AS c'
                . ' INNER JOIN #__feedgator_imports AS fi ON fi.content_id = c.id AND fi.plugin = ' . $db->quote($this->extension)
                . ' INNER JOIN #__feedgator AS fg ON fg.id = fi.feed_id'
                . ' WHERE (c.' . $this->state . ' = 1 OR c.' . $this->state . ' = 0)'
                . ($ignore ? ' AND c.id NOT IN (' . $ignore . ')' : '')
                . ' GROUP BY alias'
                . ' HAVING ( COUNT(*) > 1 )';

            return '(' . $query . ')';
        }

        $query = 'SELECT id FROM ' . $this->table . ' WHERE ' . $type . ' = ' . $db->quote($string) . ' AND (' . $this->state . ' = 1 OR ' . $this->state . ' = 0)';
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * Joomla 1.5 "sections" no longer exist - kept as a no-op returning an
     * empty/placeholder option for backwards compatibility with the admin
     * form field classes that call it.
     */
    public function getSectionList(&$fgParams)
    {
        return [(object) ['id' => -1, 'title' => '- ' . Text::_('FG_NO_SECTIONS_IN_MODERN_JOOMLA') . ' -']];
    }

    public function getCategoryList(&$fgParams)
    {
        $db = $this->db();

        // Two fixes here:
        // 1. Exclude trashed (published = -2) categories entirely - an
        //    import destination that's been trashed should never be a
        //    selectable option, since Joomla's own Article Manager list
        //    silently excludes any article whose category is trashed
        //    (or missing), making the resulting articles invisible with
        //    no filter able to surface them.
        // 2. Indent nested categories by depth (using the existing
        //    `level` column) so that two categories sharing the same
        //    title under different parents - a real case on this site,
        //    where two categories were both named "Scotland" - are
        //    actually distinguishable in the dropdown, rather than
        //    showing as two identical, unpickable-apart options.
        $query = 'SELECT id, title, level FROM #__categories'
            . ' WHERE extension = ' . $db->quote('com_content')
            . ' AND published != -2'
            . ' ORDER BY lft';
        $db->setQuery($query);
        $categories = $db->loadObjectList();

        foreach ($categories as $category) {
            $category->title = str_repeat('- ', max(0, (int) $category->level - 1)) . $category->title;
        }

        $options = [(object) ['id' => -1, 'title' => Text::_('FG_SELECT_JOOMLA_CATEGORY')]];

        return array_merge($options, $categories);
    }

    public function getCatSelectLists($filter, &$fgParams)
    {
        $db = $this->db();
        $this->getData();
        $prefix = $this->data->id . '_';

        $categories = [(object) ['value' => $prefix . '0', 'text' => '- ' . Text::_('FG_SELECT_JOOMLA_CATEGORY') . ' -']];

        $query = 'SELECT CONCAT(' . $db->quote($prefix) . ', cc.id) AS value, cc.title AS text'
            . ' FROM #__categories AS cc'
            . (string) $filter;
        $db->setQuery($query);

        return array_merge($categories, $db->loadObjectList());
    }

    public function getSecSelectLists(&$fgParams)
    {
        // Joomla 1.5 sections no longer exist.
        return [(object) ['value' => -1, 'text' => '- ' . Text::_('FG_NO_SECTIONS_IN_MODERN_JOOMLA') . ' -']];
    }

    public function getFieldNames(&$content)
    {
        $db    = $this->db();
        $query = 'SELECT title FROM #__categories WHERE id = ' . $db->quote($content['catid']);
        $db->setQuery($query);

        return $db->loadResult();
    }

    public function getSectionCategories(&$fgParams)
    {
        return [$this->sectionid => $this->getCategoryList($fgParams)];
    }

    /**
     * Saves (creates or updates) a native Joomla article from processed
     * feed content.
     *
     * @param   array  $content   Prepared article field data (title, alias, introtext, ...).
     * @param   object $fgParams  Joomla\CMS\Form\Form-like feed parameter object.
     */
    public function save(&$content, &$fgParams)
    {
        $app = Factory::getApplication();

        $row = $this->row ?? Factory::getApplication()->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator');
        $this->row = $row;

        PluginHelper::importPlugin('content');

        if (!$row->bind($content)) {
            $content['mosMsg'] = $this->title . ' ***ERROR: bind ' . $row->getError();

            return false;
        }

        $row->id     = (int) $row->id;
        $isNew       = $row->id < 1;
        $row->featured = (int) $fgParams->getValue('front_page');
        $row->language = '*';
        // Joomla's Article Manager list view inner-joins #__viewlevels to
        // display the access level name - an article with access=0 (or
        // any other value with no matching #__viewlevels row) silently
        // vanishes from that list entirely, with no filter able to
        // surface it, since the join itself excludes the row. That
        // happens if the feed's "Access" field was left at its "Select
        // Group"/blank placeholder option, which casts to (int) '' = 0.
        // Falling back to 1 (Public, always present in a stock Joomla
        // install) avoids silently orphaning imported articles this way.
        $row->access   = (int) $fgParams->getValue('access') ?: 1;
        // Hide the intro text on the full article view unless "only make introtext" is set.
        $row->attribs = $fgParams->getValue('onlyintro') ? '' : '{"show_intro":"0"}';

        // com_content's #__content table has several JSON-shaped columns
        // (images, urls, metadata) that are NOT NULL with no default in
        // modern Joomla - the same class of bug fixed earlier for this
        // component's own tables (see FeedTable.php's docblock). $content
        // never supplies these (FeedGator has no concept of them), so
        // ArticleTable's own bare property default (null) would
        // otherwise get written straight into a NOT NULL column.
        // Unconditional, not a `?: '{}'` fallback - deliberately not
        // depending on whatever the current value happens to be.
        $row->images   = '{}';
        $row->urls     = '{}';
        $row->metadata = '{}';

        $app->triggerEvent('onContentBeforeSave', ['com_content.article', &$row, $isNew]);

        $exists = $this->findDuplicates('alias', $row->alias);
        $stored = false;

        if (!$exists) {
            $stored = $row->store();
        } elseif ($fgParams->getValue('force_new') && $row->load(['alias' => $content['alias'], 'catid' => $content['catid']]) && ($row->id != $content['id'] || (int) $content['id'] === 0)) {
            // Table::load() just overwrote every property on $row -
            // including introtext/fulltext and everything else set
            // above (featured/language/access/attribs/images/urls/
            // metadata), all of which were correctly set from the fresh
            // $content moments ago - with whatever the OLD, already-
            // existing row (the one that triggered this alias-collision
            // branch in the first place) had instead. Only alias/id/
            // state were being explicitly re-set afterward, meaning
            // every other field silently kept the stale row's data:
            // redo all of it here to restore the fresh content this
            // method is actually supposed to save, while still using
            // the load() call for its real purpose (confirming a
            // colliding row exists to react to at all).
            $row->bind($content);
            $row->featured = (int) $fgParams->getValue('front_page');
            $row->language = '*';
            $row->access   = (int) $fgParams->getValue('access') ?: 1;
            $row->attribs  = $fgParams->getValue('onlyintro') ? '' : '{"show_intro":"0"}';
            $row->images   = '{}';
            $row->urls     = '{}';
            $row->metadata = '{}';

            $now          = Factory::getDate();
            $row->alias   .= '_' . $now->format('Y-m-d-H-i-s');
            $row->id       = $content['id'];
            $row->state    = (int) $fgParams->getValue('auto_publish');
            $stored        = $row->store();
        }

        if (!$stored) {
            $content['mosMsg'] = $exists
                ? $this->title . ' error saving ' . $row->title . ' - article may already exist. ' . $row->getError()
                : $this->title . ' ***ERROR: ' . $row->getError();

            return false;
        }

        $content['id'] = $row->id;

        $this->ensureWorkflowAssociation($row->id, $row->state);

        $app->triggerEvent('onContentAfterSave', ['com_content.article', &$row, $isNew]);

        /** @var Cache $cache */
        $cache = Factory::getCache('com_content');
        $cache->clean();

        FeedgatorHelper::saveImport($fgParams->getValue('hash'), $fgParams->getValue('id'), $content['id'], $this->extension, $fgParams);

        return true;
    }

    /**
     * Joomla 4+'s Workflow system requires every #__content row to have
     * a matching #__workflow_associations row (item_id + extension ->
     * stage_id) - without one, the article is silently excluded from
     * the Article Manager's list entirely, with no filter able to
     * surface it, regardless of how correct every other field is. This
     * is normally created automatically as part of Joomla's own
     * ArticleModel::save() flow; this component's direct Table::store()
     * call bypasses that entirely, which is exactly what was causing
     * every FeedGator-imported article to be invisible despite saving
     * successfully in every other respect.
     *
     * Maps the article's state (published/unpublished/etc.) to the
     * matching stage's `condition` column in the site's default
     * com_content workflow, rather than hardcoding a stage ID - stage
     * IDs aren't guaranteed stable across installs, but the condition
     * values (1=published, 0=unpublished, 2=archived, -2=trashed) are
     * how Joomla's own workflow system defines the mapping.
     */
    private function ensureWorkflowAssociation(int $itemId, int $state): void
    {
        $db = $this->db();

        try {
            $query = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__workflow_associations'))
                ->where($db->quoteName('item_id') . ' = :itemId')
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'))
                ->bind(':itemId', $itemId, \Joomla\Database\ParameterType::INTEGER);
            $db->setQuery($query);

            if ($db->loadResult()) {
                return; // already has an association (e.g. updating an existing article)
            }
        } catch (\Throwable $e) {
            // #__workflow_associations itself doesn't exist on this
            // install - nothing to associate with, so there's nothing
            // further this method can usefully do.
            return;
        }

        // The whole query-build-and-run is wrapped in try/catch, not
        // just loadResult() - Joomla's DB layer can throw at setQuery()
        // itself for some query shapes/drivers, not only at execution
        // time, and an earlier version of this code only wrapped
        // loadResult(), letting a "table doesn't exist" exception
        // escape uncaught on this install (which has #__workflow_
        // associations but not the #__workflow/#__workflow_stages
        // tables themselves - evidently this site's schema doesn't
        // include the full multi-stage workflow feature).
        $stageId = null;

        try {
            $stageQuery = $db->getQuery(true)
                ->select($db->quoteName('ws.id'))
                ->from($db->quoteName('#__workflow_stages', 'ws'))
                ->join('INNER', $db->quoteName('#__workflow', 'w') . ' ON ' . $db->quoteName('w.id') . ' = ' . $db->quoteName('ws.workflow_id'))
                ->where($db->quoteName('w.extension') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('w.published') . ' = 1')
                ->where($db->quoteName('ws.condition') . ' = :state')
                ->order($db->quoteName('w.default') . ' DESC, ' . $db->quoteName('ws.default') . ' DESC')
                ->bind(':state', $state, \Joomla\Database\ParameterType::INTEGER);
            $db->setQuery($stageQuery);
            $stageId = $db->loadResult();
        } catch (\Throwable $e) {
            $stageId = null; // schema assumption above didn't hold on this install - fall through to the safe default below
        }

        if (!$stageId) {
            // Best-effort fallback: stage id 1 is what a stock Joomla
            // install's default "Basic Workflow" uses for its initial/
            // published stage - not guaranteed correct on every install,
            // but better than leaving the association missing entirely
            // (which is the one outcome guaranteed to hide the article).
            $stageId = 1;
        }

        // insertObject()'s second parameter is by-reference (Joomla
        // writes any auto-increment id back onto it), so it needs an
        // actual variable here - passing the (object) literal directly
        // is a PHP fatal error ("cannot be passed by reference"), the
        // same class of issue fixed earlier for extractHTTP().
        $associationRow = (object) [
            'item_id'   => $itemId,
            'stage_id'  => $stageId,
            'extension' => 'com_content.article',
        ];

        try {
            $db->insertObject('#__workflow_associations', $associationRow);
        } catch (\Throwable $e) {
            // Don't fail the whole import over this - the article itself
            // is already saved successfully at this point.
        }
    }

    public function reorder($catid, &$fgParams)
    {
        $row = $this->row ?? Factory::getApplication()->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator');
        $this->row = $row;

        return (bool) $row->reorder('catid = ' . (int) $catid . ' AND state >= 0');
    }

    protected function _buildWhere(&$where, $w = true)
    {
        $db = $this->db();

        if ($this->state !== 'state' && strpos($where, 'state') !== false) {
            $where = str_replace('state', $this->state, $where);
        }

        $prefix = $where ? ' AND ' : ($w ? 'WHERE ' : '');
        $where .= $prefix . 'fg.content_type = ' . $db->quote($this->extension);
    }
}
