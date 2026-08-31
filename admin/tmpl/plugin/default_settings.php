<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/plugin/tmpl/default_settings.php.
 *
 * NOTE: the original called $this->plugin->params->render('pluginparams')
 * - JParameter's XML-driven auto-render feature. PluginModel::getParams()
 * now returns a plain Joomla\Registry\Registry (JParameter no longer
 * exists), which has no render() method. This is a real gap versus the
 * original for any *custom* content-sync driver with configurable
 * params - but both bundled drivers (com_content, com_k2) declare zero
 * params (`<param type="spacer" .../>` only), so in practice this screen
 * always takes the "no params" branch below. If you add a driver with
 * real per-plugin settings, you'll need to build its settings form from
 * its `_config.xml` via Joomla\CMS\Form\Form instead of relying on
 * ->render().
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

$noParamsLabel = Text::_('FG_PLG_NO_PARAMS');
$hasRealParams = method_exists($this->plugin->params, 'render');
$text          = $hasRealParams ? $this->plugin->params->render('pluginparams') : $noParamsLabel;
?>

<form action="index.php" method="post" name="adminForm" id="adminForm">

	<?php echo $text; ?>

	<?php if (strpos($text, $noParamsLabel) === false) : ?>
		<input type="submit" name="submit" value="Submit" class="btn btn-primary" />
	<?php endif; ?>

	<input type="hidden" name="id" value="<?php echo $this->plugin->id; ?>" />
	<input type="hidden" name="ext" value="<?php echo $this->plugin->extension; ?>" />
	<input type="hidden" name="feedId" value="-2" />
	<input type="hidden" name="option" value="com_feedgator" />
	<input type="hidden" name="task" value="savePluginSettings" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
