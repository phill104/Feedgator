<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/plugin/view.html.php
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\View\Plugin;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorFactory;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public $plugin;
    public $plugins;

    public function display($tpl = null)
    {
        $app   = Factory::getApplication();
        $doc   = $app->getDocument();
        $model = FeedgatorFactory::getPluginModel();

        \Joomla\CMS\HTML\HTMLHelper::_('bootstrap.tooltip');
        $doc->getWebAssetManager()->registerAndUseStyle('com_feedgator.admin', 'components/com_feedgator/css/styles.css');

        if ($tpl === 'settings') {
            $ext = $app->getInput()->getCmd('ext');

            if (!$plugin = $model->getPlugin($ext)) {
                $app->close($ext . ' is not valid');
            }

            if (!$plugin->componentCheck()) {
                $app->enqueueMessage(Text::_('FG_UNABLE_TO_VIEW_COMPONENT_NOT_INSTALLED'), 'warning');
                $app->redirect('index.php?option=com_feedgator&task=plugins');

                return;
            }

            $plugin->getParams();
            $this->plugin = $plugin;
        } else {
            $this->plugins = $model->loadInstalledPlugins();
        }

        $this->addToolbar($tpl);

        parent::display($tpl);
    }

    protected function addToolbar($tpl)
    {
        if ($tpl === 'settings') {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_PLG_OPTIONS'), 'plug');
        } else {
            \Joomla\CMS\Toolbar\ToolbarHelper::title(Text::_('FG_PLUGINS'), 'plug');
            \Joomla\CMS\Toolbar\Toolbar::getInstance()
                ->linkButton('cpanel', Text::_('FG_CPANEL'))
                ->icon('icon-home')
                ->url('index.php?option=com_feedgator&task=cpanel');
        }
    }
}
