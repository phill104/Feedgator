<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/fields/fgaccess.php. See fgbase.php's docblock
 * for why this uses a legacy global class name instead of this
 * component's own namespace.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

class JFormFieldFgaccess extends ListField
{
    protected $type = 'Fgaccess';

    public function getInput()
    {
        $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $cid = (array) Factory::getApplication()->getInput()->get('cid', [], 'array');
        $cid = empty($cid) ? 0 : (int) $cid[0];

        $class = $this->class ?: 'form-select';

        $query = 'SELECT a.id AS value, a.title AS text'
            . ' FROM #__viewlevels AS a'
            . ' GROUP BY a.id'
            . ' ORDER BY a.ordering ASC, `title` ASC';
        $db->setQuery($query);

        $groups = [HTMLHelper::_('select.option', '', ($cid == -2) ? Text::_('FG_SELECT_GROUP') : Text::_('FG_USE_DEFAULT'), 'value', 'text')];
        $groups = array_merge($groups, $db->loadObjectList());

        return HTMLHelper::_('select.genericlist', $groups, $this->name, 'class="' . $class . '"', 'value', 'text', $this->value, $this->id);
    }
}
