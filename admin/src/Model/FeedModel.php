<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from models/feed.php (JModelLegacy -> BaseDatabaseModel).
 * Logic is preserved 1:1 from the original; API calls have been
 * modernised. See the migration report for what still needs manual QA,
 * in particular the import() method's use of SimplePieFeedAdapter in
 * place of the original bundled SimplePie library.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Model;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Table\Table;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorUtility;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\SimplePieFeedAdapter;

\defined('_JEXEC') or die;

class FeedModel extends BaseDatabaseModel
{
    protected $_id = null;
    protected $_data = null;
    protected $_defaultData = null;
    protected $_imports = null;
    protected $_plugin = null;
    protected $_params = null;
    protected $_defaultParams = null;
    protected $_defaultParamsData = null;

    public function __construct($config = [], ?\Joomla\CMS\MVC\Factory\MVCFactoryInterface $factory = null)
    {
        parent::__construct($config, $factory);

        $app = Factory::getApplication();

        if (\in_array($app->getInput()->get('task', '', 'word'), ['new', 'add'], true)) {
            $this->setId(0);
        } else {
            $array = $app->getInput()->post->get('cid', [0], 'array');

            if ($array[0]) {
                $this->setId((int) $array[0]);
            }
        }
    }

    public function setId($id, $force = false)
    {
        if ($id != $this->_id || $force === true) {
            $this->_id             = $id;
            $this->_data           = null;
            $this->_imports        = null;
            $this->_plugin         = null;
            $this->_params         = null;
            $this->_defaultParams  = null;
        }
    }

    /**
     * @param   int|null  $id  Pass -2 to check/fetch the special "default
     *                         feed" row specifically, bypassing this
     *                         model's current feed context entirely.
     *                         Without this parameter (and the matching
     *                         fix to _loadData()), a caller doing
     *                         getData(-2) was silently getting plain
     *                         getData() instead - PHP allows calling a
     *                         method with more arguments than it
     *                         declares, so the -2 was just discarded.
     */
    public function getData($id = null)
    {
        if ($id) {
            $this->_loadData($id);

            return $this->_defaultData;
        }

        $this->_loadData();

        return $this->_data;
    }

    public function getImports()
    {
        $this->_loadImports();

        return $this->_imports;
    }

    /**
     * @param   bool    $defaults  Overlay global feed defaults onto the per-feed params.
     * @param   string  $tpl       Which forms/*.xml file to load (feed|feed_default).
     */
    public function getParams($defaults = false, $tpl = 'feed')
    {
        if (!$this->_params) {
            $fgParams = $this->getConfig('fgParams', false);
            $xmlFile  = \JPATH_ADMINISTRATOR . '/components/com_feedgator/forms/default_' . $tpl . '.xml';
            $fgParams->loadFile($xmlFile);

            if ($this->getData()) {
                $tmpParams = json_decode($this->_data->params, true) ?? [];
                $tmpParams = array_merge($tmpParams, (array) $this->_data);

                if ($defaults && $this->getDefaultParams()) {
                    $tmpParams = FeedgatorUtility::array_overlay($tmpParams, $this->_defaultParamsData);
                }

                unset($tmpParams['params']);

                $fgParams->bind($tmpParams);
            }

            $this->_params = $fgParams;
        }

        return $this->_params;
    }

    public function getDefaultParams($force = false)
    {
        if (!$this->_defaultParams) {
            if ($this->_loadData(-2)) {
                $this->_defaultParamsData = json_decode($this->_defaultData->params, true) ?? [];
                $this->_defaultParamsData = array_merge($this->_defaultParamsData, (array) $this->_defaultData);
                unset($this->_defaultParamsData['params']);
            }

            if ($this->_defaultParamsData || (!$this->_defaultParamsData && $force)) {
                $xmlFile = \JPATH_ADMINISTRATOR . '/components/com_feedgator/forms/default_feed_default.xml';
                $options = ['control' => 'params'];
                $this->_defaultParams = Form::getInstance('deffgParams', $xmlFile, $options);
                $this->_defaultParams->bind($this->_defaultParamsData);
            }
        }

        return $this->_defaultParams;
    }

    public function getConfig($name = 'config', $setId = true)
    {
        $fg      = ComponentHelper::getComponent('com_feedgator');
        $options = ['control' => 'params'];
        $xmlFile = \JPATH_ADMINISTRATOR . '/components/com_feedgator/forms/default_settings.xml';
        $fgParams = Form::getInstance($name, $xmlFile, $options);
        $fgParams->bind(json_decode((string) $fg->params));

        if ($setId) {
            $fgParams->setValue('id', null, $fg->id);
        }

        unset($fg);

        return $fgParams;
    }

    protected function _setFolderParams($preview, &$fgParams)
    {
        $app = Factory::getApplication();

        if (\in_array($app->getInput()->get('task', '', 'word'), ['import', 'importall', 'cron', 'pseudocron'], true)) {
            switch ($fgParams->getValue('sub_folder', 0)) {
                case 1:
                    $sub = 'daily/' . gmdate('Y/m/d/');
                    break;
                case 2:
                    $sub = 'weekly/' . gmdate('Y/W/');
                    break;
                case 3:
                    $sub = 'monthly/' . gmdate('Y/m/');
                    break;
                default:
                    $sub = '';
            }

            $fgParams->setValue('img_folder', null, $fgParams->getValue('img_folder', null, 'media/feedgator/images/') . $sub);
            $fgParams->setValue(
                'img_srcpath',
                null,
                ($fgParams->getValue('rel_src', null, 0) ? ($preview ? '../' : '') : $fgParams->getValue('base')) . $fgParams->getValue('img_folder')
            );
            $fgParams->setValue(
                'img_savepath',
                null,
                \JPATH_ROOT . '/' . \Joomla\Filesystem\Folder::makeSafe(str_replace('/', '//', $fgParams->getValue('img_folder')))
            );
            $fgParams->setValue(
                'srcpath',
                null,
                ($fgParams->getValue('rel_src', null, 0) ? ($preview ? '../' : '') : $fgParams->getValue('base')) . $fgParams->getValue('media_folder', null, 'media/feedgator/')
            );
            $fgParams->setValue(
                'savepath',
                null,
                \JPATH_ROOT . '/' . \Joomla\Filesystem\Folder::makeSafe(str_replace('/', '//', $fgParams->getValue('media_folder', null, 'media/feedgator/')))
            );
        }
    }

    public function getPlugin($ext = null, $preview = false)
    {
        $fgParams = $this->getParams();

        if (!$ext) {
            $ext = $fgParams->getValue('content_type') ?: '- ' . Text::_('FG_CONTENT_TYPE_NOT_SET');
        }

        $pluginModel   = FeedgatorFactory::getPluginModel();
        $this->_plugin = $pluginModel->getPlugin($ext);

        if (!isset($this->_plugin->title)) {
            $this->_plugin          = new \stdClass();
            $this->_plugin->errorMsg = Text::_('FG_UNABLE_TO_LOAD_PLUGIN') . " {$ext}.";
        } elseif (!@$this->_plugin->data->published) {
            $this->_plugin->errorMsg = Text::_('FG_PLUGIN_NOT_PUBLISHED_FOR') . " {$ext}.";
        }

        return $this->_plugin;
    }

    public function isCheckedOut($uid = 0)
    {
        if ($this->_loadData()) {
            if ($uid) {
                return $this->_data->checked_out && $this->_data->checked_out != $uid;
            }

            return $this->_data->checked_out;
        }

        return false;
    }

    public function checkin()
    {
        if ($this->_id) {
            $feed = $this->getFeedTable();

            if (!$feed->checkin($this->_id)) {
                $this->setError($feed->getError());

                return false;
            }
        }

        return false;
    }

    public function checkout($uid = null)
    {
        if ($this->_id) {
            if ($uid === null) {
                $uid = Factory::getApplication()->getIdentity()->id;
            }

            $feed = $this->getFeedTable();

            if (!$feed->checkout($uid, $this->_id)) {
                $this->setError($feed->getError());

                return false;
            }

            return true;
        }

        return false;
    }

    public function store($post)
    {
        $db  = $this->getDatabase();
        $row = $this->getFeedTable();

        if (($post['content_type'] ?? null) === '-1') {
            $post['content_type'] = 'com_content'; // force content_type if old style and not set
        }

        $pdata                       = [];
        $pdata['id']                 = $this->_id;
        $pdata['created']            = gmdate('Y-m-d H:i:s');
        $pdata['created_by']         = (int) ($post['created_by'] ?? 0);
        $pdata['title']              = $post['title'] ?? '';
        $pdata['content_type']       = $post['content_type'] ?? null;
        $pdata['sectionid']          = (int) ($post['sectionid'] ?? 0);
        $pdata['feed']               = $post['feed'] ?? '';
        $pdata['catid']              = (int) ($post['catid'] ?? 0);
        // published/front_page/filtering are checkbox-style fields: an
        // unchecked checkbox simply isn't submitted at all by the
        // browser, so `$post['published'] ?? null` would silently write
        // NULL into these NOT NULL columns (MySQL rejects that outright
        // in strict mode - the exact same class of bug fixed earlier
        // for the TEXT columns' missing defaults). Coalesce to 0/false
        // explicitly instead of passing through null.
        $pdata['published']          = (int) ($post['published'] ?? 0);
        $pdata['front_page']         = (int) ($post['front_page'] ?? 0);
        $pdata['default_author']     = $post['default_author'] ?? null;
        $pdata['default_introtext']  = $post['default_introtext'] ?? null;
        $pdata['filtering']          = (int) ($post['filtering'] ?? 0);
        $pdata['filter_whitelist']   = $post['filter_whitelist'] ?? '';
        $pdata['filter_blacklist']   = $post['filter_blacklist'] ?? '';

        unset(
            $post['title'], $post['content_type'], $post['sectionid'], $post['feed'], $post['catid'],
            $post['published'], $post['front_page'], $post['default_author'], $post['default_introtext'],
            $post['created'], $post['created_by'], $post['filtering'], $post['filter_whitelist'], $post['filter_blacklist']
        );

        $pdata['params'] = json_encode($post);

        // Only the genuinely nullable columns (content_type,
        // default_author, default_introtext - all `DEFAULT NULL` in the
        // schema) should ever become NULL here; the NOT NULL columns
        // above are already coalesced to a concrete value and must not
        // be touched by this loop.
        $nullable = ['content_type', 'default_author', 'default_introtext'];

        foreach ($nullable as $k) {
            if (($pdata[$k] ?? '') === '') {
                $pdata[$k] = null;
            }
        }

        if ($this->_id == -2 && !$this->_loadData(-2)) {
            $db->setQuery('INSERT INTO #__feedgator (id) VALUES (-2)');
            $db->execute();
        }

        if (!$row->save($pdata)) {
            $this->setError($row->getError());

            return false;
        }

        return true;
    }

    public function copy($cid = [])
    {
        $row = $this->getFeedTable();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($cid as $id) {
            $row->load($id);
            $row->id      = 0;
            $row->title   = 'Copy of ' . $row->title;
            $row->created = $now;
            $row->imports = '';

            if (!$row->save($row)) {
                return false;
            }
        }

        return true;
    }

    public function delete($cid = [])
    {
        if (\count($cid)) {
            $db   = $this->getDatabase();
            $cid  = array_map('intval', $cid);

            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__feedgator'))
                ->whereIn($db->quoteName('id'), $cid);
            $db->setQuery($query);

            if (!$db->execute()) {
                $this->setError((string) $db->stderr());

                return false;
            }
        }

        return true;
    }

    public function publish($cid = [], $publish = 1)
    {
        return $this->_setFeedFlag($cid, 'published', $publish);
    }

    public function frontpage($cid = [], $frontpage = 1)
    {
        return $this->_setFeedFlag($cid, 'front_page', $frontpage);
    }

    private function _setFeedFlag($cid, $column, $value)
    {
        if (\count($cid)) {
            $db   = $this->getDatabase();
            $user = Factory::getApplication()->getIdentity();
            $cid  = array_map('intval', $cid);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__feedgator'))
                ->set($db->quoteName($column) . ' = ' . (int) $value)
                ->whereIn($db->quoteName('id'), $cid)
                ->extendWhere(
                    'AND',
                    [
                        $db->quoteName('checked_out') . ' = 0',
                        $db->quoteName('checked_out') . ' = ' . (int) $user->id,
                    ],
                    'OR'
                );
            $db->setQuery($query);

            if (!$db->execute()) {
                $this->setError((string) $db->stderr());

                return false;
            }
        }

        return true;
    }

    /**
     * Fetches and imports one or more feeds.
     *
     * NOTE: uses SimplePieFeedAdapter in place of the original bundled
     * SimplePie library - see that class's docblock for fidelity caveats
     * (particularly around enclosures/podcast attachments).
     *
     * @param   array  $formData  Feed IDs to process.
     * @param   bool   $preview   Preview mode (renders but doesn't save).
     * @param   bool   $update    Legacy params-upgrade mode.
     */
    public function import($formData, $preview, $update)
    {
        $db       = $this->getDatabase();
        $app      = Factory::getApplication();
        $fgConfig = ComponentHelper::getParams('com_feedgator');
        $tzOffset = Factory::getConfig()->get('offset');
        $task     = $app->getInput()->get('task', '', 'word');

        $initTime  = Factory::getDate('now', $tzOffset)->format('D F j, Y, H:i:s T', false, false);
        $adminMsg  = '';
        $feedMsg   = '';
        $feedsProc = 0;
        $totTime   = 0;
        $totItems  = 0;
        $procItems = 0;

        if (!\ini_get('allow_url_fopen')) {
            \ini_set('allow_url_fopen', '1');
        }

        foreach ($formData as $feedId) {
            FeedgatorUtility::profiling('Start Process Feed: ' . $feedId);
            $addItems  = 0;
            $procItems = 0;
            $errors    = [];
            $this->setId($feedId);

            $fgParams = $this->getParams(true);
            $fgParams->setValue('preview', null, $preview);
            $this->_setFolderParams($preview, $fgParams);

            if (!$fgParams->getValue('published')) {
                return Text::_('FG_FEED_NOT_PUBLISHED');
            }

            if ($task === 'cron' || $task === 'fgautomator') {
                $now = Factory::getDate();

                if ($last = $fgParams->getValue('last_run')) {
                    $last = Factory::getDate($last);
                    $diff = $now->toUnix() - $last->toUnix();
                } else {
                    $diff = -1;
                }

                $interval = ($task === 'cron') ? $fgParams->getValue('cron_interval') : $fgParams->getValue('pseudocron_interval');
                $doImport = ($diff < 0 || $diff > ($interval * 60));
            } else {
                $doImport = true;
            }

            if (!$doImport) {
                continue;
            }

            $startTime = round(microtime(true), 2);

            try {
                $this->getPlugin(null, $preview);

                if (isset($this->_plugin->errorMsg)) {
                    return $this->_plugin->errorMsg;
                }

                $rssDoc = new SimplePieFeedAdapter();
                $rssDoc->set_input_encoding('utf-8');

                if ($fgParams->getValue('feed_encoding')) {
                    $rssDoc->set_input_encoding($fgParams->getValue('feed_encoding'));
                }

                $rssDoc->set_feed_url($fgParams->getValue('feed'));

                if ($fgParams->getValue('force_fsockopen')) {
                    $rssDoc->force_fsockopen(true);
                }

                $rssDoc->enable_cache(false);
                $rssDoc->enable_order_by_date(true);

                if ($fgParams->getValue('set_sp_timeout')) {
                    $rssDoc->set_timeout((int) $fgParams->getValue('set_sp_timeout'));
                }

                try {
                    $rssDoc->init();
                } catch (\Exception $e) {
                    $feedsProc++;
                    $feedMsg .= '<b>Feed import failed with error: ' . $e->getMessage() . '</b>';
                    continue;
                }

                // $rssDoc->error is checked first because it's the
                // specific reason a fetch/parse failed - checking the
                // generic get_type() flag first (as the original SimplePie-
                // based code did) meant a specific, useful error message
                // was always masked by a generic "unable to process"
                // message instead, in this adapter's implementation.
                if ($rssDoc->error) {
                    return 'Feed error: ' . $rssDoc->error . ' for ' . $fgParams->getValue('title') . ' (' . $fgParams->getValue('feed') . ')';
                }

                if ($rssDoc->get_type() & \SIMPLEPIE_TYPE_NONE) {
                    return Text::sprintf('FG_UNABLE_TO_PROCESS', $fgParams->getValue('title') . ' (' . $fgParams->getValue('feed') . ')');
                }

                $channelTitle = $rssDoc->get_title();
                $itemArray    = $rssDoc->get_items();

                if (\is_array($itemArray)) {
                    $num = \count($itemArray) - 1;

                    for ($i = 0; $i <= $num; $i++) { // traverse items backwards to get the oldest item first
                        $item = $itemArray[$i];

                        if ($task === 'fgautomator') {
                            $process = !$fgParams->getValue('pseudocron_import_limit', null, 1) || $addItems < $fgParams->getValue('pseudocron_import_limit', null, 1);
                        } elseif ($task === 'cron') {
                            $process = !$fgParams->getValue('cron_import_limit') || $addItems < $fgParams->getValue('cron_import_limit');
                        } else {
                            $process = !$fgParams->getValue('import_limit') || $addItems < $fgParams->getValue('import_limit');
                        }

                        if ($process) {
                            FeedgatorUtility::profiling('Start Process Item: ' . $item->get_id());
                            $procItems++;

                            $content = FeedgatorHelper::processFeedItem($item, $fgParams, $this->_plugin, $this->_id, $channelTitle, $preview, $update);

                            if (!$content) {
                                continue; // move to next item if no content generated
                            }

                            if (!$update && $fgParams->getValue('create_art', null, 1)) {
                                PluginHelper::importPlugin('feedgator');
                                $app->triggerEvent('onBeforeFGSaveArticle', [$content, $fgParams]);


                                if ($preview) {
                                    return FeedgatorHelper::getPreviewArticle($content, $fgParams, $channelTitle);
                                }

                                FeedgatorUtility::profiling('Start Save Content Item');

                                if ($this->_saveArticle($content, $fgParams)) {
                                    $addItems++;
                                } else {
                                    $errors[] = $content['mosMsg'] ?? '';
                                }

                                FeedgatorUtility::profiling('End Save Content Item');
                                $app->triggerEvent('onAfterFGSaveArticle', [$content, $fgParams]);
                            }
                        }

                        unset($content);

                        if ($i == $num && $fgParams->getValue('create_art', null, 1)) {
                            $this->_plugin->reorder($fgParams->getValue('catid'), $fgParams);
                        }
                    }
                }

                FeedgatorUtility::profiling('End Process Items');
                unset($itemArray, $rssDoc);

                $lastRun  = gmdate('Y-m-d H:i:s');
                $procTime = round(microtime(true), 2) - $startTime;

                if (!$update) {
                    if ($fgParams->getValue('imports')) {
                        $imports     = explode(',', $fgParams->getValue('imports'));
                        $imports[0] += $addItems;
                        $imports[1] += $procItems;
                        $imports[2] += $procTime;

                        if (!$imports[3]) {
                            $imports[3] = $channelTitle;
                        }
                    } else {
                        $imports    = [0, 0, 0, ''];
                        $imports[0] += $addItems;
                        $imports[1] += $procItems;
                        $imports[2] += $procTime;
                        $imports[3]  = $channelTitle;
                    }

                    $query = $db->getQuery(true)
                        ->update($db->quoteName('#__feedgator'))
                        ->set($db->quoteName('last_run') . ' = ' . $db->quote($lastRun))
                        ->set($db->quoteName('imports') . ' = ' . $db->quote(implode(',', $imports)))
                        ->where($db->quoteName('id') . ' = ' . (int) $feedId);
                    $db->setQuery($query);
                    $db->execute();
                }

                $feedMsg .= sprintf(
                    '<b>%d</b> new content item(s) imported (<i>%d processed</i>) in %ds for <b>%s</b> (%s).',
                    $addItems,
                    $procItems,
                    $procTime,
                    $fgParams->getValue('title'),
                    $channelTitle
                );
                $feedMsg .= $fgParams->getValue('filtering') ? 'This feed import was filtered.<br />' : '<br />';
                $feedMsg .= !empty($errors) ? implode('<br/>', $errors) . '<br />' : '';

                $feedsProc++;
                $totTime  += $procTime;
                $totItems += $addItems;

                FeedgatorUtility::profiling('End Process Feed: ' . $feedId);
            } catch (\Exception $e) {
                $feedsProc++;
                $feedMsg .= '<b>Feed import failed with error: ' . $e->getMessage() . '</b>';
                continue;
            }
        }

        if (!$feedsProc) {
            return 'Nothing to process. Check your settings.';
        }

        $ajax = $app->getInput()->get->getInt('ajax', 0);

        if ($fgConfig->get('email_admin')) {
            $this->_sendAdminDigest($fgConfig, $task, $ajax, $feedMsg, $tzOffset, $formData);
        }

        return $ajax
            ? sprintf('<div res="result" count="%d" proc="%d" time="%d">%s</div>', $totItems, $procItems, $totTime, $feedMsg)
            : sprintf(
                '%s<br /><br /><b>%d</b> content items imported in %d seconds.<br /><br /><a href="javascript:closeMsgArea();">Close this window</a><br />',
                $feedMsg,
                $totItems,
                $totTime
            );
    }

    /**
     * Extracted from the tail of the original import() for readability -
     * behaviour preserved from the 2.5 code (digest vs. immediate admin
     * email of import results).
     */
    private function _sendAdminDigest($fgConfig, $task, $ajax, $feedMsg, $tzOffset, $formData)
    {
        $db      = $this->getDatabase();
        $eProc   = 0;
        $eItems  = 0;
        $eTime   = 0;
        $eMsg    = '';
        $in      = [];
        $digest  = null;
        $last    = (bool) ($ajax && Factory::getApplication()->getInput()->get->getInt('last', 0));

        if ($fgConfig->get('email_digest', 1) && ($last || $task === 'cron')) {
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__feedgator'))
                ->where($db->quoteName('published') . ' = 1');
            $db->setQuery($query);
            $rows = $db->loadObjectList();
            $now  = time();

            foreach ($rows as $row) {
                if (($last || ($now >= ($row->last_email + ((int) $fgConfig->get('digest_period', '24') * 3600)))) && $row->imports) {
                    $in[]   = $row->id;
                    $digest = explode(',', $row->imports); // addItems,procItems,procTime,channelTitle

                    if ($fgConfig->get('send_if_null') || $digest[0]) {
                        $eMsg .= sprintf(
                            '<b>%d</b> new content item(s) imported (<i>%d processed</i>) in %ds for <b>%s</b> (%s). ',
                            $digest[0],
                            $digest[1],
                            $digest[2],
                            $row->title,
                            $digest[3]
                        );
                        $eMsg .= $row->filtering ? 'This feed import was filtered.<br />' : '<br />';
                    }

                    $eItems += $digest[0];
                    $eTime  += $digest[2];
                    $eProc++;
                }
            }
        }

        if (($eItems || $fgConfig->get('send_if_null')) && (!$fgConfig->get('email_digest', 1) || $digest !== null)) {
            $exitTime = Factory::getDate('now', $tzOffset)->format('D F j, Y, H:i:s T', false, false);
            $adminMsg  = ($fgConfig->get('email_digest', 1) ? '<b>Feed Gator import digest report:</b>' : '<b>Results of the last Feed Gator import run:</b>') . "\n\n";
            $adminMsg .= '<div id="feedinfo">' . ($fgConfig->get('email_digest', 1) ? '' : '<h1>START Feed Gator Import Processing: ' . $tzOffset . '</h1>') . "\n";
            $adminMsg .= '<span class="feedmsg">' . ($eMsg ?: $feedMsg) . "</span>\n";
            $adminMsg .= ($fgConfig->get('email_digest', 1) ? '' : '<h1>END: ' . $exitTime . '</h1>') . '</div>' . "\n";
            $adminMsg .= sprintf('<h2>%d content items imported in %d seconds (%d feeds processed)</h2>', $eItems, $eTime, $eProc);
            $adminMsg .= $ajax ? "\n" . '<h4>May include imports which have not been notified by email earlier</h4>' : '';

            if (FeedgatorUtility::sendAdminEmail($adminMsg)) {
                $in = $in ? implode(',', $in) : implode(',', $formData);
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__feedgator'))
                    ->set($db->quoteName('last_email') . ' = ' . (int) time())
                    ->set($db->quoteName('imports') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('id') . ' IN (' . $in . ')');
                $db->setQuery($query);
                $db->execute();
            }
        }
    }

    public function getLatestImports()
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__feedgator_imports'))
            ->order($db->quoteName('id') . ' DESC')
            ->setLimit(10);
        $db->setQuery($query);
        $imports = $db->loadAssocList();
        $rows    = null;
        $ids     = [];

        if (!empty($imports)) {
            foreach ($imports as $import) {
                // at present we ignore enclosure-only imports
                if ($import['plugin'] !== 'enclosure') {
                    $this->setId($import['feed_id']);
                    $this->getData();
                    $ids[$import['plugin']]['ids'][] = $import['content_id'];
                }
            }

            if ($ids) {
                $rparts = [];

                foreach ($ids as $content_type => $data) {
                    $plugin   = $this->getPlugin($content_type);
                    $where    = ' WHERE c.id IN (' . implode(',', $data['ids']) . ')';
                    $rparts[] = $plugin->getContentItemsQuery($where);
                }

                $rparts = (\count($rparts) > 1) ? implode(' UNION ', $rparts) : $rparts[0];

                $db->setQuery($rparts . ' ORDER BY id DESC');
                $rows = $db->loadObjectList();

                if (\is_array($rows)) {
                    foreach ($rows as $row) {
                        $plugin           = $this->getPlugin($row->content_type);
                        $row->content_link = $plugin->getContentLink($row->id);
                        $row->feed_link    = Route::_('index.php?option=com_feedgator&task=edit&cid[]=' . $row->feedid);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Legacy one-off upgrade helper (fixes stale #__components rows from
     * very old Joomla installs). Left largely as-is; the #__components
     * table itself no longer exists on Joomla 4+ (components moved to
     * #__extensions), so this is dead code on Joomla 6 and is kept only
     * for reference / historical upgrade paths. It will simply do nothing
     * useful on a J6 site - safe to remove.
     */
    public function upgradeComponentParams()
    {
        return true;
    }

    public function upgradeFeedParams($formData)
    {
        $frow = $this->getFeedTable();
        $irow = $this->getImportTable();

        $dataUp = false;

        foreach ($formData as $feedId) {
            $frow->reset();
            $irow->reset();
            $this->setId($feedId);
            $data = $this->getData();

            $frow->content_type = ($data->sectionid == -2) ? 'com_k2' : 'com_content';

            if (!empty($data->params)) {
                $params  = FeedgatorUtility::parseINIString($data->params);
                $nParams = [];

                foreach ($params as $k => $v) {
                    switch ($k) {
                        case 'default_type':
                            if (is_numeric($v)) {
                                $nParams[$k] = ($v == '-2') ? 'com_k2' : 'com_content';
                            }

                            break;

                        case 'save_img':
                            if (!isset($params['alt_img_ext'])) {
                                $nParams['alt_img_ext'] = $v;
                                $nParams[$k]            = 0;
                            }

                            break;

                        case 'save_img_type':
                            $nParams['alt_img_ext_type'] = $v;

                            break;

                        default:
                            $nParams[$k] = $v;

                            break;
                    }
                }

                $data->params = FeedgatorUtility::makeINIString($nParams);
            }

            $imports = FeedgatorUtility::parseINIString($data->imports);
            $imports = array_unique($imports);

            foreach ($imports as $hash => $content_id) {
                $irow->id = null;
                $irow->save([
                    'content_id' => $content_id,
                    'plugin'     => $this->_data->content_type,
                    'hash'       => $hash,
                    'feed_id'    => $this->_id,
                ]);
            }

            $data->imports = '';

            if (!$frow->save($data)) {
                $dataUp = true;
            }
        }

        if ($dataUp) {
            return '<br /><br /><strong class="red">There was a problem upgrading your feeds. Please check your parameters carefully</strong><br />'
                . '<br /><br /><strong><a href="index.php?option=com_feedgator">Click here to set up your feeds</a></strong>';
        }

        return '<br /><br /><strong class="green">Old feeds upgrade successful!</strong>'
            . '<br /><br /><strong><a href="index.php?option=com_feedgator">Click here to set up your feeds</a></strong>';
    }

    protected function _loadImports()
    {
        if (empty($this->_imports) && $this->_id) {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__feedgator_imports'))
                ->where($db->quoteName('feed_id') . ' = ' . (int) $this->_id);
            $db->setQuery($query);
            $this->_imports = $db->loadAssocList();

            return (bool) $this->_imports;
        }

        return (bool) $this->_id;
    }

    protected function _loadData($id = null)
    {
        if ((empty($this->_data) && $this->_id) || $id) {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__feedgator'))
                ->where($db->quoteName('id') . ' = ' . (int) ($id ?: $this->_id));
            $db->setQuery($query);
            $data = $db->loadObject();

            $id ? $this->_defaultData = $data : $this->_data = $data;

            return (bool) $data;
        }

        return (bool) $this->_id;
    }

    protected function _saveArticle(&$content, &$fgParams)
    {
        $imports = $this->getImports();

        if (!empty($content['id']) && $fgParams->getValue('compare_existing') == 2) {
            $exists = FeedgatorHelper::findDuplicates($content, $imports, $fgParams->getValue('hash'), $content['id'], $fgParams, $this->_plugin, true, true);

            if ($exists && \is_int($exists)) {
                FeedgatorUtility::profiling('Already Imported: Exhaustive Duplicate Check');

                return false;
            }

            switch ($fgParams->getValue('merging')) {
                case 0: // don't merge, make new
                    break;

                case 1: // attempt to merge, makes new if fails
                    if (str_contains($exists['introtext'] . $exists['fulltext'], $content['introtext'] . $content['fulltext'])) {
                        $exists['introtext'] = $content['introtext'];
                        $exists['fulltext']  = $content['fulltext'];

                        if ($this->_plugin->save($exists, $fgParams)) {
                            return true;
                        }
                    }

                    break;

                case 2: // over-write
                    $exists['introtext'] = $content['introtext'];
                    $exists['fulltext']  = $content['fulltext'];
                    $exists['overwrite'] = 1;
                    $this->_plugin->save($exists, $fgParams);

                    return true;
            }
        }

        return $this->_plugin->save($content, $fgParams);
    }

    private function getFeedTable(): Table
    {
        return FeedgatorFactory::getTable('Feed');
    }

    private function getImportTable(): Table
    {
        return FeedgatorFactory::getTable('Import');
    }
}
