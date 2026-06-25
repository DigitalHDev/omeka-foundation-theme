# Foundation Theme (Dev) - Omeka S 4.2

## Project Structure

This is a customized version of the Omeka S Foundation theme ("Foundation-Dev", v1.5.3). It is a PHP theme built on the ZURB Foundation CSS framework.

### Two-repo setup

- **Theme repo** (this directory): `C:\Users\gilsh\source\repos\omeka-foundation-theme - Dev`
  Contains the theme files that get deployed to an Omeka S installation's `themes/` directory.
- **Omeka S core** (read-only reference): `C:\Users\gilsh\source\repos\omeka-s`
  The full Omeka S 4.2 source. Use this to look up core view helpers, controllers, API behavior, default templates, and resource page block logic. Do not modify files here.

### Key directories

- `view/` — Theme view templates (`.phtml` files). Overrides core templates from `omeka-s/application/view/`.
- `view/common/resource-page-block-layout/` — Custom resource page blocks (tab navigation, custom callouts).
- `view/common/block-template/` — Block templates for browse preview, assets, list-of-sites, etc.
- `asset/css/` — Compiled CSS stylesheets (default, inkwell, revolution, seafoam).
- `asset/scss/` — SCSS source files. Compiled via gulp (`gulpfile.js`).
- `asset/js/` — JavaScript files (`show.js`, `browse.js`).
- `helper/` — PHP view helper classes (`TabManager.php`, `SecondDegreeResources.php`).
- `config/theme.ini` — Theme configuration: metadata, form elements for admin settings, resource page regions/blocks, block templates, page templates.

### Custom additions beyond upstream Foundation theme

- **TabManager** helper — manages tab-based navigation on resource show pages.
- **SecondDegreeResources** helper — fetches and displays resources connected through intermediate resources (second-degree connections).
- Custom resource page block layouts: `tab-navigation`, `customCallout`, `customCalloutFrame`.
- Custom block templates for browse preview (grid/list/toggle), assets (card), list-of-sites (card), page title (accent).
- Multiple resource page regions: `full_width_main`, `main`, `right` sidebar, `left` sidebar.

### Design considerations

- All pages and views should be mobile firendly

## Local tooling

- **PHP 8.3** (matches the omeka.net host) is installed but not on PATH. Full
  path: `C:\Users\gilsh\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`.
  Use it for syntax linting, e.g. `& "<that path>" -l <file.phtml>`. Note: this is
  CLI-only — there is no local Omeka install or database, so the theme cannot be
  rendered locally; render/test by deploying into the VM sandbox site.

## Conventions

- Templates use PHP short echo tags (`<?= ?>`).
- View helpers are called via `$this->helperName()` in `.phtml` files.
- Theme settings are accessed via `$this->themeSetting('setting_name')`.
- CSS is compiled from SCSS using gulp. Edit `.scss` files, not `.css` directly.
- The theme targets Omeka S ^4.1.0 (currently running 4.2).

## SecondDegreeResources usage policy

The `SecondDegreeResources` helper encapsulates tested traversal logic for the
site's Event-centric graph (Document/Photograph → Event → Organization, and
Event → Person via creator roles). On all pages **other than the home page**,
use this helper for second-degree connections. Its logic has been tested in
production; **any change to its behavior must be discussed before implementation.**

The home page may use a leaner, purpose-built lookup for performance (it resolves
many random items per request), but it should **reuse code/logic from
`SecondDegreeResources` where possible** rather than diverging. Do not silently
fork its traversal rules.

## Looking up Omeka S internals

When you need to understand how a core feature works (view helpers, API queries, resource representations, block layouts), look in the omeka-s repo:

- View helpers: `omeka-s/application/src/View/Helper/`
- Default site templates: `omeka-s/application/view/omeka/site/`
- Common partials: `omeka-s/application/view/common/`
- API adapters: `omeka-s/application/src/Api/Adapter/`
- Entity representations: `omeka-s/application/src/Api/Representation/`
- Block layouts: `omeka-s/application/src/Site/BlockLayout/`
- Resource page block layouts: `omeka-s/application/src/Site/ResourcePageBlockLayout/`
