<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from tables/import.php
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

\defined('_JEXEC') or die;

/**
 * Table class for #__feedgator_imports - records which remote feed items
 * have already been imported (and into which content item), used for
 * duplicate detection.
 */
class ImportTable extends Table
{
    public $id = null;
    public $content_id = 0;
    public $plugin = '';
    public $feed_id = 0;
    public $hash = '';

    /**
     * Constructor
     *
     * @param   DatabaseDriver  $db  Database driver object.
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__feedgator_imports', 'id', $db);
    }
}
