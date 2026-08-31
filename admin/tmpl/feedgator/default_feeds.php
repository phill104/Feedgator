<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_feeds.php.
 *
 * Preview/Import/Import All are rendered here as plain HTML buttons
 * rather than via Joomla's Toolbar API, and drive a small JS function
 * (fgImportTask(), near the bottom of this file) that submits the
 * existing list form with task=import and the requested type. This is
 * simpler than the original's MooTools-based live per-feed progress UI
 * (MooTools was removed from Joomla core in Joomla 4 - see HtmlView's
 * docblock) - clicking one of these submits the page and shows Joomla's
 * plain import-result text afterwards, rather than an in-page progress
 * bar. Real functionality, just less polished than the original.
 *
 * The front-page toggle below is a plain link to the controller's
 * existing front_yes/front_no tasks.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;

\defined('_JEXEC') or die;

$ordering = \in_array($this->lists['order'], ['section_name', 'cat_name'], true);
$app      = Factory::getApplication();
$user     = $app->getIdentity();
$token    = Session::getFormToken();

$warning = false;

foreach ($this->plugins as $row) {
    if (!$row->pub_count && !$warning) {
        ?>
        <div class="alert alert-warning"><?php echo Text::_('FG_NO_PLGS_PUBLISHED'); ?></div>
        <?php
        $warning = true;
    }
}
?>

<div id="fgmsgarea"></div>

<div class="btn-group" style="margin-bottom: 1em;">
	<button type="button" class="btn btn-secondary" onclick="fgImportTask('preview');"><span class="icon-eye" aria-hidden="true"></span> <?php echo Text::_('FG_PREVIEW'); ?></button>
	<button type="button" class="btn btn-secondary" onclick="fgImportTask('all');"><span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('FG_IMPORT_ALL'); ?></button>
	<button type="button" class="btn btn-secondary" onclick="fgImportTask('feed');"><span class="icon-upload" aria-hidden="true"></span> <?php echo Text::_('FG_IMPORT'); ?></button>
</div>

<form action="index.php" method="post" name="adminForm" id="adminForm">
	<table>
		<tr>
			<td width="100%">
				<?php echo Text::_('JSEARCH_FILTER'); ?>:
				<input type="text" name="search" id="search" value="<?php echo htmlspecialchars($this->lists['search']); ?>" class="form-control" onchange="document.adminForm.submit();" title="<?php echo Text::_('FG_FILTER_BY_TITLE_OR_ID'); ?>" />
				<button class="btn" onclick="this.form.submit();"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
				<button class="btn" onclick="document.getElementById('search').value='';this.form.submit();"><?php echo Text::_('JSEARCH_FILTER_CLEAR'); ?></button>
			</td>
		</tr>
	</table>
	<table class="table" cellspacing="1">
		<thead>
			<tr>
				<th width="5"><?php echo Text::_('FG_NUM'); ?></th>
				<th width="5"><input type="checkbox" name="checkall-toggle" value="" onclick="Joomla.checkAll(this);" /></th>
				<th class="title"><?php echo HTMLHelper::_('grid.sort', 'JGLOBAL_TITLE', 'title', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="1%" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JENABLED', 'published', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="1%" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JFEATURED', 'front_page', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="20%" nowrap="nowrap" class="title"><?php echo HTMLHelper::_('grid.sort', 'FG_FEED_GATOR', 'feed', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="8%" nowrap="nowrap" class="title"><?php echo HTMLHelper::_('grid.sort', 'FG_CONTENT_TYPE', 'content_type', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="8%" nowrap="nowrap" class="title"><?php echo HTMLHelper::_('grid.sort', 'JCATEGORY', 'cat_name', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="10" align="center"><?php echo HTMLHelper::_('grid.sort', 'FG_LAST_RUN', 'created', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
				<th width="1%" class="title"><?php echo HTMLHelper::_('grid.sort', 'JGLOBAL_NUM_ID', 'id', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			</tr>
		</thead>
		<tfoot>
		<tr>
			<td colspan="9"><?php echo $this->page->getListFooter(); ?></td>
		</tr>
		</tfoot>
		<tbody>
			<?php
			$k = 0;

			for ($i = 0, $n = \count($this->rows); $i < $n; $i++) {
				$row  = $this->rows[$i];
				$link = 'index.php?option=com_feedgator&task=edit&cid[]=' . $row->id;

				// Actual row-selection checkbox (needed for the
                // Publish/Unpublish/Delete/Import/Preview toolbar
                // buttons, all of which act on selected rows via
                // cid[]). This was missing entirely before - only a
                // "checked out" lock icon was rendered here, which is a
                // different thing and isn't a selectable input, so
                // nothing could actually be selected.
                $checked = HTMLHelper::_('grid.id', $i, $row->id);

                $published = HTMLHelper::_('grid.published', $row, $i, 'feeds.', true, 'published');
                ?>
                <tr class="<?php echo 'row' . $k; ?>">
                    <td><?php echo $this->page->getRowOffset($i); ?></td>
                    <td class="text-center feedid"><?php echo $checked; ?></td>
                    <td class="feedtitle" data-id="<?php echo $row->id; ?>" title="<?php echo $row->title; ?>">
                        <?php
                        if ($user->id == $row->checked_out) {
                            echo $row->title;
                        } else {
                            ?>
                            <a href="<?php echo Route::_($link); ?>"><?php echo htmlspecialchars($row->title, ENT_QUOTES); ?></a>
                            <?php
                        }
                        ?>
                    </td>
                    <td width="2%" class="text-center"><?php echo $published; ?></td>
                    <td width="2%" class="text-center">
                        <a href="<?php echo Route::_('index.php?option=com_feedgator&task=' . ($row->front_page ? 'front_no' : 'front_yes') . '&cid[]=' . $row->id . '&' . $token . '=1'); ?>" title="<?php echo $row->front_page ? Text::_('JYES') : Text::_('JNO'); ?>">
                            <?php echo $row->front_page ? Text::_('JYES') : Text::_('JNO'); ?>
                        </a>
                    </td>
                    <td align="left"><?php echo htmlspecialchars($row->feed); ?></td>
                    <td align="left"><?php echo $row->content_type; ?></td>
                    <td align="left"><?php echo $row->cat_name; ?></td>
                    <td align="left"><?php echo $row->last_run; ?></td>
                    <td><?php echo $row->id; ?></td>
                </tr>
                <?php
                $k = 1 - $k;
            }
            ?>
		</tbody>
	</table>
	<input type="hidden" name="option" value="com_feedgator" />
	<input type="hidden" name="task" value="feeds" />
	<input type="hidden" name="boxchecked" value="0" />
	<input type="hidden" name="filter_order" value="<?php echo $this->lists['order']; ?>" />
	<input type="hidden" name="filter_order_Dir" value="<?php echo $this->lists['order_Dir']; ?>" />
	<input type="hidden" name="type" value="" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>

<script>
// Drives the Preview/Import/Import All toolbar buttons. Simpler than the
// original's MooTools-based live per-feed progress UI (which relied on
// libraries no longer loaded in Joomla 6 - see HtmlView's docblock) -
// this just submits the existing list form with task=import and the
// requested type, and shows Joomla's plain result page. Real
// functionality, just without the fancy in-page progress bar.
function fgImportTask(type) {
	var form = document.adminForm;

	if (type !== 'all' && (!form.boxchecked || form.boxchecked.value == 0)) {
		alert('<?php echo addslashes(Text::_('FG_SELECT_ITEM_TO_IMPORT')); ?>');
		return;
	}

	form.task.value = 'import';
	form.type.value = type;
	form.submit();
}
</script>
