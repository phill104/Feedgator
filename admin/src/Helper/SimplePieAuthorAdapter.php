<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Minimal stand-in for SimplePie's author item object.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

\defined('_JEXEC') or die;

class SimplePieAuthorAdapter
{
    public function __construct(private string $name = '', private string $email = '')
    {
    }

    public function get_name()
    {
        return $this->name;
    }

    public function get_email()
    {
        return $this->email;
    }

    public function get_link()
    {
        return '';
    }
}
