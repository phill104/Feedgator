<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/fields/fgcontent.php. See fgbase.php's docblock
 * for why this uses a legacy global class name, and fgcategories.php's
 * docblock re: the (currently no-op) changeDynaList() onchange hook.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;

class JFormFieldFgcontent extends ListField
{
    protected $type = 'Fgcontent';

    public function getInput()
    {
        $feedModel   = FeedgatorFactory::getFeedModel();
        $pluginModel = FeedgatorFactory::getPluginModel();

        $type  = (string) $this->element['var'];
        $class = $this->class ?: 'form-select';

        $fgParams = $feedModel->getParams();

        if (!$this->value) {
            $this->value = $fgParams->getValue('default_type');
        }

        $plugins = $pluginModel->loadInstalledPlugins();

        $options   = [];
        $options[] = HTMLHelper::_('select.option', -1, $type ? '- ' . Text::_('FG_CHOOSE_CONTENT') . ' -' : '- ' . Text::_('FG_DEFAULT_CONTENT') . ' -', 'id', 'title');

        foreach ($plugins as $plugin) {
            if ($plugin->published) {
                $options[] = HTMLHelper::_('select.option', $plugin->extension, $plugin->name, 'id', 'title');
            }
        }

        $javascript = $type
            ? ' onchange="changeDynaList(\'paramssectionid\', contentsections, document.adminForm.paramscontent_type.options[document.adminForm.paramscontent_type.selectedIndex].value, 0, 0); changeDynaList(\'paramscatid\', sectioncategories, document.adminForm.paramssectionid.options[document.adminForm.paramssectionid.selectedIndex].value, 0, 0);"'
            : '';

        return HTMLHelper::_('select.genericlist', $options, $this->name, 'class="' . $class . '"' . $javascript, 'id', 'title', $this->value, $this->id);
    }
}
