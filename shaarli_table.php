<?php
/**
 * Shaarli Table View Plugin
 *
 * Displays bookmarks as an interactive spreadsheet-like table,
 * inspired by NocoDB / Baserow / Monday.com.
 *
 * Compatible with Shaarli 0.12.x
 * Deploy: copy the shaarli_table/ folder to <shaarli>/plugins/shaarli_table/
 *         then enable it in Shaarli's Plugin Administration page.
 */

use Shaarli\Plugin\PluginManager;

/**
 * Plugin initialisation — no configuration needed.
 */
function shaarli_table_init($conf)
{
    // Nothing to configure.
}

// ---------------------------------------------------------------------------
// Hook: render_includes  (CSS files go here, not in render_footer)
// ---------------------------------------------------------------------------

function hook_shaarli_table_render_includes($data)
{
    $data['css_files'][] = PluginManager::$PLUGINS_PATH . '/shaarli_table/shaarli_table.css';
    return $data;
}

// ---------------------------------------------------------------------------
// Hook: render_footer  (JS files)
// ---------------------------------------------------------------------------

function hook_shaarli_table_render_footer($data)
{
    $data['js_files'][] = PluginManager::$PLUGINS_PATH . '/shaarli_table/shaarli_table.js';
    return $data;
}

// ---------------------------------------------------------------------------
// Hook: render_linklist
// ---------------------------------------------------------------------------

function hook_shaarli_table_render_linklist($data)
{
    $isTableView = isset($_GET['view']) && $_GET['view'] === 'table';
    $isLoggedIn  = !empty($data['_LOGGEDIN_']);

    // Build toggle URLs from the current query string.
    parse_str($_SERVER['QUERY_STRING'] ?? '', $params);

    $tableParams = array_merge($params, ['view' => 'table']);
    $tableUrl    = '?' . http_build_query($tableParams);

    $cardParams = $params;
    unset($cardParams['view']);
    $cardUrl = empty($cardParams) ? '?' : '?' . http_build_query($cardParams);

    $tableUrlEsc = htmlspecialchars($tableUrl, ENT_QUOTES);
    $cardUrlEsc  = htmlspecialchars($cardUrl,  ENT_QUOTES);

    // Toolbar
    $activeCard  = $isTableView ? '' : ' active';
    $activeTable = $isTableView ? ' active' : '';

    $copyBtn = $isTableView
        ? '<button id="st-copy-btn" class="st-copy-btn" title="Copy visible rows to clipboard">&#128203; Copy</button>'
        : '';

    $toolbar = <<<HTML
<div class="shaarli-table-toolbar">
  <span class="st-view-label">View:</span>
  <a href="{$cardUrlEsc}"  class="st-view-btn{$activeCard}"  title="Card view">&#9783; Cards</a>
  <a href="{$tableUrlEsc}" class="st-view-btn{$activeTable}" title="Table view">&#9783; Table</a>
  {$copyBtn}
</div>
HTML;

    if ($isTableView) {
        $links     = $data['links'] ?? [];
        $tableHtml = shaarli_table_build_html($links, $isLoggedIn);

        // Hide the native linklist while table is shown.
        $hideNative = '<style>.linklist,.shaarli-paging,#shaarli-paging{display:none!important;}</style>';

        $data['plugin_start_zone'][] = $toolbar . $hideNative . $tableHtml;
    } else {
        $data['plugin_start_zone'][] = $toolbar;
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Table HTML builder
// ---------------------------------------------------------------------------

function shaarli_table_build_html(array $links, $loggedIn)
{
    $html  = '<div class="st-wrapper">';

    // Controls
    $html .= '<div class="st-controls">';
    $html .= '<input type="text" id="st-search"     placeholder="Filter rows…"   autocomplete="off">';
    $html .= '<input type="text" id="st-tag-filter" placeholder="Filter by tag…" autocomplete="off">';
    $html .= '<span id="st-count" class="st-count"></span>';
    $html .= '</div>';

    $html .= '<div class="st-scroll">';
    $html .= '<table class="st-table" id="st-table">';

    // Header
    $html .= '<thead><tr>';
    $html .= '<th class="col-num">#</th>';
    $html .= '<th class="col-title sortable" data-col="1">Title <span class="sort-icon"></span></th>';
    $html .= '<th class="col-url   sortable" data-col="2">URL <span class="sort-icon"></span></th>';
    $html .= '<th class="col-tags sortable" data-col="3">Tags <span class="sort-icon"></span></th>';
    $html .= '<th class="col-desc">Description</th>';
    $html .= '<th class="col-date sortable" data-col="5">Date <span class="sort-icon"></span></th>';
    if ($loggedIn) {
        $html .= '<th class="col-priv">Priv.</th>';
        $html .= '<th class="col-actions">Actions</th>';
    }
    $html .= '</tr></thead><tbody>';

    $rowNum = 1;
    foreach ($links as $link) {
        $id    = htmlspecialchars((string)($link['id'] ?? ''), ENT_QUOTES);
        $title = htmlspecialchars($link['title'] ?? 'Untitled', ENT_QUOTES);
        $url   = $link['url'] ?? '';
        $urlH  = htmlspecialchars($url, ENT_QUOTES);
        $desc  = htmlspecialchars($link['description'] ?? '', ENT_QUOTES);
        $priv  = !empty($link['private']);

        // Tags — collect names first (used for both sort key and pill HTML).
        // Shaarli 0.12 may pass taglist as array of objects/strings, or tags as a space-separated string.
        $tagNames = [];
        if (!empty($link['taglist']) && is_array($link['taglist'])) {
            foreach ($link['taglist'] as $tagEntry) {
                $t = trim(is_array($tagEntry) ? ($tagEntry['value'] ?? '') : (string)$tagEntry);
                if ($t !== '') $tagNames[] = $t;
            }
        } elseif (!empty($link['tags'])) {
            $tagStr = is_array($link['tags']) ? implode(' ', $link['tags']) : (string)$link['tags'];
            foreach (explode(' ', trim($tagStr)) as $t) {
                $t = trim($t);
                if ($t !== '') $tagNames[] = $t;
            }
        }

        $tagsHtml  = '';
        foreach ($tagNames as $t) {
            $tagsHtml .= '<a href="?searchtags=' . urlencode($t) . '&amp;view=table" class="st-tag">'
                . htmlspecialchars($t, ENT_QUOTES) . '</a>';
        }
        // data-sort value: space-joined tag names, used for column sorting and tag filter
        $tagSort = htmlspecialchars(implode(' ', $tagNames), ENT_QUOTES);

        // Date
        $dateDisplay = '';
        $dateSort    = '';
        $created     = $link['created'] ?? null;
        if ($created !== null) {
            if ($created instanceof \DateTimeInterface) {
                $dateDisplay = $created->format('Y-m-d');
                $dateSort    = $created->format('YmdHis');
            } elseif (is_int($created)) {
                $dateDisplay = date('Y-m-d', $created);
                $dateSort    = date('YmdHis', $created);
            } else {
                $dateDisplay = substr((string)$created, 0, 10);
                $dateSort    = str_replace(['-', 'T', ':'], '', (string)$created);
            }
        }

        // Truncated URL for display
        $displayUrl  = (strlen($url) > 60) ? substr($url, 0, 57) . '…' : $url;
        $displayUrlH = htmlspecialchars($displayUrl, ENT_QUOTES);

        $rowClass = $priv ? 'st-row st-private' : 'st-row';

        $html .= '<tr class="' . $rowClass . '">';
        $html .= '<td class="col-num">' . $rowNum . '</td>';

        $html .= '<td class="col-title" data-sort="' . $title . '">'
            . '<a href="' . $urlH . '" target="_blank" rel="noopener noreferrer">' . $title . '</a>'
            . '</td>';

        $html .= '<td class="col-url" data-sort="' . $urlH . '">'
            . '<a href="' . $urlH . '" target="_blank" rel="noopener noreferrer" title="' . $urlH . '">'
            . $displayUrlH . '</a></td>';

        $html .= '<td class="col-tags" data-sort="' . $tagSort . '">' . ($tagsHtml ?: '<span class="st-empty">—</span>') . '</td>';

        $html .= '<td class="col-desc">' . ($desc ?: '<span class="st-empty">—</span>') . '</td>';

        $html .= '<td class="col-date" data-sort="' . $dateSort . '">' . $dateDisplay . '</td>';

        if ($loggedIn) {
            $html .= '<td class="col-priv">'
                . ($priv ? '<span class="st-priv-badge" title="Private">&#128274;</span>' : '')
                . '</td>';

            $editUrl   = '?do=editlink&amp;id=' . $id;
            $deleteUrl = '?do=deletelink&amp;id=' . $id;
            $html .= '<td class="col-actions">'
                . '<a href="' . $editUrl . '" class="st-act" title="Edit">&#9998;</a>'
                . '<a href="' . $deleteUrl . '" class="st-act st-del" title="Delete"'
                . ' onclick="return confirm(\'Delete this link?\')">&#10005;</a>'
                . '</td>';
        }

        $html .= '</tr>';
        $rowNum++;
    }

    $html .= '</tbody></table></div></div>';
    return $html;
}
