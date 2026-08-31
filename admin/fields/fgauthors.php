<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/fields/fgauthors.php. See fgbase.php's docblock
 * for why this uses a legacy global class name instead of this
 * component's own namespace.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

class JFormFieldFgauthors extends ListField
{
    protected $type = 'Fgauthors';

    public function getInput()
    {
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $cid = (array) Factory::getApplication()->getInput()->get('cid', [], 'array');
        $cid = empty($cid) ? 0 : (int) $cid[0];

        $class = $this->class ?: 'form-select';

        $query = 'SELECT u.id AS id, u.name AS text'
            . ' FROM #__users AS u'
            . ' INNER JOIN #__user_usergroup_map AS um ON um.user_id = u.id'
            . ' WHERE u.block = 0'
            . ' AND um.group_id != 2' // above registered
            . ' GROUP BY u.name'
            . ' ORDER BY u.name';
        $db->setQuery($query);

        $authors = [HTMLHelper::_('select.option', '', ($cid == -2) ? Text::_('FG_SELECT_AUTHOR') : Text::_('FG_USE_DEFAULT'), 'id', 'text')];
        $authors = array_merge($authors, $db->loadObjectList());

        return HTMLHelper::_('select.genericlist', $authors, $this->name, 'class="' . $class . '"', 'id', 'text', $this->value, $this->id);
    }
}
