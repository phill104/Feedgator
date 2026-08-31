<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/tools.php (JModelLegacy -> BaseDatabaseModel).
 *
 * NOTE: checkLatestVersion()/loadJoomlaCode() scrape joomlacode.org for
 * update notifications. joomlacode.org has been offline since ~2014, so
 * this feature has been non-functional for a decade regardless of the
 * Joomla/PHP version. It's converted here for structural completeness but
 * you should either remove it or point it at wherever FeedGator is
 * actually hosted now (GitHub releases API, etc.).
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorUtility;

\defined('_JEXEC') or die;

class ToolsModel extends BaseDatabaseModel
{
    protected $_id = null;
    protected $_data = null;
    protected $_imports = null;
    protected $_plugin = null;

    private $pluginModel;
    private $feedModel;

    public function __construct($config = [], ?\Joomla\CMS\MVC\Factory\MVCFactoryInterface $factory = null)
    {
        parent::__construct($config, $factory);

        PluginHelper::importPlugin('feedgator');

        $this->pluginModel = FeedgatorFactory::getPluginModel();
        $this->feedModel   = FeedgatorFactory::getFeedModel();
    }

    public function findDuplicates()
    {
        $db           = $this->getDatabase();
        $plugins_data = $this->pluginModel->loadInstalledPlugins();

        foreach ($plugins_data as $plugin_data) {
            if ($plugin_data->published) {
                $this->pluginModel->setExt($plugin_data->extension);
                $plugin = $this->pluginModel->getPlugin();
                $query  = $plugin->findDuplicates('internal', null);

                if (!$query) {
                    continue;
                }

                $db->setQuery($query, 0, 1);

                if ($db->loadResult()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getDuplicates()
    {
        $db           = $this->getDatabase();
        $plugins_data = $this->pluginModel->loadInstalledPlugins();
        $queries      = [];

        foreach ($plugins_data as $plugin_data) {
            if ($plugin_data->published) {
                $this->pluginModel->setExt($plugin_data->extension);
                $plugin    = $this->pluginModel->getPlugin();
                $queries[] = $plugin->findDuplicates('internal', null);
            }
        }

        $query = implode(' UNION ', array_filter($queries));

        if (!$query) {
            return false;
        }

        $query .= ' ORDER BY content_type';
        $db->setQuery($query);

        if ($dups = $db->loadObjectList()) {
            return $dups;
        }

        return false;
    }

    public function ignoreDuplicate()
    {
        $app          = Factory::getApplication();
        $rel          = $app->getInput()->get->getCmd('rel', '');
        $id           = substr($rel, strpos($rel, '_') + 1);
        $content_type = $app->getInput()->get->getCmd('type', '');

        if ($rel && $content_type) {
            $this->pluginModel->setExt($content_type);
            $this->pluginModel->getParams(-1);
            $params = $this->pluginModel->getPluginData()->paramsArray[-1] ?? [];

            $params['ignore'] = isset($params['ignore']) ? $params['ignore'] . ',' . $id : $id;
            unset($params['--TXT--']);

            if ($this->pluginModel->store(-1, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated  joomlacode.org has been offline since ~2014 - see class docblock.
     */
    public function checkLatestVersion(&$fgParams, $version = null, $stable = '3190', $dev = '5405')
    {
        $version   = $version ?: FeedgatorHelper::getFGVersion();
        $short_v   = substr($version, 0, 5);
        $dev_level = substr($version, 5);

        $frs = 'http://joomlacode.org/gf/project/feedgator/frs/?action=FrsReleaseBrowse&frs_package_id=';
        $url = $frs . $stable;
        $url2 = $dev ? $frs . $dev : null;

        $stableResult = [];
        $devResult    = [];

        if ($page = FeedgatorUtility::getUrl($url, $fgParams->getValue('scrape_type'))) {
            $stableResult          = $this->loadJoomlaCode($page);
            $stableResult['upgrade'] = 0;
        }

        if ((!$page || $fgParams->getValue('notify_dev')) && $url2) {
            if ($page = FeedgatorUtility::getUrl($url2, $fgParams->getValue('scrape_type'))) {
                $devResult          = $this->loadJoomlaCode($page);
                $devResult['upgrade'] = 0;
            }
        }

        if (empty($stableResult) && empty($devResult)) {
            $stableResult['v']       = 'unknown';
            $stableResult['upgrade'] = 0;
        }

        if (isset($stableResult['v']) && ($short_v < $stableResult['v'] || ($short_v == $stableResult['v'] && $dev_level))) {
            $stableResult['upgrade'] = 1;
        }

        if (isset($devResult['upgrade'], $devResult['v']) && $version < $devResult['v']) {
            $devResult['upgrade'] = 1;
        }

        return ['stable' => $stableResult, 'dev' => $devResult];
    }

    /**
     * Checks whether the Scheduled Task plugin (plg_task_feedgator) is
     * installed and enabled - this is what actually drives automated
     * imports now. This replaces an earlier version of this method that
     * checked for a "feedgator_system" system plugin instead - that was
     * the pre-conversion component's automation mechanism (a system
     * plugin hooked into every page load), fully replaced by Joomla's
     * native Scheduled Tasks feature. That old check would always fail
     * since nothing installs a "feedgator_system" plugin any more,
     * permanently showing a stale, no-longer-relevant warning
     * regardless of whether the real task plugin was set up correctly.
     */
    public function checkJPlugins()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('feedgator'))
            ->where($db->quoteName('enabled') . ' = 1');
        $db->setQuery($query);

        return $db->loadResult();
    }

    public function checkPlugins()
    {
        $db    = $this->getDatabase();
        $query = 'SELECT COUNT(fg.id) AS fgids, COUNT(fgg.id) AS fggids'
            . ' FROM #__feedgator AS fg'
            . ' INNER JOIN #__feedgator_plugins AS fp ON fg.content_type = fp.extension'
            . ' RIGHT JOIN #__feedgator AS fgg ON fg.id = fgg.id';
        $db->setQuery($query);
        $counts = $db->loadRow();

        if ($counts[0] == $counts[1]) {
            $rows = $this->pluginModel->loadInstalledPlugins();

            foreach ($rows as $row) {
                if ($row->pub_count) {
                    return true;
                }
            }
        }

        return false;
    }

    public function checkImports()
    {
        $db    = $this->getDatabase();
        $count = 0;
        $ids   = [];

        $query = 'SELECT * FROM #__feedgator_imports WHERE (content_id != -1 AND content_id != -2) AND plugin != ' . $db->quote('enclosure');
        $db->setQuery($query);
        $rows = $db->loadAssocList();

        if (!empty($rows)) {
            foreach ($rows as $row) {
                if ($plugin = $this->pluginModel->getPlugin($row['plugin'])) {
                    if (!isset($ids[$row['plugin']]['query'])) {
                        $ids[$row['plugin']]['query'] = $plugin->countContentQuery();
                    }

                    $ids[$row['plugin']]['ids'][] = $row['content_id'];
                }
            }

            foreach ($ids as $data) {
                $query = sprintf($data['query'], implode(',', $data['ids']));
                $db->setQuery($query);
                $count += (int) $db->loadResult();
            }

            $total = \count($rows);
        } else {
            $total = 0;
        }

        return $total == $count;
    }

    public function syncImports()
    {
        $db  = $this->getDatabase();
        $app = Factory::getApplication();
        $msg = '<h4>' . Text::_('FG_IMPORT_SYNCHRONISATION') . '</h4>';
        $ids = [];

        $query = 'SELECT * FROM #__feedgator_imports WHERE (content_id != -1 AND content_id != -2) AND plugin != ' . $db->quote('enclosure');
        $db->setQuery($query);
        $rows = $db->loadAssocList();

        foreach ($rows as $row) {
            if ($plugin = $this->pluginModel->getPlugin($row['plugin'])) {
                if (!$plugin->getContentItem($row['content_id'])) {
                    $ids[] = $row['id'];
                }
            } else {
                $msg .= 'Unable to sync imports using ' . $row['plugin'];
            }
        }

        $total = 0;

        if ($ids) {
            $total  = \count($ids);
            $idList = implode(',', $ids);
            $query  = 'DELETE FROM #__feedgator_imports WHERE id ' . (($total > 1) ? 'IN (' . $idList . ')' : '= ' . $idList);
            $db->setQuery($query);
            $db->execute();
        }

        $msg .= $total ? $total . Text::_('FG_LOG_ENTRIES_DELETED') : Text::_('FG_NO_LOG_ENTRIES_DELETED');
        $app->enqueueMessage($msg, 'message');
        $app->redirect('index.php?option=com_feedgator&task=tools');
    }

    /**
     * @deprecated  Only ever useful against the (long dead) joomlacode.org FRS pages.
     */
    public function loadJoomlaCode($page)
    {
        $data = [];

        // Same PHP 8 empty-string issue as FeedgatorHelper::processImages()
        // - joomlacode.org has been offline since ~2014 (see this
        // method's docblock), so $page is essentially guaranteed to be
        // empty/false in practice, which used to just produce an empty
        // DOMDocument silently and now throws instead.
        if (!$page) {
            return $data;
        }

        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML($page);
        libxml_clear_errors();

        $table = null;

        foreach ($dom->getElementsByTagName('table') as $candidate) {
            if (trim($candidate->getAttribute('class')) === 'tabular') {
                $table = $candidate;
                break;
            }
        }

        if (!$table) {
            return $data;
        }

        $tds = null;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (trim($tr->getAttribute('class')) === 'l') {
                $tds = $tr->getElementsByTagName('td');
                break;
            }
        }

        if (!$tds) {
            return $data;
        }

        $a           = $tds->item(0)->getElementsByTagName('a')->item(0);
        $data['v']   = str_replace('FeedGator', '', $a->nodeValue);
        $data['date'] = $tds->item(1)->nodeValue;
        $data['name'] = $tds->item(2)->nodeValue;
        $data['link'] = 'http://www.joomlacode.org' . $tds->item(2)->getElementsByTagName('a')->item(0)->getAttribute('href');
        $data['size'] = $tds->item(3)->nodeValue;

        return $data;
    }
}
