<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/plugin.php (JModelLegacy -> BaseDatabaseModel).
 *
 * This manages FeedGator's own bespoke "content-sync driver" registry
 * (the #__feedgator_plugins table + files under admin/plugins/<name>) -
 * NOT Joomla's own plugin system, despite the similar naming. See the
 * migration report for why the original install-time mechanism for these
 * (plugin.feedgator.installer.php, a custom <install type="fgplugin">
 * adapter) was dropped rather than ported: it relied on JInstaller
 * adapter hooks (parseFiles/pushStep/copyManifest) that were removed
 * from Joomla's installer in the 3.x rewrite, so it hasn't actually
 * worked in a very long time. Drivers are now just PHP classes shipped
 * inside this component and registered via the install SQL.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Table\Table;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorUtility;

\defined('_JEXEC') or die;

class PluginModel extends BaseDatabaseModel
{
    protected $_ext = null;
    protected $_file = null;
    protected $_installed = null;
    protected $_plugins = null;
    protected $_plugin = null;
    protected $_data = null;
    private $_temp_ext = null;

    /**
     * Plain array of installed-plugin rows, as returned by
     * loadInstalledPlugins(). Kept separate from $_installed (a
     * stdClass keyed by extension name, used elsewhere in this class
     * purely to test "is extension X installed") because an earlier
     * version of loadInstalledPlugins() returned $_installed itself on
     * its cache-hit path and a plain array on its cache-miss path -
     * inconsistent return types from the same method depending on call
     * order within a request. Since PluginModel is a shared singleton
     * per request (see FeedgatorFactory), that meant whichever caller
     * happened to trigger the *first* call got the array, and every
     * other caller (e.g. the fgcontent form field, if the view had
     * already primed the cache) got the stdClass instead - which
     * behaves differently enough in edge cases (e.g. a driver whose
     * config XML failed to parse still appears as a plain DB row in the
     * array, but is never added to $_installed at all) that it was
     * silently dropping drivers from the content-type dropdown.
     */
    private ?array $_installedRows = null;

    public function setExt($ext)
    {
        if ($ext && $ext != $this->_ext) {
            $this->_ext    = $ext;
            $this->_file   = $this->getFilename($ext);
            $this->_plugin = null;
            $this->_data   = null;

            return true;
        }

        return false;
    }

    public function getFilename($ext)
    {
        return 'plg_fg_' . substr($ext, strrpos($ext, '_') + 1);
    }

    /**
     * @param   string|null  $ext   Extension key (e.g. 'com_content'). Defaults to the currently set one.
     * @param   string       $type  'xml' for the driver's config/manifest file, otherwise 'php' for the class file.
     */
    public function getFilePath($ext = null, $type = 'xml')
    {
        $file = $ext ? $this->getFilename($ext) : $this->_file;

        // Drivers now live inside this component (admin/plugins/<short-name>)
        // rather than JPATH_SITE/plugins/feedgator/<file> as in the 2.5
        // version - see class docblock for why.
        $base = \JPATH_ADMINISTRATOR . '/components/com_feedgator/plugins/' . $file;

        return match ($type) {
            'xml'   => $base . '/' . $file . '_config.xml',
            default => $base . '/' . $file . '.' . $type,
        };
    }

    public function getPlugin($ext = null)
    {
        if ($ext) {
            $this->setExt($ext);
        }

        if (!$this->_plugin) {
            $this->_loadPlugin();
        }

        return $this->_plugin;
    }

    public function getPluginData()
    {
        if (!$this->_data) {
            $this->_loadPluginData();
        }

        return $this->_data;
    }

    public function getParams($feedId = '-2')
    {
        $params = null;

        if (!$this->_data) {
            $this->getPluginData();
        }

        if (!isset($this->_data->paramsArray)) {
            $this->_parseParams();
        }

        if (isset($this->_data->paramsArray)) {
            $params = empty($this->_data->paramsArray[$feedId]['--TXT--'])
                ? $this->_data->paramsArray[-2]['--TXT--']
                : $this->_data->paramsArray[$feedId]['--TXT--'];
        }

        return $params;
    }

    protected function _loadDefaultParams()
    {
        $path   = $this->getFilePath($this->_ext);
        $result = '';

        if (file_exists($path) && ($xml = simplexml_load_file($path))) {
            if ((string) $xml->attributes()->group === 'feedgator') {
                foreach ($xml->params as $param) {
                    $key  = (string) $param->attributes()->name;
                    $type = (string) $param->attributes()->type;

                    if ($type !== 'spacer') {
                        $value = str_replace("\n", '\n', (string) $param->attributes()->default);
                        $result .= "$key=$value\n";
                    }
                }
            }
        }

        return $result;
    }

    protected function _loadPlugin()
    {
        if (!$this->_installed) {
            $this->loadInstalledPlugins();
        }

        if (isset($this->_installed->{$this->_ext})) {
            if (!\is_object($this->_plugins)) {
                $this->_plugins = new \stdClass();
            }

            if (!isset($this->_plugins->{$this->_ext})) {
                $file = $this->getFilePath($this->_ext, 'php');

                if (file_exists($file)) {
                    require_once $file;

                    // Driver class names follow the original convention:
                    // plgFeedgator{Ext-suffix}, e.g. plgFeedgatorContent / plgFeedgatorK2 -
                    // but they now live under the component's own namespace rather
                    // than the global namespace, so build a fully-qualified name.
                    $classname = 'Trafalgardesign\\Component\\Feedgator\\Administrator\\Plugin\\plgFeedgator' . ucfirst(substr($this->_ext, 4));

                    $this->_plugins->{$this->_ext} = new $classname();
                    $this->_plugins->{$this->_ext}->setData($this->getPluginData());
                    $this->_plugins->{$this->_ext}->componentInstalled = $this->_plugins->{$this->_ext}->componentCheck();
                }
            }

            $this->_plugin = $this->_plugins->{$this->_ext} ?? null;
        } else {
            $this->_plugin = null;
        }

        return (bool) $this->_plugin;
    }

    protected function _loadPluginData()
    {
        if (!isset($this->_installed->{$this->_ext})) {
            $this->loadInstalledPlugins();
        }

        if (isset($this->_installed->{$this->_ext})) {
            $this->_data = $this->_installed->{$this->_ext};
        }

        return (bool) $this->_data;
    }

    protected function _parseParams()
    {
        if ($this->_loadPluginData()) {
            preg_match_all('/(.?[0-9]+){{?([^}]+)}}?/', $this->_data->params, $paramsList);
            $count = \count($paramsList[1]);

            for ($i = 0; $i < $count; $i++) {
                $this->_data->paramsArray[$paramsList[1][$i]] = $this->_paramsToArray($paramsList[2][$i]);
            }

            if (!isset($this->_data->paramsArray[-2])) {
                $def_params                         = $this->_loadDefaultParams();
                $this->_data->paramsArray[-2] = $this->_paramsToArray($def_params);
            }
        }
    }

    protected function _paramsToArray(&$paramsList)
    {
        if (strpos($paramsList, "\n") === false) {
            $res = json_decode($paramsList, true) ?? [];
            // horrible fix for INI string expected but json used from Joomla 1.6+ onward
            $res['--TXT--'] = str_replace(['":"', '","', '"'], ['=', "\n", ''], $paramsList);
        } else {
            $tmp = explode("\n", $paramsList);
            $res = [];

            foreach ($tmp as $a) {
                if ($a) {
                    [$key, $val] = array_pad(explode('=', $a, 2), 2, null);
                    $res[$key]   = str_replace('\n', "\n", $val);
                }
            }

            $res['--TXT--'] = $paramsList;
        }

        return $res;
    }

    public function setParams($params, $feedId)
    {
        $this->_data->paramsArray[$feedId] = $params;
    }

    public function store($feedId = null, $params = null)
    {
        $app = Factory::getApplication();

        if (!$feedId) {
            $feedId = $app->getInput()->getInt('feedId', -2);
        }

        $id = $app->getInput()->getInt('id');

        if (!$id) {
            $id = $this->getPluginData()->id;
        }

        $row = FeedgatorFactory::getTable('Fgplugin');
        $row->load($id);

        if (!$row->id) {
            return false;
        }

        if (!$params) {
            $params = $app->getInput()->post->get('pluginparams', [], 'array');
        }

        if (empty($params)) {
            return false;
        }

        $paramsTxt = FeedgatorUtility::makeINIString($params);

        if (!$this->_data || !isset($this->_data->paramsArray)) {
            $this->getParams();
        }

        $this->_data->paramsArray[$feedId]             = $params;
        $this->_data->paramsArray[$feedId]['--TXT--']   = $paramsTxt;
        $this->_data->params                            = '';

        foreach ($this->_data->paramsArray as $tmpfeedId => $tmpparams) {
            $this->_data->params .= $tmpfeedId . '{' . $tmpparams['--TXT--'] . '}';
        }

        return $row->save($this->_data);
    }

    public function loadInstalledPlugins()
    {
        if ($this->_installedRows !== null) {
            return $this->_installedRows;
        }

        $db    = $this->getDatabase();
        $query = 'SELECT *,'
            . ' (SELECT SUM(published) FROM #__feedgator_plugins) as pub_count'
            . ' FROM #__feedgator_plugins'
            . ' ORDER BY extension';

        // completePluginInstallation() checks each of the two bundled
        // drivers (com_content, com_k2) individually and only inserts
        // whichever is actually missing - calling it unconditionally
        // here (rather than only when the whole table was empty, as an
        // earlier version of this method did) fixes the case where the
        // table has *some* rows but is still missing one driver, e.g.
        // from an earlier partial/interrupted install. Cheap no-op when
        // both already exist, so safe to call on every request.
        $this->completePluginInstallation();

        $db->setQuery($query);
        $rows = $db->loadObjectList();
        $n    = \count($rows);

        // PHP 8 no longer auto-vivifies null into stdClass on property
        // write (the original relied on that now-removed behaviour) -
        // initialise it explicitly before assigning dynamic properties below.
        if (!\is_object($this->_installed)) {
            $this->_installed = new \stdClass();
        }

        for ($i = 0; $i < $n; $i++) {
            $row             = $rows[$i];
            $row->installed  = false;
            $xmlfile         = $this->getFilePath($row->extension);

            if (file_exists($xmlfile) && ($xml = simplexml_load_file($xmlfile))) {
                // This config XML's root element is deliberately named
                // <fgplugindef>, not <install>/<extension> - it's a
                // plain internal data file for this component's own use
                // (name/version/params for a content-sync driver), read
                // only via child-element lookups below (never checked
                // by tag name), but Joomla's package installer scans
                // .xml files under a component's admin/plugins/ folder
                // for potential sub-extension manifests based on any
                // root element with a "type" attribute - the original
                // <install type="fgplugin"> tripped that scan with
                // "Unknown extension type: fgplugin" and blocked
                // install/update entirely. Don't revert this without
                // confirming that's still fine on a real install.
                if ((string) $xml->attributes()->group !== 'feedgator') {
                    continue;
                }

                $this->_temp_ext = $this->_ext;
                $this->setExt($row->extension);

                $this->_installed->{$this->_ext} = $row;

                $row->name          = (string) $xml->name ?: '';
                $row->creationdate  = (string) $xml->creationDate ?: '';
                $row->updateddate   = (string) $xml->updatedDate ?: '';
                $row->author        = (string) $xml->author ?: '';
                $row->copyright     = (string) $xml->copyright ?: '';
                $row->authorEmail   = (string) $xml->authorEmail ?: '';
                $row->authorUrl     = (string) $xml->authorUrl ?: '';
                $row->version       = (string) $xml->version ?: '';
                $row->icon          = \Joomla\CMS\Uri\Uri::root() . 'administrator/components/com_feedgator/plugins/' . $this->_file . '/' . $this->_file . '.png';
                $row->xmlfile       = $xmlfile;
                $row->params        = $this->_parseParams();

                // $row->published already comes straight from
                // #__feedgator_plugins (the SELECT * at the top of this
                // method) and is this driver's actual enabled/disabled
                // state. An earlier version of this method overwrote it
                // here with a lookup against Joomla's own #__extensions
                // table (folder='feedgator') - that was correct for the
                // original component, which installed these drivers as
                // real Joomla plugin extensions, but this port ships
                // them as plain files with no #__extensions row at all
                // (see MIGRATION_REPORT.md), so that lookup always
                // returned null and silently disabled every driver
                // regardless of its real #__feedgator_plugins.published
                // value. Do not re-add that overwrite.

                $this->getPlugin($this->_ext); // loads plugin and checks if component installed

                $row->componentInstalled = $this->_plugins->{$this->_ext}->componentInstalled;
                $row->installed          = true;

                $this->setExt($this->_temp_ext);
            }
        }

        // NOTE: the original had an unconditional call to
        // _loadPluginData() here. That's both redundant (the loop above
        // already populates $this->_installed for every extension it
        // finds) and actively dangerous with the _installedRows caching
        // added above: if $this->_ext is still unset at this point (a
        // fresh PluginModel that never called setExt() first - exactly
        // what happens when the Scheduled Task plugin builds one from
        // scratch), _loadPluginData() doesn't find a match and calls
        // loadInstalledPlugins() again - and since _installedRows isn't
        // set until after this point, that recursive call re-enters the
        // whole method from scratch, which calls _loadPluginData()
        // again at ITS end, recursing without bound until PHP's memory
        // limit kills the request. Removed entirely rather than guarded,
        // since it wasn't doing anything the loop above hadn't already done.
        $this->_installedRows = $rows;

        return $rows;
    }

    /**
     * Ensures the two bundled drivers (com_content, com_k2) have a row in
     * #__feedgator_plugins. The install SQL already inserts these for a
     * fresh install; this remains as a safety net for upgrades from very
     * old data where the table might be empty.
     */
    public function completePluginInstallation()
    {
        $db = $this->getDatabase();

        $extensions = [
            ['com_content', 1],
            // K2 support dropped - see MIGRATION_REPORT.md (K2 doesn't
            // support Joomla 4+). Removed here, and any leftover row is
            // deleted at install/update time (see script.php).
        ];

        foreach ($extensions as $ext) {
            $query = 'SELECT COUNT(*) FROM `#__feedgator_plugins` WHERE extension=' . $db->quote($ext[0]);
            $db->setQuery($query);

            if ((int) $db->loadResult() === 0) {
                $this->setExt($ext[0]);

                $row              = FeedgatorFactory::getTable('Fgplugin');
                $row->extension   = $ext[0];
                $row->published   = $ext[1];
                $row->params      = '-2{' . $this->_loadDefaultParams() . '}';
                $row->store();
            }
        }

        return true;
    }

    public function renderPluginParams($feedId = -2)
    {
        $ext = Factory::getApplication()->getInput()->get->getCmd('ext', '');

        if (!$ext || $ext == -2) {
            return Text::_('FG_PLG_PARAMS_NOT_LOADED');
        }

        $this->getPlugin($ext);
        $this->_plugin->getParams($feedId);

        return FeedgatorHelper::renderFieldset('params', $this->_plugin->params);
    }
}
