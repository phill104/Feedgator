<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_feed_default.php - this is
 * the "edit the global default feed" screen, so unlike default_feed.php
 * its fieldsets don't show "default setting is..." hints (there's
 * nothing above it to default from).
 *
 * Tab switching uses a small self-contained script rather than relying
 * on data-bs-toggle="tab" auto-initialising via Bootstrap's JS bundle -
 * that depends on Bootstrap's JS actually being loaded on this specific
 * admin view, which isn't something verifiable without a live Joomla 6
 * instance, so this avoids the dependency entirely. Panel IDs are
 * prefixed (fgdefault_panelN) so they can't collide with default_feed.php's
 * identical panel1..panel12 IDs if both were ever rendered on the same
 * page (they aren't currently, but this is cheap insurance).
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

$tabs = [
	'panel1'  => ['FG_TAB_FEED_DETAILS', ['feed_3', 'feed_1', 'feed_2']],
	'panel2'  => ['FG_TAB_PUBLISHING', ['publishing_1', 'publishing_2']],
	'panel3'  => ['FG_TAB_PROCESSING_DUPS', ['duplicates']],
	'panel4'  => ['FG_TAB_TXT_HANDLING', ['text_1', 'text_2']],
	'panel5'  => ['FG_TAB_LANGS', ['languages']],
	'panel6'  => ['FG_TAB_IMGS_ENCS', ['images']],
	'panel7'  => ['FG_TAB_LINKS', ['links']],
	'panel8'  => ['FG_TAB_TXT_FLTRS', ['text']],
	'panel9'  => ['FG_TAB_HTML_FLTRS', ['html']],
	'panel10' => ['FG_TAB_IMPORT_FLTRS', ['import_1', 'import_2', 'import_3']],
	'panel11' => ['FG_TAB_TAGGING', ['tagging']],
];
?>

<div class="fgform">
	<form action="index.php" method="post" name="adminForm" id="adminForm">

		<ul class="nav nav-tabs fg-tabs" role="tablist" data-fg-tabgroup="fgdefault">
			<?php $first = true; foreach ($tabs as $panelId => [$label, ]) : ?>
				<li class="nav-item" role="presentation">
					<button class="nav-link<?php echo $first ? ' active' : ''; ?>" data-fg-target="fgdefault_<?php echo $panelId; ?>" type="button" role="tab"><?php echo Text::_($label); ?></button>
				</li>
				<?php $first = false; endforeach; ?>
			<li class="nav-item" role="presentation">
				<button class="nav-link" data-fg-target="fgdefault_panel12" type="button" role="tab"><?php echo Text::_('FG_TAB_PLG_SETTINGS'); ?></button>
			</li>
		</ul>

		<div class="tab-content" data-fg-tabgroup="fgdefault">
			<?php $first = true; foreach ($tabs as $panelId => [, $fieldsets]) : ?>
				<div class="tab-pane<?php echo $first ? ' show active' : ''; ?>" id="fgdefault_<?php echo $panelId; ?>" role="tabpanel">
					<?php foreach ($fieldsets as $fieldset) { echo FeedgatorHelper::renderFieldset($fieldset, $this->fgParams); } ?>
				</div>
				<?php $first = false; endforeach; ?>

			<div class="tab-pane" id="fgdefault_panel12" role="tabpanel">
				<div id="pluginparams"><?php echo Text::_('FG_PLG_PARAMS_NOT_LOADED'); ?></div>
			</div>
		</div>

		<input type="hidden" name="cid" value="-2" />
		<input type="hidden" name="option" value="com_feedgator" />
		<input type="hidden" name="task" value="" />
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</div>

<script>
document.getElementById('adminForm').addEventListener('submit', function (e) {
	var f = this;
	if (f.params_content_type && f.params_content_type.value === '-1') {
		alert('You must choose a content type');
		e.preventDefault();
	}
});

// Self-contained tab switching - doesn't depend on Bootstrap's JS bundle
// auto-initialising data-bs-toggle="tab" on this page; only needs the
// nav-link/tab-pane CSS classes Bootstrap's stylesheet already defines.
(function () {
	var group = 'fgdefault';
	var tabs  = document.querySelectorAll('[data-fg-tabgroup="' + group + '"].fg-tabs .nav-link');

	tabs.forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var targetId = btn.getAttribute('data-fg-target');

			tabs.forEach(function (b) { b.classList.remove('active'); });
			btn.classList.add('active');

			document.querySelectorAll('[data-fg-tabgroup="' + group + '"].tab-content .tab-pane').forEach(function (pane) {
				pane.classList.remove('show', 'active');
			});

			var target = document.getElementById(targetId);
			if (target) {
				target.classList.add('show', 'active');
			}
		});
	});
})();
</script>
