<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/plugin/tmpl/default.php
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

// NOTE: the original called HTMLHelper::_('behavior.modal', 'a.modal-button')
// here to pop the "Options" link below open in a modal/lightbox. That
// jQuery-era HTML helper method was removed entirely from Joomla core
// (part of the same cleanup that dropped jQuery/MooTools/Bootstrap 2)
// and calling it now throws a fatal error rather than silently doing
// nothing. Rather than guess at Joomla 6's current (unverified in this
// environment) modal mechanism, "Options" below is now a plain link
// that navigates to the plugin settings screen directly instead of
// opening a popup - simpler and guaranteed not to depend on an API that
// might not exist the way this assumes.

$warning = false;
?>

<form name="adminForm" enctype="multipart/form-data" method="post" action="index.php">
	<fieldset class="paramform">
		<legend><?php echo Text::_('FG_INSTALLED_PLGS'); ?></legend>
		<div id="plugins">
			<?php if (!\count($this->plugins)) : ?>
				<div><?php echo Text::_('FG_NO_PLGS_INSTALLED'); ?></div>
			<?php else :
				foreach ($this->plugins as $row) {
					if (!$row->pub_count && !$warning) {
						?>
						<div class="alert alert-warning"><?php echo Text::_('FG_NO_PLGS_PUBLISHED'); ?></div>
						<?php
						$warning = true;
					}

					if ($row->extension && !isset($row->name)) {
						?>
						<div class="plugin orphaned">
							<div class="titlebar">
								<div class="pluginname red"><?php echo $row->extension; ?></div>
								<div class="plugincomponent red"><?php echo Text::_('FG_LEGACY_PLUGIN_NOT_COMPLIANT') . ' ' . FeedgatorHelper::getFGVersion(); ?></div>
								<div class="pluginversion">&nbsp;</div>
								<div class="spacer"></div>
							</div>
							<div class="insidebox">
								<div class="plugindate"><?php echo Text::_('FG_PLG_CREATED') . ': ' . ($row->creationdate ?? '&nbsp;'); ?></div>
								<div class="plugindate"><?php echo Text::_('FG_PLG_UPDATED') . ': ' . ($row->updateddate ?? '&nbsp;'); ?></div>
								<div class="pluginauthor"><?php echo Text::_('FG_PLG_AUTHOR') . ': ' . ($row->author ?: Text::_('FG_UNKNOWN_AUTHOR')); ?></div>
								<div class="pluginemail"><?php echo Text::_('FG_PLG_AUTHOR_EMAIL') . ': ' . ($row->authorEmail ? ' &lt;' . $row->authorEmail . '&gt;' : '&nbsp;'); ?></div>
							</div>
						</div>
						<?php
					} else {
						?>
						<div class="plugin <?php echo $row->published ? 'published' : 'unpublished'; ?> <?php echo $row->componentInstalled ? 'installed' : 'orphaned'; ?>">
							<div class="titlebar">
								<img src="<?php echo $row->icon; ?>" alt="<?php echo $row->name; ?>" class="pluginlogo" />
								<div class="pluginname red"><?php echo $row->name; ?></div>
								<div class="plugincomponent <?php echo $row->componentInstalled ? 'green' : 'red'; ?>"><?php echo Text::_('JGLOBAL_COMPONENT') . ': ' . ($row->componentInstalled ? Text::_('JINSTALLED') : Text::_('FG_NOT_INSTALLED')); ?></div>
								<div class="pluginversion"><?php echo Text::_('FG_PLG_VERSION') . ': ' . ($row->version ?: '&nbsp;'); ?></div>
								<div class="spacer"></div>
							</div>
							<div class="insidebox">
								<div class="plugindate"><?php echo Text::_('FG_PLG_CREATED') . ': ' . ($row->creationdate ?? '&nbsp;'); ?></div>
								<div class="plugindate"><?php echo Text::_('FG_PLG_UPDATED') . ': ' . ($row->updateddate ?? '&nbsp;'); ?></div>
								<div class="pluginauthor"><?php echo Text::_('FG_PLG_AUTHOR') . ': ' . ($row->author ?: Text::_('FG_UNKNOWN_AUTHOR')); ?></div>
								<div class="pluginemail"><?php echo Text::_('FG_PLG_AUTHOR_EMAIL') . ': ' . ($row->authorEmail ? ' &lt;' . $row->authorEmail . '&gt;' : '&nbsp;'); ?></div>
								<div class="plugintaskbar">
									<?php if ($row->componentInstalled) : ?>
										<a href="index.php?option=com_feedgator&task=pluginSettings&ext=<?php echo $row->extension; ?>"><span class="options_img"><?php echo Text::_('FG_PLG_OPTIONS'); ?></span></a>
										<a href="index.php?option=com_feedgator&task=changePluginState&ext=<?php echo $row->extension; ?>&id=<?php echo $row->id; ?>"><span class="<?php echo $row->published ? 'unpublished_img' : 'published_img'; ?>"><?php echo $row->published ? Text::_('FG_PLG_PUBLISHED') : Text::_('FG_PLG_UNPUBLISHED'); ?></span></a>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php
					}
				}
			endif; ?>
		</div>
	</fieldset>

	<input type="hidden" name="option" value="com_feedgator" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
