<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Minimal stand-in for SimplePie's category item object - only
 * get_label() is ever called on it (see FeedgatorHelper::processFeedItem()'s
 * "save_feed_cats" handling). Note that only the first RSS <category> per
 * item is captured (see SimplePieFeedAdapter::buildItemFromRssNode()) -
 * feeds with multiple categories per item only get the first here.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

\defined('_JEXEC') or die;

class SimplePieCategoryAdapter
{
    public function __construct(private string $label)
    {
    }

    public function get_label()
    {
        return $this->label;
    }
}
