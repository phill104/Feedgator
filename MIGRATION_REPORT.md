# FeedGator: Joomla 2.5 -> Joomla 6 / PHP 8.3 migration report

This is an honest status report on the conversion, not a claim that the
component is finished or production-ready. **Nothing in this package has
been installed or run against a live Joomla 6 site** - there was no PHP
interpreter, Joomla instance, or network access available in the
environment this was converted in, so everything here needs real-world
testing before use. Treat this as a thorough first pass by someone who
could read but not execute the code, not a finished audit.

## What's converted

**Architecture & install**
- `feedgator.xml` (namespaced, PHP 8.3 requirement, SQL-based install/
  uninstall), `access.xml`, `config.xml`
- `script.php` (install script), `admin/services/provider.php`,
  `admin/src/Extension/FeedgatorComponent.php`
- `sql/install.mysql.utf8.sql`, `sql/uninstall.mysql.utf8.sql`

**Admin side (`admin/`)**
- Tables: `FeedTable`, `FgpluginTable`, `ImportTable`
- Controller: `FeedgatorController` - every task method from the
  original `controller.php`
- Models: `FeedModel` (~875 lines - feed CRUD plus the whole fetch/
  import/dedupe/email-digest pipeline), `PluginModel`, `ToolsModel`
- Helpers: `FeedgatorHelper` and `FeedgatorUtility` (~2,450 lines
  combined) - the content-processing engine: title/alias generation,
  duplicate detection, image download & processing, enclosure handling,
  HTML sanitising via htmLawed, tag/keyword extraction, admin email
  digest
- `FeedgatorFactory` (replaces the old `FGFactory` static registry)
- SimplePie replacement: `SimplePieFeedAdapter` / `SimplePieFeedItemAdapter`
  / `SimplePieEnclosureAdapter` / `SimplePieCategoryAdapter` / `SimplePieAuthorAdapter` -
  **as of the latest round, this is now a small self-contained RSS 2.0 /
  RSS 1.0 (RDF) / Atom parser written directly against PHP's SimpleXML**,
  not a wrapper around Joomla's built-in Feed API. That first attempt
  (wrapping `Joomla\CMS\Feed\FeedFactory`) was found via real-world
  testing to parse channel metadata correctly but return zero items for
  at least one real WordPress feed - Joomla's built-in parser is
  evidently too thin for some real-world RSS extensions/namespaces.
  Bundling an actual current SimplePie release (1.8.x) was considered
  but wasn't practical in this session (its source is split across 50+
  files with no single-file distribution reachable through this
  session's tooling). The current parser handles RSS2 (incl.
  content:encoded, dc:creator, standard `<enclosure>`, Media RSS
  `<media:content>`), RSS1/RDF, and Atom, with **real enclosure
  support** (something the Joomla-Feed-API version could never provide
  at all). It has not been tested against a live Joomla 6 site by
  anyone but you - test it against a representative sample of your
  actual feeds (including any with enclosures) before relying on it.
- Content-sync drivers: `plg_fg_content` (native Joomla articles) and
  `plg_fg_k2` (K2) under `admin/plugins/`
- Views: `View/Feedgator/HtmlView` and `View/Plugin/HtmlView`, plus every
  `tmpl/*.php` template (cpanel, feeds list, feed edit form x2, settings,
  tools, imports, plugins list, plugin settings, about, support)
- Form field classes: `FgbaseField`, `FgaccessField`, `FgauthorsField`,
  `FgcategoriesField`, `FgcontentField` (`admin/src/Field/`)
- Forms moved to `admin/forms/*.xml` with `addfieldpath` updated
- Bundled third-party libs (htmLawed, Readability port, cURL CA bundle)
  copied unchanged; CSS/images copied unchanged
- Starter language file (see caveat below - none existed in the source)

**Site side (`site/`)**
- Minimal controller + hidden empty view, matching the *original*
  component, which had no real front-end UI of its own - imported
  content displays via com_content/K2 as regular articles, so there's
  nothing FeedGator-specific to render publicly (see
  `DisplayController`'s docblock)

**Scheduled Task plugin (`plg_task_feedgator/`, a separate extension)**
- Replaces `cron.feedgator.php` with a proper Joomla Scheduled Task
  (System -> Scheduled Tasks), the modern equivalent and simpler than
  the original's hand-rolled mini-bootstrap of the Joomla environment

**Dropped as dead code / redesigned rather than ported**
- `elements/*.php` (5 files) - Joomla-1.5-era `JElement` parameter
  classes, already superseded within this same codebase by
  `models/fields/*.php` (which was converted instead - see above)
- `plugin.feedgator.installer.php`, the custom `<install type="fgplugin">`
  installer adapter - it used `JInstaller` adapter hooks
  (`parseFiles`/`pushStep`/`copyManifest`) removed in the Joomla 3.x
  installer rewrite, so it hasn't actually worked in a very long time.
  Content-sync drivers are now just PHP files shipped inside the
  component and registered via the install SQL instead.
- Inline MooTools admin JS (import-progress UI, tab/slider widgets,
  dynamic cascading category dropdowns) - MooTools and jQuery UI were
  both removed from Joomla core in Joomla 4. Tabs/accordions are
  replaced with plain Bootstrap 5 markup (needs no JS). The duplicate-
  management screen got a small vanilla-JS replacement. The import-
  progress UI and cascading category dropdowns did **not** get a JS
  replacement - see "Not done" below.

## Known, load-bearing risks (test these specifically)

1. **~~SimplePieFeedAdapter has no enclosure support~~ - resolved.** The
   parser was rewritten as a self-contained RSS/Atom implementation with
   real enclosure support (see above) rather than wrapping Joomla's Feed
   API. Still worth testing against a feed with enclosures to confirm.
   One known gap: `<media:thumbnail>` isn't parsed separately from
   `<media:content>` - see `SimplePieEnclosureAdapter`'s docblock.
2. **htmLawed and the Readability port are unverified on PHP 8** - copied
   unchanged; no PHP interpreter was available here to lint them.
3. **K2 does not currently support Joomla 4+** (confirmed via K2's own
   2026 release notes and community forum posts). `plg_fg_k2` is
   converted and disabled by default, but it cannot work on a stock
   Joomla 6 site regardless of code quality. Delete
   `admin/plugins/plg_fg_k2/` (and its `#__feedgator_plugins` row) if
   you don't use K2.
4. **com_content's Article table API has changed repeatedly across
   J3->4->5->6** (workflow states, associations, custom fields).
   `plg_fg_content` uses the stable, long-lived `#__content` columns and
   Joomla's public `Table` API rather than any internal com_content
   model, but test article creation (featured/category assignment, the
   duplicate-alias-retry logic) against your actual Joomla 6 install.
5. **No language files existed in the uploaded package at all**, despite
   the original manifest referencing them. This is a pre-existing gap in
   the source, not something this conversion introduced. The starter
   `admin/language/en-GB/*.ini` covers the keys the converted code uses;
   merge in a fuller string set if you have one from elsewhere.
6. **All three service providers** (`admin/services/provider.php`,
   `site/services/provider.php`, `plg_task_feedgator/services/provider.php`)
   **and the Scheduled Task plugin class were hand-written from memory**
   of current Joomla component/plugin registration patterns, without
   being able to check them against Joomla core's actual source in this
   session. Diff against a real Joomla 6 checkout (e.g. `com_contact`'s
   `provider.php`, and a core task plugin such as `plg_task_requests`)
   before relying on them.
7. **`addfieldpath` in the forms XML may be redundant or wrong** for
   namespaced field classes - modern Joomla resolves a component's own
   custom field types via its registered namespace automatically in most
   cases. Left in place defensively; verify the content-type/category/
   author dropdowns actually render on the feed edit screen.

## Not done

- **Import-progress UI JS** - the "Processing..." live-update area shown
  when clicking Import/Import All/Preview from the toolbar or feed list.
  The original's MooTools implementation was dropped rather than ported
  to vanilla JS. The underlying AJAX endpoints (`task=import&ajax=1`
  etc.) still exist and work; only the front-end JS driving them is
  missing. Self-contained follow-up work.
- **Cascading category dropdowns** (selecting a content type/section
  narrows the category list via JS) - same story; `changeDynaList()` was
  MooTools-only and wasn't reimplemented. The `onchange` attributes are
  left in place as hooks for a future vanilla-JS version.
- **Toolbar button wiring** - the original's custom toolbar button
  classes (FGPreview/FGImport/FGImportAll/FGDelete) and the
  `toolbar.feedgator.*` files were not converted to Joomla's modern
  fluent `Toolbar` API. The controller and views work without them
  (Joomla renders a default toolbar), but you'll want proper
  titles/buttons per screen - fairly quick, low-risk remaining work.
- **PluginModel/plugin-settings rendering** - the original used
  `JParameter::render()`, which no longer exists (see
  `views/plugin/tmpl/default_settings.php`'s docblock). Currently a
  no-op fallback since both bundled drivers declare zero configurable
  params; only matters if you add a driver with real settings.
- **End-to-end install verification** - never attempted against a real
  Joomla 6 site.

## Suggested next steps, in priority order

1. Spin up a real Joomla 6 + PHP 8.3 test site and attempt to install
   this package (`com_feedgator` first, then `plg_task_feedgator`) - fix
   whatever the installer/PHP error log surfaces first. Expect the
   service-provider wiring and namespace/autoload details to need the
   most correction, since those were written without being able to
   check them against live Joomla source.
2. Decide on SimplePie: keep the adapter vs. bundle an updated SimplePie
   release, based on whether your feeds use enclosures.
3. Decide whether to keep the K2 driver at all.
4. Port the toolbar wiring and the import-progress/cascading-dropdown JS
   if you want the admin UI fully interactive again.

## File map (new vs. original)

| Original | New |
|---|---|
| `controller.php` | `admin/src/Controller/FeedgatorController.php` |
| `factory.feedgator.php` | `admin/src/Helper/FeedgatorFactory.php` |
| `models/feed.php` | `admin/src/Model/FeedModel.php` |
| `models/plugin.php` | `admin/src/Model/PluginModel.php` |
| `models/tools.php` | `admin/src/Model/ToolsModel.php` |
| `tables/feed.php` | `admin/src/Table/FeedTable.php` |
| `tables/fgplugin.php` | `admin/src/Table/FgpluginTable.php` |
| `tables/import.php` | `admin/src/Table/ImportTable.php` |
| `helpers/feedgator.helper.php` | `admin/src/Helper/FeedgatorHelper.php` |
| `helpers/feedgator.utility.php` | `admin/src/Helper/FeedgatorUtility.php` |
| `views/feedgator/view.html.php` | `admin/src/View/Feedgator/HtmlView.php` |
| `views/feedgator/tmpl/*.php` | `admin/tmpl/feedgator/*.php` |
| `views/plugin/view.html.php` | `admin/src/View/Plugin/HtmlView.php` |
| `views/plugin/tmpl/*.php` | `admin/tmpl/plugin/*.php` |
| `models/fields/*.php` | `admin/src/Field/*Field.php` |
| `models/forms/*.xml` | `admin/forms/*.xml` |
| `plugins/com_content/com_content.php` | `admin/plugins/plg_fg_content/plg_fg_content.php` |
| `plugins/com_k2/com_k2.php` | `admin/plugins/plg_fg_k2/plg_fg_k2.php` |
| `script.feedgator.php` | `script.php` |
| `cron.feedgator.php` | `plg_task_feedgator/` (separate extension) |
| `elements/*.php` | *(dropped - dead code, see above)* |
| `plugin.feedgator.installer.php` | *(dropped - see above)* |
