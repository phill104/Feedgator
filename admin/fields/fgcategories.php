<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/fields/fgcategories.php. See fgbase.php's
 * docblock for why this uses a legacy global class name instead of
 * this component's own namespace.
 *
 * NOTE: the `onchange="changeDynaList(...)"` behaviour references a
 * MooTools-only JS helper that was part of the dropped inline scripts
 * (see the Feedgator HtmlView's docblock) - the attribute is kept so a
 * future vanilla-JS reimplementation has somewhere to hook in, but it's
 * a no-op today.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;

class JFormFieldFgcategories extends ListField
{
    protected $type = 'Fgcategories';

    public function getInput()
    {
        $app       = Factory::getApplication();
        $feedModel = FeedgatorFactory::getFeedModel();
        $cid       = (array) $app->getInput()->get('cid', [], 'array');
        $cid       = empty($cid) ? 0 : (int) $cid[0];
        $default   = $cid == -2;

        $type  = (string) $this->element['var'];
        $class = $this->class ?: 'form-select';

        $fgParams    = $feedModel->getParams();
        $fgdefParams = $feedModel->getDefaultParams(true);

        if (!$fgParams->getValue('content_type')) {
            $fgParams->setValue('content_type', null, $fgdefParams->getValue('content_type', null, 'com_content'));
        }

        $plugin     = $feedModel->getPlugin();
        $javascript = '';

        if ($type === 'section') {
            $javascript = ' onchange="changeDynaList(\'datacatid\', sectioncategories, document.adminForm.datasectionid.options[document.adminForm.datasectionid.selectedIndex].value, 0, 0);"';
            $title      = $default ? '- ' . Text::_('FG_SELECT_SECTION') . ' -' : Text::_('FG_USE_DEFAULT');
            $options    = ($cid || $fgParams->getValue('default_type')) ? $plugin->getSectionList($fgParams, $default) : [HTMLHelper::_('select.option', '', $title, 'id', 'title')];
        } else {
            $title   = ($cid == -2) ? '- ' . Text::_('FG_SELECT_CATEGORY') . ' -' : Text::_('FG_USE_DEFAULT');
            $options = ($cid || $fgParams->getValue('default_type')) ? $plugin->getCategoryList($fgParams, $default) : [HTMLHelper::_('select.option', '', $title, 'id', 'title')];
        }

        return HTMLHelper::_('select.genericlist', $options, $this->name, 'class="' . $class . '"' . $javascript, 'id', 'title', $this->value, $this->id);
    }
}
