# shaarli_table — Plugin Specification

## What it does

`shaarli_table` is a plugin for [Shaarli](https://github.com/shaarli/Shaarli) (tested on 0.12.1) that adds an alternative **Table View** to the bookmark list. The default Shaarli view shows links as cards; this plugin adds a spreadsheet-style view similar to NocoDB, Baserow, or Monday.com.

A **Cards / Table** toggle appears above the link list on every page. The state is carried in the URL via `?view=table`, making the table view bookmarkable and shareable.

---

## Files

```
shaarli_table/
├── shaarli_table.php   — plugin logic and HTML generation
├── shaarli_table.css   — table styles
├── shaarli_table.js    — client-side sort and filter
├── shaarli_table.meta  — one-line description shown in Plugin Administration
└── spec.md             — this file
```

---

## Deployment

Copy the `shaarli_table/` folder into `<shaarli-root>/plugins/`, then enable it under **Administration → Plugins**.

The folder and all filenames use underscores. This is a hard requirement: Shaarli's `PluginManager::buildHookName()` concatenates the directory name directly into PHP function names (`hook_<dir>_<hookname>`). PHP function names cannot contain hyphens, so a hyphenated directory name silently breaks all hooks.

---

## How the plugin integrates with Shaarli

Shaarli's plugin system works by scanning `plugins/*/` for `.meta` files (shown in admin) and calling named PHP functions at render time. Three hooks are used:

### `hook_shaarli_table_render_includes`
Injects the CSS file. CSS must be registered here, not in `render_footer`; the latter only supports JS.

### `hook_shaarli_table_render_footer`
Injects the JS file.

### `hook_shaarli_table_render_linklist`
Main hook. Called when Shaarli renders the bookmark list. `$data['links']` contains all links for the current page. The hook:
1. Reads `$_GET['view']` to detect table mode.
2. Builds toggle URLs by manipulating `$_SERVER['QUERY_STRING']` with `http_build_query`.
3. Injects a toolbar (`plugin_start_zone`) with Cards / Table buttons.
4. In table mode: also injects a `<style>` block that hides the native `.linklist` and paging elements, followed by the generated table HTML.

---

## Table HTML generation (`shaarli_table_build_html`)

Each link in `$data['links']` may present its tags as:
- `taglist` — array of objects/strings (Shaarli 0.12 prepared template data)
- `tags` — space-separated string or array (fallback)

The function normalises both into a `$tagNames` array, then:
- Renders tag pills as `<a href="?searchtags=…&view=table">` links so clicking a tag searches within table view.
- Writes a `data-sort` attribute on the tags `<td>` containing the plain space-joined tag names. This is used by both column sorting and the tag filter.
- Writes `data-sort` on the title `<td>` (the title text) and date `<td>` (compact `YmdHis` timestamp) so the JS sort comparator can use a clean key rather than cell `textContent`.

When logged in, Edit and Delete action buttons are shown in a final column.

---

## Columns

| Column | Sortable | Notes |
|--------|----------|-------|
| # | — | Row number; renumbered after sort |
| Title | yes | Links to the bookmark URL |
| URL | yes | Truncated to 60 chars for display |
| Tags | yes | Pills linking to tag search in table view |
| Description | — | Plain text |
| Date | yes | `created` date, formatted `Y-m-d` |
| Priv. | — | Padlock icon for private bookmarks (logged-in only) |
| Actions | — | Edit / Delete (logged-in only) |

---

## Client-side features (shaarli_table.js)

### Sorting

Clicking a sortable column header toggles asc → desc → asc. The comparator uses `cells[n].getAttribute('data-sort')` when present, falling back to `textContent`. `localeCompare` with `numeric: true` handles mixed text/number values. After sorting, row numbers are renumbered.

### Filtering

Two independent filter inputs, both applied simultaneously (AND logic):

- **Filter rows** (`#st-search`): substring match against the entire row's `textContent`. Catches title, URL, description, tags, and date.
- **Filter by tag** (`#st-tag-filter`): substring match against only the tags cell's `data-sort` attribute (the plain tag name string). This is more precise than the row filter — typing `photo` will not accidentally match a URL that contains `photo`.

Both inputs call `applyFilters()` on every keystroke. A row is visible only when both filters pass. The row count display (`n / total rows`) updates after each filter operation.

### Copy to clipboard

A **Copy** button appears in the toolbar whenever Table view is active. Clicking it serialises the currently visible rows (respecting both active filters) to tab-separated values (TSV) and writes them to the clipboard.

The TSV includes a header row. Columns `#`, `Priv.`, and `Actions` are excluded — they carry no meaningful text. The Tags column uses the `data-sort` attribute (plain tag names) rather than cell `textContent` (which would include link markup). The URL column uses `data-sort` to get the full URL, since the displayed text may be truncated.

`navigator.clipboard.writeText()` is used where available; a `textarea` + `execCommand('copy')` fallback handles older browsers. The button briefly turns green and shows "✓ Copied!" as visual confirmation.

---

## Known limitations

- Pagination is Shaarli's own. The table shows only the links on the current page. To see more rows at once, increase Shaarli's links-per-page setting.
- Descriptions are displayed as plain escaped text; markdown is not rendered.
- No persistent column width or column reordering.
