<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_settings.php
 */

use Joomla\CMS\HTML\HTMLHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

HTMLHelper::_('bootstrap.tooltip');
?>

<form name="adminForm" id="adminForm" method="post" action="index.php">

	<?php echo FeedgatorHelper::renderFieldset('advanced', $this->config); ?>

	<input type="hidden" name="id" value="<?php echo $this->config->getValue('id'); ?>" />
	<input type="hidden" name="option" value="com_feedgator" />
	<input type="hidden" value="" name="task" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
