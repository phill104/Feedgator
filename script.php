<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Joomla 6 / PHP 8.3 installer script.
 *
 * Converted from the original com_feedgatorInstallerScript (Joomla 2.5).
 *
 * IMPORTANT FIX: an earlier version of this file registered an
 * InstallerScriptInterface implementation into the DI container via a
 * ServiceProviderInterface::register() method. That is NOT how Joomla
 * invokes install scripts - Joomla simply `include`s this file and uses
 * whatever it directly `return`s (or, for older-style scripts, a
 * specifically-named class). The DI-container approach meant
 * preflight()/postflight() below were never actually being called. This
 * version returns the script object directly, which is the standard
 * pattern used throughout Joomla core since Joomla 4.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;

return new class implements InstallerScriptInterface {
    /**
     * Minimum PHP version required to install this component. Set to
     * 8.1 (constructor property promotion + match expressions are the
     * newest syntax the converted code actually relies on) rather than
     * 8.3 - an earlier version of this file over-strictly required 8.3
     * based on Joomla 6's general recommended minimum, which isn't
     * actually enforced this strictly and isn't what this code needs.
     *
     * @var string
     */
    protected $minimumPhp = '8.1';

    /**
     * Minimum Joomla version required to install this component.
     *
     * @var string
     */
    protected $minimumJoomla = '5.4';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' && !version_compare(PHP_VERSION, $this->minimumPhp, '>=')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('FG_PHP_VERSION_TOO_LOW', $this->minimumPhp, PHP_VERSION),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

        // Safety net: create the schema here too, not just via the
        // manifest's <install><sql> block. That block only runs on a
        // genuinely fresh install - Joomla's "upgrade" method install
        // path (re-installing over an already-registered extension) does
        // NOT re-run it, so if an earlier broken package version left the
        // extension registered without ever creating the tables, a
        // straight re-install wouldn't fix that. These are all
        // CREATE TABLE IF NOT EXISTS, so they're safe to run every time.
        $this->createSchema($db);

        // Make sure the com_content driver is registered, and force it
        // published=1 on every install/update run. This is deliberately
        // unconditional (not just "insert if missing") because earlier
        // package versions had a bug where toggling a driver's publish
        // state on the Plugins screen incorrectly flipped it based on a
        // now-removed #__extensions lookup, which could leave
        // com_content stuck at published=0 in the database from before
        // that bug was fixed. Forcing it back to 1 here self-heals any
        // site that hit that bug, without requiring a manual DB edit.
        // If you deliberately want com_content disabled, unpublish it
        // from the Plugins screen *after* installing - this only runs
        // at install/update time, not on every page load.
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__feedgator_plugins'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
        $db->setQuery($query);

        if ((int) $db->loadResult() === 0) {
            $row = (object) [
                'extension' => 'com_content',
                'published' => 1,
                'params'    => '-2{}',
            ];
            $db->insertObject('#__feedgator_plugins', $row);
        } else {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__feedgator_plugins'))
                ->set($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
            $db->setQuery($query);
            $db->execute();
        }

        // K2 support was dropped (K2 doesn't support Joomla 4+ anyway -
        // see MIGRATION_REPORT.md). Remove any leftover row from an
        // earlier install that included it.
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__feedgator_plugins'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_k2'));
        $db->setQuery($query);
        $db->execute();

        Factory::getApplication()->enqueueMessage(
            Text::_('FG_INSTALL_SUCCESS'),
            'message'
        );

        return true;
    }

    /**
     * Mirrors admin/sql/install.mysql.utf8.sql - see postflight()'s
     * docblock for why this is also run directly here.
     */
    private function createSchema(\Joomla\Database\DatabaseInterface $db): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS `#__feedgator` (
                `id` int(10) NOT NULL AUTO_INCREMENT,
                `title` varchar(100) NOT NULL DEFAULT 'Untitled',
                `feed` text NOT NULL DEFAULT '',
                `content_type` varchar(50) DEFAULT NULL,
                `sectionid` int(10) NOT NULL DEFAULT 0,
                `catid` int(10) NOT NULL DEFAULT 0,
                `default_author` varchar(100) DEFAULT NULL,
                `default_introtext` varchar(250) DEFAULT NULL,
                `created_by` int(11) NOT NULL DEFAULT 0,
                `created` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
                `checked_out` int(11) unsigned NOT NULL DEFAULT 0,
                `checked_out_time` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
                `last_run` datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
                `last_email` int(11) NOT NULL DEFAULT 0,
                `published` tinyint(1) NOT NULL DEFAULT 0,
                `front_page` tinyint(1) NOT NULL DEFAULT 0,
                `filtering` tinyint(1) NOT NULL DEFAULT 0,
                `filter_whitelist` text NOT NULL DEFAULT '',
                `filter_blacklist` text NOT NULL DEFAULT '',
                `params` text NOT NULL DEFAULT '',
                `imports` text NOT NULL DEFAULT '',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `#__feedgator_imports` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `content_id` int(11) NOT NULL,
                `plugin` text NOT NULL DEFAULT '',
                `feed_id` int(11) NOT NULL,
                `hash` text NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `idx_feed_id` (`feed_id`),
                KEY `idx_content_id` (`content_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `#__feedgator_plugins` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `extension` varchar(100) NOT NULL,
                `published` int(1) NOT NULL DEFAULT 0,
                `params` text NOT NULL DEFAULT '',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($queries as $sql) {
            $db->setQuery($sql);
            $db->execute();
        }

        // CREATE TABLE IF NOT EXISTS is a no-op against tables that
        // already exist, so an earlier broken package version's tables -
        // created with `id` as unsigned (which rejects the -2 sentinel ID
        // this component uses for its "default feed" row) and without
        // DEFAULT '' on the TEXT columns (which MySQL strict mode
        // requires for a NOT NULL column not supplied on every INSERT) -
        // need to be fixed up explicitly here too. Safe to run repeatedly.
        $alterQueries = [
            "ALTER TABLE `#__feedgator`
                MODIFY `id` int(10) NOT NULL AUTO_INCREMENT,
                MODIFY `feed` text NOT NULL DEFAULT '',
                MODIFY `filter_whitelist` text NOT NULL DEFAULT '',
                MODIFY `filter_blacklist` text NOT NULL DEFAULT '',
                MODIFY `params` text NOT NULL DEFAULT '',
                MODIFY `imports` text NOT NULL DEFAULT ''",
            "ALTER TABLE `#__feedgator_imports`
                MODIFY `plugin` text NOT NULL DEFAULT '',
                MODIFY `hash` text NOT NULL DEFAULT ''",
            "ALTER TABLE `#__feedgator_plugins`
                MODIFY `params` text NOT NULL DEFAULT ''",
        ];

        foreach ($alterQueries as $sql) {
            try {
                $db->setQuery($sql);
                $db->execute();
            } catch (\Exception $e) {
                // Non-fatal: don't block install/update over this - worst
                // case the pre-existing "no default" columns remain and
                // the same error can recur, but everything else the
                // installer does still completes.
            }
        }
    }
};
