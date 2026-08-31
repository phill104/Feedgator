<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from factory.feedgator.php (FGFactory).
 *
 * The original used JModelLegacy::getInstance()'s internal static registry.
 * That registry is gone in Joomla 6, so this now goes through the
 * component's MVCFactory (the same factory Joomla itself uses to build
 * models for the current component) and caches instances the same way the
 * original static properties did.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Table\Table;
use Trafalgardesign\Component\Feedgator\Administrator\Model\FeedModel;
use Trafalgardesign\Component\Feedgator\Administrator\Model\PluginModel;
use Trafalgardesign\Component\Feedgator\Administrator\Model\ToolsModel;

\defined('_JEXEC') or die;

abstract class FeedgatorFactory
{
    private static ?FeedModel $feedModel = null;
    private static ?ToolsModel $toolsModel = null;
    private static ?PluginModel $pluginModel = null;

    public static function getFeedModel(): FeedModel
    {
        if (!self::$feedModel) {
            self::$feedModel = self::getMVCFactory()->createModel('Feed', 'Administrator', ['ignore_request' => false]);
        }

        return self::$feedModel;
    }

    public static function getToolsModel(): ToolsModel
    {
        if (!self::$toolsModel) {
            self::$toolsModel = self::getMVCFactory()->createModel('Tools', 'Administrator', ['ignore_request' => false]);
        }

        return self::$toolsModel;
    }

    public static function getPluginModel(): PluginModel
    {
        if (!self::$pluginModel) {
            self::$pluginModel = self::getMVCFactory()->createModel('Plugin', 'Administrator', ['ignore_request' => false]);
        }

        return self::$pluginModel;
    }

    /**
     * Creates one of this component's Table objects (Feed / Fgplugin /
     * Import) via the component's MVCFactory.
     *
     * An earlier version of this codebase used
     * Table::getInstance($name, 'Trafalgardesign\\...\\Table\\') directly
     * throughout - Table::getInstance()'s string-building for namespaced
     * prefixes didn't resolve to this component's actual class names
     * (FeedTable/FgpluginTable/ImportTable) and silently returned false
     * instead of throwing, which is a much worse failure mode than an
     * exception. createTable() goes through Joomla's real, documented
     * table-resolution mechanism instead of string-guessing a class name.
     *
     * @param   string  $name  'Feed', 'Fgplugin', or 'Import'.
     */
    public static function getTable(string $name): Table
    {
        return self::getMVCFactory()->createTable($name, 'Administrator');
    }

    public static function getMVCFactory(): MVCFactoryInterface
    {
        return Factory::getApplication()->bootComponent('com_feedgator')->getMVCFactory();
    }
}
