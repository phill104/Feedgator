<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from tables/feed.php (JTable -> Joomla\CMS\Table\Table)
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

\defined('_JEXEC') or die;

/**
 * Feed table class for the #__feedgator table.
 *
 * Property defaults matter here, not just cosmetically: FeedModel::store()
 * only ever sets a subset of these via bind() before calling
 * save()/store(true) (updateNulls=true) - matching the original 2.5
 * component's design, which never loaded the existing row before a
 * partial bind+store either. Joomla's Table::store() writes every
 * public property to the database regardless of whether bind() touched
 * it, so any property left at PHP's `null` here gets written as NULL -
 * fine for genuinely nullable columns, but a hard error under MySQL
 * strict mode for the several NOT NULL columns below that FeedModel
 * never explicitly sets (checked_out, checked_out_time, last_run,
 * last_email, imports). The original code "worked" here only because
 * old non-strict MySQL silently coerced NULL into a NOT NULL column's
 * zero-equivalent instead of erroring; these defaults reproduce that
 * same effective behaviour explicitly rather than relying on
 * strict-mode-incompatible database leniency.
 */
class FeedTable extends Table
{
    public $id = null;
    public $title = null;
    public $content_type = null;
    public $sectionid = 0;
    public $feed = null;
    public $catid = 0;
    public $published = 0;
    public $front_page = 0;
    public $default_author = null;
    public $default_introtext = null;
    public $created = null;
    public $created_by = 0;
    public $checked_out = 0;
    public $checked_out_time = '1000-01-01 00:00:00';
    public $last_run = '1000-01-01 00:00:00';
    public $last_email = 0;
    public $filtering = 0;
    public $filter_whitelist = '';
    public $filter_blacklist = '';
    public $params = null;
    public $imports = '';

    /**
     * Constructor
     *
     * @param   DatabaseDriver  $db  Database driver object.
     */
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__feedgator', 'id', $db);
    }

    /**
     * Shortcut to bind/check/store, preserved from the original 2.5 override
     * (the base Table::save() signature changed across Joomla versions, so
     * this keeps the original bind -> check -> store -> checkin flow explicit).
     *
     * @param   mixed   $src             An associative array or object to bind to the Table instance.
     * @param   string  $orderingFilter  Filter for the order updating.
     * @param   mixed   $ignore          Fields to ignore while binding.
     *
     * @return  boolean  True on success.
     */
    public function save($src, $orderingFilter = '', $ignore = '')
    {
        if (!$this->bind($src, $ignore)) {
            return false;
        }

        if (!$this->check()) {
            return false;
        }

        if (!$this->store(true)) {
            return false;
        }

        if (!$this->checkin()) {
            return false;
        }

        if ($orderingFilter) {
            $filterValue = $this->$orderingFilter;
            $this->reorder(
                $orderingFilter
                    ? $this->getDatabase()->quoteName($orderingFilter) . ' = ' . $this->getDatabase()->quote($filterValue)
                    : ''
            );
        }

        $this->setError('');

        return true;
    }
}
