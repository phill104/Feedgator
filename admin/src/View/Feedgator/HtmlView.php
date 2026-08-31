<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from views/feedgator/view.html.php. Design note: kept as one
 * view class with a switch on $tpl (matching the original), since
 * Joomla's HtmlView::display($tpl) still supports that pattern natively
 * in J4/5/6 - splitting into a view class per layout would be a bigger,
 * riskier rewrite for no functional benefit here.
 *
 * The admin-side JavaScript (MooTools-based inline import/preview UI) is
 * carried over unmodified in behaviour. MooTools itself was removed from
 * Joomla core in Joomla 4, so this JS will NOT run on Joomla 6 unless you
 * either (a) load MooTools yourself as a third-party asset, or (b) port
 * this UI to vanilla JS. That port is flagged but not done here - it's a
 * separate, self-contained piece of work from the PHP/API migration and
 * doesn't block the rest of the component functioning.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\View\Feedgator;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public $fgParams;
    public $config;
    public $dups;
    public $rows = [];
    public $page;
    public $search;
    public $lists;
    public $plugins;
    public $version_data;
    public $contentsections;
    public $sectioncategories;
    public $jplugin;
    public $fgplugins;
    public $import_sync;
    public $duplicates;
    public $latest_imports;
    public $globals;
    public $defaults;

    public function display($tpl = null)
    {
        $app   = Factory::getApplication();
        $doc   = $app->getDocument();
        $user  = $app->getIdentity();
        $model = FeedgatorFactory::getFeedModel();
        $toolsModel = FeedgatorFactory::getToolsModel();

        if (!class_exists('DOMDocument')) {
            $app->enqueueMessage(Text::_('FG_DOMDOCUMENT'), 'error');
        }

        $edit = !\in_array($app->getInput()->get('task', null, 'cmd'), ['new', 'add'], true);

        \Joomla\CMS\HTML\HTMLHelper::_('bootstrap.tooltip');
        $doc->getWebAssetManager()->registerAndUseStyle('com_feedgator.admin', 'components/com_feedgator/css/styles.css');

        if ($tpl) {
            $id = 0;

            if ($edit) {
                $cid = (array) $app->getInput()->get('cid', [0], 'array');
                $id  = $app->getInput()->getInt('id', (int) ($cid[0] ?? 0));
            }

            $model->setId($id);

            if ($tpl === 'settings') {
                $this->config = $model->getConfig();
            } elseif ($tpl === 'imports') {
                $this->fgParams = $model->getParams();
                $this->buildImportLists();
            } elseif ($tpl === 'tools') {
                if ($dups = $toolsModel->getDuplicates()) {
                    foreach ($dups as $dup) {
                        $plugin = $model->getPlugin($dup->content_type);
                        $data   = explode('||', $dup->results);

                        foreach ($data as &$datum) {
                            $datum = explode('|', $datum);
                        }

                        $dup->dups = [];

                        for ($i = 0; $i < $dup->num; $i++) {
                            $d               = new \stdClass();
                            $d->id           = $data[$i][0] ?? null;
                            $d->content_link = $plugin->getContentLink($d->id);
                            $d->catid        = $data[$i][2] ?? null;
                            $d->title        = $data[$i][3] ?? null;
                            $dup->dups[$i]   = $d;
                        }
                    }

                    $this->dups = $dups;
                }
            } elseif ($tpl === 'feed' || $tpl === 'feed_default') {
                $this->fgParams = ($tpl === 'feed') ? $model->getParams() : $model->getDefaultParams(true);
                $this->buildEditLists();

                if ($edit && $app->getInput()->get('task', null, 'cmd') !== 'editdefault') {
                    if ($model->isCheckedOut($user->id)) {
                        $msg = Text::sprintf('DESCBEINGEDITTED', Text::_('The feed'), $model->getData()->title ?? '');
                        $app->enqueueMessage($msg, 'warning');
                        $app->redirect('index.php?option=com_feedgator');
                    }
                }
            } elseif ($tpl === 'feeds') {
                $this->buildFeedLists($this->fgParams);
                $pluginModel   = FeedgatorFactory::getPluginModel();
                $this->plugins = $pluginModel->loadInstalledPlugins();
            } elseif ($tpl === 'about') {
                $this->fgParams     = $model->getParams();
                $this->version_data = $toolsModel->checkLatestVersion($this->fgParams);
            }
        } else { // control panel
            $fgParams           = $model->getParams();
            $this->fgParams     = $fgParams;
            $this->version_data = $toolsModel->checkLatestVersion($fgParams);
            // NOTE: this used to check $fgParams->getValue('base') -
            // $fgParams here is $model->getParams(), the "default feed"
            // (-2 sentinel row)'s OWN settings, which is a completely
            // different storage location from actual global settings
            // (saved to #__extensions.params via the component's
            // Options screen - see DisplayController::saveSettings()).
            // It was checking the wrong data entirely, and 'base' is a
            // poor signal even on the right data source anyway, since
            // that field always auto-populates a display value (see
            // JFormFieldFgbase) regardless of whether the form has ever
            // actually been saved. Check the real global-settings
            // storage directly instead.
            $globalParamsRaw    = (string) \Joomla\CMS\Component\ComponentHelper::getComponent('com_feedgator')->params;
            $this->globals      = $globalParamsRaw !== '' && $globalParamsRaw !== '{}' && $globalParamsRaw !== '[]';
            $this->defaults     = (bool) $model->getData(-2);
            $this->jplugin      = $toolsModel->checkJPlugins();
            $this->fgplugins    = $toolsModel->checkPlugins();
            $this->import_sync  = $toolsModel->checkImports();
            $this->duplicates   = $toolsModel->findDuplicates();
            $this->latest_imports = $model->getLatestImports();
        }

        $this->addToolbar($tpl);

        parent::display($tpl);
    }

    /**
     * Sets the page title and toolbar buttons per screen. Converted from
     * the original toolbar/toolbar.feedgator.html.php (TOOLBAR_feedgator
     * class) into Joomla's modern fluent Toolbar API. The FGPreview/
     * FGImport/FGImportAll custom toolbar buttons from the original are
     * not reproduced here - see MIGRATION_REPORT.md re: the dropped
     * import-progress JS those buttons drove.
     */
    protected function addToolbar($tpl)
    {
        $toolbar = \Joomla\CMS\Toolbar\Toolbar::getInstance();
        $canDo   = \Joomla\CMS\Helper\ContentHelper::getActions('com_feedgator');

        if ($tpl === 'feed' || $tpl === 'feed_default') {
            $isNew = !$this->fgParams->getValue('id');
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_FEED_GATOR') . ': ' . Text::_($tpl === 'feed_default' ? 'FG_TAB_FEED_DETAILS' : ($isNew ? 'JTOOLBAR_NEW' : 'JTOOLBAR_EDIT')), 'feedgator');
            $toolbar->apply($tpl === 'feed_default' ? 'applydefault' : 'apply');
            $toolbar->save($tpl === 'feed_default' ? 'savedefault' : 'save');
            $toolbar->cancel('cancel');
        } elseif ($tpl === 'settings') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_SETTINGS'), 'cog');
            $toolbar->apply('applysettings');
            $toolbar->save('savesettings');
            $toolbar->cancel('cancel', 'JTOOLBAR_CLOSE');
        } elseif ($tpl === 'feeds') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_MAN_FEEDS'), 'rss');

            if ($canDo->get('core.create')) {
                $toolbar->addNew('add');
            }

            if ($canDo->get('core.edit.state')) {
                $toolbar->publish('publish');
                $toolbar->unpublish('unpublish');
            }

            if ($canDo->get('core.delete')) {
                $toolbar->delete('remove')->message('JGLOBAL_CONFIRM_DELETE');
            }

            $toolbar->linkButton('editdefault', Text::_('FG_EDIT_DEFAULTS'))->icon('icon-cog')->url('index.php?option=com_feedgator&task=editdefault');
            // Preview/Import/Import All buttons are rendered directly in
            // default_feeds.php instead of via the Toolbar API here -
            // see that template's docblock for why.
        } elseif ($tpl === 'tools') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_TOOLS'), 'wrench');
        } elseif ($tpl === 'imports') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_IMPORTS'), 'archive');
        } elseif ($tpl === 'about') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_ABOUT'), 'info-circle');
        } elseif ($tpl === 'support') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_SUPPORT'), 'question-circle');
        } else {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_CPANEL'), 'home');
        }

        if ($tpl) {
            $toolbar->linkButton('cpanel', Text::_('FG_CPANEL'))->icon('icon-home')->url('index.php?option=com_feedgator&task=cpanel');
        }
    }

    protected function buildEditLists()
    {
        $app   = Factory::getApplication();
        $user  = $app->getIdentity();
        $model = FeedgatorFactory::getFeedModel();

        if ($model->getData() && !\in_array($app->getInput()->get('task', null, 'cmd'), ['new', 'add'], true)) {
            $model->checkout($user->id);
            $createdate = Factory::getDate($this->fgParams->getValue('created'));
            $this->fgParams->setValue('created', null, $createdate->toUnix());
        }

        $dynaLists = FeedgatorHelper::getDynaLists($this->fgParams, false);

        $this->contentsections   = $dynaLists['contentsections'];
        $this->sectioncategories = $dynaLists['sectioncategories'];
    }

    protected function buildFeedLists(&$fgParams)
    {
        $app         = Factory::getApplication();
        $db          = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $model       = FeedgatorFactory::getFeedModel();
        $pluginModel = FeedgatorFactory::getPluginModel();
        $context     = 'com_feedgator.feeds';

        $limit            = $app->getUserStateFromRequest($context . 'viewlistlimit', 'limit', 10, 'int');
        $limitstart       = $app->getUserStateFromRequest($context . 'viewlimitstart', 'limitstart', 0, 'int');
        $search           = strtolower((string) $app->getUserStateFromRequest('searchcom_feedgator', 'search', '', 'word'));
        $filter_order     = $app->getUserStateFromRequest($context . 'filter_order', 'filter_order', 'fg.id', 'cmd');
        $filter_order_Dir = $app->getUserStateFromRequest($context . 'filter_order_Dir', 'filter_order_Dir', 'asc', 'word');

        $plugins_data = $pluginModel->loadInstalledPlugins();

        $where   = [];
        $where[] = 'fg.id > 0'; // ensures default feed not shown in feed list

        if ($search) {
            $where[] = '(LOWER(fg.title) LIKE ' . $db->quote('%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%') . ' OR fg.id = ' . (int) $search . ')';
        }

        $where = count($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        $db->setQuery('SELECT * FROM #__feedgator WHERE id > 0');
        $rows  = [];
        $found = [];

        if ($feeds = $db->loadObjectList()) {
            foreach ($feeds as $feed) {
                if (!\in_array($feed->content_type, $found, true)) {
                    foreach ($plugins_data as $plugin_data) {
                        if ($plugin_data->extension === $feed->content_type) {
                            $plugin = $model->getPlugin($plugin_data->extension);

                            if (!isset($plugin->errorMsg)) {
                                array_splice($rows, \count($rows), 0, $plugin->getFeedItems($where));
                                $found[] = $feed->content_type;
                            }
                        }
                    }

                    if (!\in_array($feed->content_type, $found, true)) {
                        $feed->title        .= ' - ' . Text::_('FG_PLUGIN_MISSING');
                        $feed->cat_name      = '<strong><i>' . Text::_('FG_PLUGIN_MISSING') . '</i></strong>';
                        $feed->section_name  = '<strong><i>' . Text::_('FG_PLUGIN_MISSING') . '</i></strong>';
                        $feed->editor        = '<strong><i>' . Text::_('FG_PLUGIN_MISSING') . '</i></strong>';
                        $rows[]              = $feed;
                    }
                }
            }
        }

        $total      = \count($rows);
        $pagination = new Pagination($total, $limitstart, $limit);

        if (!empty($rows)) {
            $alias = $this->getFilterAlias($filter_order);
            usort($rows, static function ($a, $b) use ($alias, $filter_order_Dir) {
                $dir = $filter_order_Dir === 'asc' ? 1 : -1;

                return $dir * ($a->$alias <=> $b->$alias);
            });
            $rows = \array_slice($rows, $pagination->limitstart, $pagination->limit ?: null);
        }

        $lists              = [];
        $lists['order_Dir'] = $filter_order_Dir;
        $lists['order']     = $filter_order;
        $lists['search']    = $search;

        $this->rows   = $rows;
        $this->page   = $pagination;
        $this->search = $search;
        $this->lists  = $lists;
    }

    protected function buildImportLists()
    {
        $app         = Factory::getApplication();
        $db          = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $model       = FeedgatorFactory::getFeedModel();
        $pluginModel = FeedgatorFactory::getPluginModel();
        $filter      = null;
        $pluginid    = null;

        $context          = 'com_feedgator.imports';
        $filter_order     = $app->getUserStateFromRequest($context . 'filter_order', 'filter_order', '', 'cmd');
        $filter_order_Dir = $app->getUserStateFromRequest($context . 'filter_order_Dir', 'filter_order_Dir', '', 'word');
        $filter_state     = $app->getUserStateFromRequest($context . 'filter_state', 'filter_state', '', 'word');
        $filter_authorid  = $app->getUserStateFromRequest($context . 'filter_authorid', 'filter_authorid', 0, 'int');
        $filter_feedid    = $app->getUserStateFromRequest($context . 'filter_feedid', 'filter_feedid', -1, 'int');
        $search           = (string) $app->getUserStateFromRequest($context . 'search', 'search', '', 'string');

        if (strpos($search, '"') !== false) {
            $search = str_replace(['=', '<'], '', $search);
        }

        $search = strtolower($search);

        $filter_sectionid = $app->getUserStateFromRequest($context . 'filter_sectionid', 'filter_sectionid', -1, 'cmd');
        $s_pluginid       = null;

        if ($filter_sectionid && $filter_sectionid < -1) {
            $s_pluginid = -1 * $filter_sectionid;
        }

        $filter_catid = $app->getUserStateFromRequest($context . 'filter_catid', 'filter_catid', '', 'cmd');
        $c_pluginid   = null;

        if ($filter_catid) {
            $c_pluginid   = substr($filter_catid, 0, strpos($filter_catid, '_'));
            $filter_catid = substr($filter_catid, strpos($filter_catid, '_') + 1);

            if ((int) $filter_catid === 0) {
                $c_pluginid = null;
            }
        }

        if ($s_pluginid !== null && $s_pluginid != $c_pluginid) {
            $pluginid = $s_pluginid;
        } elseif ($c_pluginid !== null) {
            $pluginid = $c_pluginid;
        }

        $limit      = $app->getUserStateFromRequest('global.list.limit', 'limit', $app->get('list_limit'), 'int');
        $limitstart = $app->getUserStateFromRequest($context . 'limitstart', 'limitstart', 0, 'int');
        $limitstart = $limit != 0 ? (floor($limitstart / $limit) * $limit) : 0;

        if (!$filter_order) {
            $filter_order = 'id';
        }

        if ($filter_order === 'ordering') {
            $order = ' ORDER BY section_name, cat_name, ordering ' . $filter_order_Dir;
        } else {
            $order = ' ORDER BY ' . $this->getFilterAlias($filter_order) . ' ' . $filter_order_Dir . ', section_name, cat_name, ordering';
        }

        $plugins_data = $pluginModel->loadInstalledPlugins();
        $tparts       = [];
        $rparts       = [];
        $categories   = [];
        $sections     = [];

        foreach ($plugins_data as $plugin_data) {
            if ($this->fgParams->getValue('id') || $pluginid !== null) {
                $plugin = $model->getPlugin($plugin_data->extension);

                if ($plugin_data->extension === $this->fgParams->getValue('content_type') || $plugin_data->id == $pluginid) {
                    $where        = $this->buildWhere($plugin_data->extension === 'com_content', $filter_feedid, $filter_sectionid, $filter_catid, $filter_authorid, $filter_state, $search, $db, $this->fgParams);
                    $tparts[]     = $plugin->countContentItems($where);
                    $rparts[]     = $plugin->getContentItemsQuery($where);
                    $categories   = $plugin->getCatSelectLists($filter, $this->fgParams);
                    array_splice($sections, \count($sections), 0, $plugin->getSecSelectLists($this->fgParams));
                } elseif ($pluginid !== null) {
                    array_splice($sections, \count($sections), 0, $plugin->getSecSelectLists($this->fgParams));
                }
            } elseif ($plugin_data->published) {
                $plugin       = $model->getPlugin($plugin_data->extension);
                $where        = $this->buildWhere($plugin_data->extension === 'com_content', $filter_feedid, $filter_sectionid, $filter_catid, $filter_authorid, $filter_state, $search, $db, $this->fgParams);
                $tparts[]     = $plugin->countContentItems($where);
                $rparts[]     = $plugin->getContentItemsQuery($where);
                array_splice($categories, \count($categories), 0, $plugin->getCatSelectLists($filter, $this->fgParams));
                array_splice($sections, \count($sections), 0, $plugin->getSecSelectLists($this->fgParams));
            }
        }

        $tparts = $tparts ? implode(' + ', $tparts) : '0';
        $db->setQuery('SELECT ' . $tparts);
        $total = (int) $db->loadResult();

        $pagination = new Pagination($total, $limitstart, $limit);

        $rparts = $rparts ? implode(' UNION ', $rparts) : '';
        $db->setQuery($rparts . $order, $pagination->limitstart, $pagination->limit);
        $rows = $db->loadObjectList();

        foreach ($rows as $row) {
            $plugin            = $model->getPlugin($row->content_type);
            $row->content_link = $plugin->getContentLink($row->id);
        }

        $javascript = 'onchange="document.adminForm.submit();"';
        $lists      = [];
        $lists['catid']     = \Joomla\CMS\HTML\HTMLHelper::_('select.genericlist', $categories, 'filter_catid', 'class="inputbox" size="1" ' . $javascript, 'value', 'text', $pluginid !== null ? $pluginid . '_' . $filter_catid : $filter_catid);
        $lists['sectionid'] = \Joomla\CMS\HTML\HTMLHelper::_('select.genericlist', $sections, 'filter_sectionid', 'class="inputbox" size="1" ' . $javascript, 'value', 'text', $filter_sectionid);

        $db->setQuery('SELECT id AS value, title AS text FROM #__feedgator WHERE published = 1');
        $feeds   = [(object) ['value' => '0', 'text' => '- ' . Text::_('FG_SELECT_FEED') . ' -']];
        $feeds   = array_merge($feeds, $db->loadObjectList());
        $lists['feed'] = \Joomla\CMS\HTML\HTMLHelper::_('select.genericlist', $feeds, 'filter_feedid', 'class="inputbox" size="1" ' . $javascript, 'value', 'text', $filter_feedid);

        $query = 'SELECT c.created_by, u.name'
            . ' FROM #__content AS c'
            . ' LEFT JOIN #__users AS u ON u.id = c.created_by'
            . ' WHERE c.state <> -1 AND c.state <> -2'
            . ' GROUP BY u.name'
            . ' ORDER BY u.name';
        $authors = [(object) ['created_by' => '0', 'name' => '- ' . Text::_('FG_SELECT_AUTHOR') . ' -']];
        $db->setQuery($query);
        $authors = array_merge($authors, $db->loadObjectList());
        $lists['authorid'] = \Joomla\CMS\HTML\HTMLHelper::_('select.genericlist', $authors, 'filter_authorid', 'class="inputbox" size="1" ' . $javascript, 'created_by', 'name', $filter_authorid);

        $lists['state']     = \Joomla\CMS\HTML\HTMLHelper::_('grid.state', $filter_state, 'Published', 'Unpublished', 'Archived', 'Trashed');
        $lists['order_Dir'] = $filter_order_Dir;
        $lists['order']     = $filter_order;
        $lists['search']    = $search;

        $this->model  = $model;
        $this->rows   = $rows;
        $this->page   = $pagination;
        $this->search = $search;
        $this->lists  = $lists;
    }

    protected function getFilterAlias($str)
    {
        if ($pos = strpos($str, '.')) {
            $str = substr($str, $pos + 1);
        }

        return $str;
    }

    protected function buildWhere($com_content, $filter_feedid, $filter_sectionid, $filter_catid, $filter_authorid, $filter_state, $search, &$db, &$fgParams)
    {
        $where   = [];
        $where[] = 'fi.content_id = c.id';

        if ($filter_feedid > 0) {
            $where[] = 'fg.id = ' . (int) $filter_feedid;
        }

        if ($filter_sectionid > 0 && !$fgParams->getValue('id') && $com_content) {
            $where[] = 'c.sectionid = ' . (int) $filter_sectionid;
        }

        if ($filter_catid > 0 && !$fgParams->getValue('id')) {
            $where[] = 'c.catid = ' . (int) $filter_catid;
        }

        if ($filter_authorid > 0) {
            $where[] = 'c.created_by = ' . (int) $filter_authorid;
        }

        if ($filter_state) {
            $where[] = match ($filter_state) {
                'P'       => 'c.state = 1',
                'U'       => 'c.state = 0',
                'A'       => 'c.state = 2',
                'T', 'D'  => 'c.state = -2',
                default   => 'c.state != -2',
            };
        } else {
            $where[] = 'c.state != -2';
        }

        if ($search) {
            $where[] = '(LOWER(c.title) LIKE ' . $db->quote('%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%') . ' OR c.id = ' . (int) $search . ')';
        }

        return count($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    }
}
