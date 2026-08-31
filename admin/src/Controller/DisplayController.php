<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from controller.php (JControllerLegacy -> BaseController).
 *
 * Design note: the original component used ONE controller class with a
 * method per "task" (cpanel(), feeds(), saveFeed(), publishFeeds(), ...) and
 * a front-controller switch($task) to call them. Joomla's BaseController
 * still supports exactly that pattern natively (execute($task) calls
 * $this->{$task}() when such a method exists), so the method-per-task shape
 * below is kept 1:1 with the original rather than being split into several
 * task-specific controller classes - this was the lowest-risk conversion
 * given there is no live Joomla 6 instance available to test against.
 *
 * Named DisplayController (not FeedgatorController) because the admin
 * menu's task strings (task="cpanel", task="feeds", etc.) have no dot,
 * so Joomla's ComponentDispatcher falls back to its default controller
 * name, "display", when resolving which controller class to instantiate.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;

\defined('_JEXEC') or die;

class DisplayController extends BaseController implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Default control panel screen.
     */
    public function cpanel()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display();
    }

    public function feeds()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display('feeds');
    }

    public function settings()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display('settings');
    }

    public function tools()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display('tools');
    }

    public function imports()
    {
        $ajax  = $this->input->get->getInt('ajax', 0);
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);

        if ($ajax) {
            echo $view->display('imports');
            Factory::getApplication()->close();
        }

        $view->display('imports');
    }

    public function about()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display('about');
    }

    public function support()
    {
        $model = FeedgatorFactory::getFeedModel();
        $view  = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display('support');
    }

    public function saveSettings($apply = false)
    {
        if (!Session::checkToken()) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->setRedirect('index.php?option=com_feedgator&task=settings');

            return;
        }

        $input     = $this->input;
        $component = $input->post->getCmd('option', '');

        $table = Table::getInstance('extension');
        $id    = $table->find(['element' => $component]);

        if (!$id || !$table->load($id)) {
            $this->app->enqueueMessage(Text::_('FG_NOT_A_VALID_COMPONENT'), 'error');

            return;
        }

        $post             = [];
        $post['params']   = $input->post->get('params', [], 'array');

        if (!$table->save($post)) {
            $this->app->enqueueMessage($table->getError(), 'error');

            return;
        }

        $link = $apply ? 'index.php?option=com_feedgator&task=settings' : 'index.php?option=com_feedgator&task=feeds';
        $msg  = $apply ? Text::_('FG_CHANGES_APPLIED') : Text::_('FG_SETTINGS_SAVED');
        $this->setRedirect($link, $msg);
    }

    public function upgrade()
    {
        if (!Session::checkToken('request')) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $model = FeedgatorFactory::getFeedModel();
        $model->upgradeComponentParams();

        echo $this->importAll(true);
        Factory::getApplication()->close();
    }

    // feed

    public function copyFeed()
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $cid = (array) $this->input->post->get('cid', [], 'array');
        $cid = array_map('intval', $cid);

        if (count($cid) < 1) {
            $this->setRedirect('index.php?option=com_feedgator&task=feeds', Text::_('FG_SELECT_ITEM_TO_COPY'), 'warning');

            return;
        }

        $model = FeedgatorFactory::getFeedModel();

        if (!$model->copy($cid)) {
            $msg = Text::sprintf('FG_ERROR_COPYING_FEEDS', $model->getError());
        } else {
            $msg = Text::sprintf('FG_FEEDS_COPIED', count($cid));
        }

        $this->setRedirect('index.php?option=com_feedgator&task=feeds', $msg);
    }

    public function editFeed($default = false)
    {
        if ($default) {
            $this->input->set('cid', -2);
        }

        $model = FeedgatorFactory::getFeedModel();

        if (!$default && !$model->getDefaultParams()) {
            $this->setRedirect(
                'index.php?option=com_feedgator&task=editdefault',
                Text::_('FG_MUST_SAVE_DEFAULT_FEED_FIRST'),
                'warning'
            );

            return;
        }

        $view = $this->getView('Feedgator', 'html');
        $view->setModel($model);
        $view->display($default ? 'feed_default' : 'feed');
    }

    public function saveFeed($apply = false, $default = false)
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $cid  = $this->input->post->getInt('cid');
        $post = $this->input->post->get('params', [], 'array');

        $model       = FeedgatorFactory::getFeedModel();
        $deffgParams = $model->getDefaultParams();

        if (empty($post['content_type']) && $deffgParams) {
            $post['content_type'] = $deffgParams->getValue('content_type');
        }

        $msg = $model->store($post) ? Text::_('FG_FEED_SAVED') : Text::_('FG_ERROR_SAVING_FEED');
        $model->checkin();

        $this->savePluginSettings($cid, $post['content_type'] ?? null);

        $edit = $default ? 'editdefault' : 'edit';
        $link = $apply
            ? 'index.php?option=com_feedgator&task=' . $edit . '&cid[]=' . $cid
            : 'index.php?option=com_feedgator&task=feeds';

        $this->setRedirect($link, $msg);
    }

    public function publishFeeds($publish = 1, $action = 'publish')
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $cid = (array) $this->input->post->get('cid', [], 'array');
        $cid = array_map('intval', $cid);

        if (count($cid) < 1) {
            $this->setRedirect('index.php?option=com_feedgator&task=feeds', Text::sprintf('FG_SELECT_ITEM_TO', $action), 'warning');

            return;
        }

        $model = FeedgatorFactory::getFeedModel();

        if (!$model->publish($cid, $publish)) {
            $this->app->enqueueMessage($model->getError(), 'error');
        }

        $this->setRedirect('index.php?option=com_feedgator&task=feeds');
    }

    /**
     * Changes the frontpage state of one or more feeds.
     */
    public function frontpageFeeds($frontpage = 1, $action = 'front_yes')
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $cid = (array) $this->input->post->get('cid', [], 'array');
        $cid = array_map('intval', $cid);

        if (count($cid) < 1) {
            $this->setRedirect('index.php?option=com_feedgator&task=feeds', Text::sprintf('FG_SELECT_ITEM_TO', $action), 'warning');

            return;
        }

        $model = FeedgatorFactory::getFeedModel();

        if (!$model->frontpage($cid, $frontpage)) {
            $this->app->enqueueMessage($model->getError(), 'error');
        }

        $this->setRedirect('index.php?option=com_feedgator&task=feeds');
    }

    public function remove()
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $cid = (array) $this->input->post->get('cid', [], 'array');
        $cid = array_map('intval', $cid);

        if (count($cid) < 1) {
            $this->setRedirect('index.php?option=com_feedgator&task=feeds', Text::_('FG_SELECT_ITEM_TO_DELETE'), 'warning');

            return;
        }

        $model = FeedgatorFactory::getFeedModel();

        if (!$model->delete($cid)) {
            $this->app->enqueueMessage($model->getError(), 'error');
        }

        $msg = Text::sprintf('FG_ITEMS_DELETED', count($cid));
        $this->setRedirect('index.php?option=com_feedgator&task=feeds', $msg);
    }

    /**
     * Cancels an edit operation.
     */
    public function cancel()
    {
        if (!Session::checkToken()) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $model = FeedgatorFactory::getFeedModel();
        $model->checkin();
        $this->setRedirect('index.php?option=com_feedgator&task=feeds');
    }

    public function import($type = null)
    {
        if (!Session::checkToken('request')) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        if (!$type) {
            // Top-level input (merged GET+POST), not $this->input->get
            // (GET-only) - the feeds list's Preview/Import/Import All
            // buttons submit `type` via a POST form, which the GET-only
            // sub-input can't see at all.
            $type = $this->input->getWord('type', '');
        }

        $formData = $this->input->get('cid', [], 'array');

        switch ($type) {
            case 'all':
                if ($this->input->get('task', '', 'cmd') === 'fgautomator') {
                    return $this->importAll();
                }

                $this->importAll();
                break;

            case 'feed':
                $this->importFeed($formData);
                break;

            case 'preview':
                $this->importFeed($formData, true);
                break;
        }
    }

    private function importAll($update = false)
    {
        $db   = $this->getDatabase();
        $ajax = $this->input->get->getInt('ajax', 0);

        if ($ajax) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'title']))
                ->from($db->quoteName('#__feedgator'))
                ->where($db->quoteName('id') . ' > 0')
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('id'));
            $db->setQuery($query);
            $formData = $db->loadAssocList();
            echo json_encode($formData);
            Factory::getApplication()->close();
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__feedgator'))
            ->where($db->quoteName('id') . ' > 0')
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('id'));
        $db->setQuery($query);
        $formData = $db->loadColumn();

        return $this->importFeed($formData, false, $update);
    }

    private function importFeed($formData = '', $preview = false, $update = false)
    {
        $model = FeedgatorFactory::getFeedModel();

        if ($update) {
            echo $model->upgradeFeedParams($formData);
            Factory::getApplication()->close();
        }

        if ($this->input->get('task', '', 'cmd') === 'fgautomator') {
            return $model->import($formData, $preview, $update);
        }

        echo $model->import($formData, $preview, $update);
        Factory::getApplication()->close();
    }

    // plugin

    public function plugins()
    {
        $model = FeedgatorFactory::getPluginModel();
        $view  = $this->getView('Plugin', 'html');
        $view->setModel($model);
        $view->display();
    }

    public function pluginSettings()
    {
        $model = FeedgatorFactory::getPluginModel();
        $view  = $this->getView('Plugin', 'html');
        $view->setModel($model);
        $view->display('settings');
    }

    public function savePluginSettings($feedId = null, $content_type = null)
    {
        if ($feedId === null) {
            $feedId = $this->input->getInt('feedId', -2);
        }

        if ($content_type === null) {
            $content_type = $this->input->get('ext', '', 'cmd');
        }

        $pluginModel = FeedgatorFactory::getPluginModel();
        $pluginModel->setExt($content_type);
        $msg = $pluginModel->store($feedId)
            ? Text::_('FG_DEFAULT_PLUGIN_SETTINGS_SAVED')
            : Text::_('FG_DEFAULT_PLUGIN_SETTINGS_NOT_SAVED');

        $this->setRedirect('index.php?option=com_feedgator&task=plugins', $msg);
    }

    /**
     * Toggles a content-sync driver's published state.
     *
     * NOTE: this used to also read/write Joomla's own #__extensions
     * table (folder='feedgator', matching the original component's
     * design where these drivers were installed as real Joomla plugin
     * extensions). This port ships them as plain files with no
     * #__extensions row (see MIGRATION_REPORT.md), so that table isn't
     * involved any more - the #__feedgator_plugins.published column is
     * the sole source of truth.
     */
    public function changePluginState()
    {
        $id  = $this->input->getInt('id');
        $ext = $this->input->get('ext', '', 'cmd');

        $model  = FeedgatorFactory::getPluginModel();
        $plugin = $model->getPlugin($ext);

        if (!$plugin || !$plugin->componentCheck()) {
            $this->setRedirect(
                'index.php?option=com_feedgator&task=plugins',
                Text::_('FG_UNABLE_TO_PUBLISH_COMPONENT_NOT_INSTALLED'),
                'warning'
            );

            return;
        }

        $row = FeedgatorFactory::getTable('Fgplugin');
        $row->load($id);
        $row->published = $row->published ? 0 : 1;

        if ($row->store()) {
            $msg = $row->published ? Text::_('FG_PLUGIN_PUBLISHED') : Text::_('FG_PLUGIN_UNPUBLISHED');
        } else {
            $msg = $row->getError();
        }

        $this->setRedirect('index.php?option=com_feedgator&task=plugins', $msg);
    }

    public function getPluginParams()
    {
        $cid = $this->input->get->getInt('cid');

        $model = FeedgatorFactory::getPluginModel();
        echo $model->renderPluginParams($cid);
        Factory::getApplication()->close();
    }

    // tools

    public function syncImports()
    {
        $model = FeedgatorFactory::getToolsModel();
        $model->syncImports();
    }

    public function ignoreDuplicate()
    {
        if (!Session::checkToken('request')) {
            $this->app->close(Text::_('JINVALID_TOKEN'));
        }

        $model = FeedgatorFactory::getToolsModel();
        $model->ignoreDuplicate();
    }

    // --- Task aliases ---
    //
    // The original component's front-controller (feedgator.php) had an
    // explicit switch($task) mapping short toolbar-button task strings
    // (apply/save/publish/etc.) onto these longer, argument-taking
    // methods above. Joomla's BaseController::execute($task) has no such
    // switch - it just calls a method with the same name as the task -
    // so these thin wrappers reproduce that original mapping. Toolbar
    // buttons (see the View classes) submit the short task names below.

    public function add()
    {
        $this->editFeed();
    }

    public function edit()
    {
        $this->editFeed();
    }

    public function editdefault()
    {
        $this->editFeed(true);
    }

    public function copy()
    {
        $this->copyFeed();
    }

    public function apply()
    {
        $this->saveFeed(true);
    }

    public function applydefault()
    {
        $this->saveFeed(true, true);
    }

    public function save()
    {
        $this->saveFeed();
    }

    public function savedefault()
    {
        $this->saveFeed(false, true);
    }

    public function applysettings()
    {
        $this->saveSettings(true);
    }

    public function publish()
    {
        $this->publishFeeds(1, 'publish');
    }

    public function unpublish()
    {
        $this->publishFeeds(0, 'unpublish');
    }

    public function front_yes()
    {
        $this->frontpageFeeds(1, 'front_yes');
    }

    public function front_no()
    {
        $this->frontpageFeeds(0, 'front_no');
    }
}
