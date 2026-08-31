<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_feed.php.
 *
 * Joomla's old `tabs.*` JHtml behaviour (jQuery UI tabs) was removed
 * when Joomla 4 dropped jQuery UI - replaced with Bootstrap 5-styled
 * markup. Tab switching itself uses a small self-contained script
 * rather than relying on data-bs-toggle="tab" auto-initialising via
 * Bootstrap's JS bundle - whether that's actually loaded on this
 * specific admin view isn't something verifiable without a live
 * Joomla 6 instance, so this avoids the dependency entirely.
 *
 * NOT carried over: the original emitted `contentsections`/
 * `sectioncategories` JS arrays consumed by a MooTools `changeDynaList()`
 * function (defined in the now-dead elements/fgcategories.php) to make
 * the category dropdown cascade from the selected content type/section.
 * That cascading-dropdown behaviour needs a small vanilla-JS rewrite if
 * you want it back; for now the category field just shows whatever
 * content type is currently selected server-side.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

$user  = Factory::getApplication()->getIdentity();
$input = Factory::getApplication()->getInput();

// NOTE: $input->get is a GET-only sub-input object and has no post()
// method - "$input->get->post(...)" was an invalid chained call causing
// a fatal error on this screen. The top-level input's own get() already
// reads from Joomla's merged request data (GET+POST), which is what was
// actually intended here.
$edit = \in_array($input->get('task', '', 'cmd'), ['new', 'add'], true) ? false : true;

$tabs = [
	'panel1'  => ['FG_TAB_FEED_DETAILS', ['feed_3', 'feed_1:default', 'feed_2:default']],
	'panel2'  => ['FG_TAB_PUBLISHING', ['publishing_1:default', 'publishing_2:default']],
	'panel3'  => ['FG_TAB_PROCESSING_DUPS', ['duplicates:default']],
	'panel4'  => ['FG_TAB_TXT_HANDLING', ['text_1:default', 'text_2:default']],
	'panel5'  => ['FG_TAB_LANGS', ['languages:default']],
	'panel6'  => ['FG_TAB_IMGS_ENCS', ['images:default']],
	'panel7'  => ['FG_TAB_LINKS', ['links:default']],
	'panel8'  => ['FG_TAB_TXT_FLTRS', ['text:default']],
	'panel9'  => ['FG_TAB_HTML_FLTRS', ['html:default']],
	'panel10' => ['FG_TAB_IMPORT_FLTRS', ['import_1:default', 'import_2:default', 'import_3:default']],
	'panel11' => ['FG_TAB_TAGGING', ['tagging:default']],
];
?>

<div id="fgmsgarea"></div>

<div class="fgform">
	<form action="index.php" method="post" name="adminForm" id="adminForm">

		<ul class="nav nav-tabs fg-tabs" role="tablist" data-fg-tabgroup="fgfeed">
			<?php $first = true; foreach ($tabs as $panelId => [$label, ]) : ?>
				<li class="nav-item" role="presentation">
					<button class="nav-link<?php echo $first ? ' active' : ''; ?>" data-fg-target="fgfeed_<?php echo $panelId; ?>" type="button" role="tab"><?php echo Text::_($label); ?></button>
				</li>
				<?php $first = false; endforeach; ?>
			<li class="nav-item" role="presentation">
				<button class="nav-link" data-fg-target="fgfeed_panel12" type="button" role="tab"><?php echo Text::_('FG_TAB_PLG_SETTINGS'); ?></button>
			</li>
		</ul>

		<div class="tab-content" data-fg-tabgroup="fgfeed">
			<?php $first = true; foreach ($tabs as $panelId => [, $fieldsets]) : ?>
				<div class="tab-pane<?php echo $first ? ' show active' : ''; ?>" id="fgfeed_<?php echo $panelId; ?>" role="tabpanel">
					<?php
					foreach ($fieldsets as $fieldset) {
						[$name, $showDefault] = array_pad(explode(':', $fieldset), 2, null);
						echo FeedgatorHelper::renderFieldset($name, $this->fgParams, $showDefault === 'default');
					}
					?>
				</div>
				<?php $first = false; endforeach; ?>

			<div class="tab-pane" id="fgfeed_panel12" role="tabpanel">
				<div id="pluginparams"><?php echo Text::_('FG_PLG_PARAMS_NOT_LOADED'); ?></div>
			</div>
		</div>

		<input type="hidden" name="cid" value="<?php echo $this->fgParams->getValue('id'); ?>" />
		<input type="hidden" name="created_by" value="<?php echo $this->fgParams->getValue('created_by') ?: $user->id; ?>" />
		<input type="hidden" name="option" value="com_feedgator" />
		<input type="hidden" name="task" value="edit" />
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>
</div>

<script>
document.getElementById('adminForm').addEventListener('submit', function (e) {
	var f = this;
	if (!f.params_feed || !f.params_feed.value) {
		alert('You must at least enter a feed.');
		e.preventDefault();
	} else if (!f.params_title || !f.params_title.value) {
		alert('You must enter a title.');
		e.preventDefault();
	} else if (f.paramscontent_type && f.paramscontent_type.value === '-1') {
		alert('You must choose a content type');
		e.preventDefault();
	} else if (f.paramscatid && !f.paramscatid.value) {
		alert('You must enter a category');
		e.preventDefault();
	}
});

// Self-contained tab switching - doesn't depend on Bootstrap's JS bundle
// auto-initialising data-bs-toggle="tab" on this page; only needs the
// nav-link/tab-pane CSS classes Bootstrap's stylesheet already defines.
(function () {
	var group = 'fgfeed';
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
