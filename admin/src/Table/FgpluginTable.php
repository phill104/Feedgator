<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from tables/fgplugin.php
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

\defined('_JEXEC') or die;

/**
 * Table class for #__feedgator_plugins - tracks the content-sync drivers
 * (bundled "com_content" / "com_k2" drivers, or any third-party ones) and
 * their per-feed parameter storage.
 */
class FgpluginTable extends Table
{
    public $id = null;
    public $extension = '';
    public $published = 0;
    public $params = '';

    /**
     * Constructor
     *
     * @param   DatabaseDriver  $db  Database driver object.
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__feedgator_plugins', 'id', $db);
    }
}
