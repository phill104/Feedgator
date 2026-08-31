<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * The original component had no real public-facing UI - its manifest's
 * <files folder="site"> only shipped feedgator.php plus a hidden,
 * empty "default" view (metadata.xml: <view hidden="true" />). FeedGator
 * imports feed content into native Joomla articles (or K2 items), and
 * those display through com_content/K2 as normal - there's nothing
 * front-end-specific for FeedGator itself to render. This mirrors that:
 * a minimal controller + hidden empty view, not a feed-reader UI.
 */

namespace Trafalgardesign\Component\Feedgator\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        return parent::display($cachable, $urlparams);
    }
}
