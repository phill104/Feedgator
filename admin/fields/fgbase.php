<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from models/fields/fgbase.php.
 *
 * NOT namespaced, deliberately: this is loaded via the forms XML's
 * `addfieldpath` attribute, which is Joomla's legacy custom-field-type
 * mechanism (FormHelper::loadFieldType()). That mechanism includes the
 * file and then looks for a class literally named
 * "JFormField" . ucfirst($type) in the GLOBAL namespace - it has no
 * awareness of this component's own PSR-4 namespace. An earlier version
 * of these field classes lived under src/Field/ with modern namespaced
 * names (FgbaseField etc.), which addfieldpath could never actually
 * find, silently dropping these fields from every fieldset that used
 * them. This legacy naming is the long-stable, still-fully-supported
 * way to register a custom field type via addfieldpath.
 */

\defined('_JEXEC') or die;

class JFormFieldFgbase extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Fgbase';

    public function getInput()
    {
        $base = substr(\Joomla\CMS\Uri\Uri::base(), 0, strpos(\Joomla\CMS\Uri\Uri::base(), 'administrator/'));

        return '<input type="text" name="' . $this->name . '" id="' . $this->id . '" value="' . $base . '" class="form-control" size="50">';
    }
}
