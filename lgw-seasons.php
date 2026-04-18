<?php
/**
 * LGW Seasons — multi-season archive and management.
 * v7.1.27
 *
 * Data model
 * ----------
 * lgw_seasons  WP option — array of season objects:
 *   [
 *     {
 *       "id":        "2026",           // year string, used as slug
 *       "label":     "2026 Season",
 *       "active":    true,             // only one season is active
 *       "divisions": [                 // one entry per division/CSV
 *         { "division": "Division 1", "csv_url": "https://..." },
 *         ...
 *       ]
 *     },
 *     ...
 *   ]
 *
 * Scorecards get a lgw_sc_season post-meta stamped on submission.
 * When a season is archived all untagged scorecards are back-stamped.
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

function lgw_get_seasons() {
    return get_option('lgw_seasons', array());
}

function lgw_get_active_season() {
    foreach (lgw_get_seasons() as $s) {
        if (!empty($s['active'])) return $s;
    }
    return null;
}

function lgw_get_active_season_id() {
    $s = lgw_get_active_season();
    return $s ? $s['id'] : '';
}

/**
 * Returns true if the given scorecard post belongs to the active season,
 * or if no season system is in use (no seasons configured / no tag on the post).
 * Used to decide whether Drive/Sheets writeback should run.
 */
function lgw_scorecard_is_active_season($post_id) {
    $active_id = lgw_get_active_season_id();
    if (!$active_id) return true; // No season system — always allow writeback
    $sc_season = get_post_meta($post_id, 'lgw_sc_season', true);
    if (!$sc_season) return true; // Untagged scorecard — assume current
    return $sc_season === $active_id;
}

/** Return a season by ID, or null. */
function lgw_get_season_by_id($id) {
    foreach (lgw_get_seasons() as $s) {
        if ($s['id'] === $id) return $s;
    }
    return null;
}

/** Return CSV URL for a given division name in a given season. */
function lgw_season_csv_for_division($season_id, $division_name) {
    $s = lgw_get_season_by_id($season_id);
    if (!$s) return '';
    foreach ($s['divisions'] as $d) {
        if (strtolower($d['division']) === strtolower($division_name)) {
            return $d['csv_url'];
        }
    }
    return '';
}

/**
 * Build the seasons data array that gets localised into lgwData.seasons.
 * Keyed by season ID.  Only includes the fields the JS needs.
 */
function lgw_seasons_for_js() {
    $out = array();
    foreach (lgw_get_seasons() as $s) {
        $divs = array();
        foreach (($s['divisions'] ?? array()) as $d) {
            $divs[] = array(
                'division' => $d['division'],
                'csv_url'  => $d['csv_url'],
            );
        }
        $out[$s['id']] = array(
            'id'        => $s['id'],
            'label'     => $s['label'],
            'active'    => !empty($s['active']),
            'divisions' => $divs,
        );
    }
    return $out;
}

// ── Scorecard season stamping ─────────────────────────────────────────────────

/**
 * Stamp all scorecards that have no lgw_sc_season with the given season ID.
 * Called when archiving the active season so they're attributed correctly.
 */
function lgw_backfill_scorecard_seasons($season_id) {
    if (!$season_id) return 0;
    $posts = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => 'lgw_sc_season',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ));
    foreach ($posts as $p) {
        update_post_meta($p->ID, 'lgw_sc_season', $season_id);
    }
    return count($posts);
}

// ── Admin menu registration ───────────────────────────────────────────────────

function lgw_seasons_register_submenu() {
    add_submenu_page(
        'lgw-scorecards',
        'Seasons',
        '📅 Seasons',
        'manage_options',
        'lgw-seasons',
        'lgw_seasons_admin_page'
    );
}

// ── POST handlers ─────────────────────────────────────────────────────────────

add_action('admin_init', 'lgw_seasons_handle_posts');
function lgw_seasons_handle_posts() {
    if (!current_user_can('manage_options')) return;

    // ── Save active season divisions ──────────────────────────────────────────
    if (isset($_POST['lgw_save_active_season']) && check_admin_referer('lgw_seasons_nonce')) {
        $seasons  = lgw_get_seasons();
        $label    = sanitize_text_field($_POST['lgw_active_label'] ?? '');
        $start    = sanitize_text_field($_POST['lgw_active_start'] ?? '');
        $end      = sanitize_text_field($_POST['lgw_active_end']   ?? '');
        $div_names = isset($_POST['lgw_div_name'])   ? array_map('sanitize_text_field', $_POST['lgw_div_name'])   : array();
        $div_urls  = isset($_POST['lgw_div_csv_url']) ? array_map('esc_url_raw',         $_POST['lgw_div_csv_url']) : array();

        $divisions = array();
        foreach ($div_names as $i => $name) {
            $name = trim($name);
            $url  = trim($div_urls[$i] ?? '');
            if ($name !== '' && $url !== '') {
                $divisions[] = array('division' => $name, 'csv_url' => $url);
            }
        }

        // Find and update active season, or create one
        $found = false;
        foreach ($seasons as &$s) {
            if (!empty($s['active'])) {
                if ($label) $s['label'] = $label;
                $s['start']     = $start;
                $s['end']       = $end;
                $s['divisions'] = $divisions;
                $found = true;
                break;
            }
        }
        unset($s);

        if (!$found) {
            // No active season yet — create one using the year as ID
            $year = date('Y');
            $seasons[] = array(
                'id'        => $year,
                'label'     => $label ?: $year . ' Season',
                'active'    => true,
                'start'     => $start,
                'end'       => $end,
                'divisions' => $divisions,
            );
        }

        update_option('lgw_seasons', $seasons);

        // Sync active season divisions back into lgw_drive sheets_tabs
        // so Quick Score Entry and Sheets writeback stay in step
        lgw_seasons_sync_to_drive($divisions);

        wp_redirect(admin_url('admin.php?page=lgw-seasons&saved=1'));
        exit;
    }

    // ── Archive active season ─────────────────────────────────────────────────
    if (isset($_POST['lgw_archive_season']) && check_admin_referer('lgw_seasons_nonce')) {
        $seasons      = lgw_get_seasons();
        $archive_id   = sanitize_text_field($_POST['lgw_archive_id']    ?? '');
        $archive_label = sanitize_text_field($_POST['lgw_archive_label'] ?? '');
        $new_id       = sanitize_text_field($_POST['lgw_new_season_id']  ?? '');
        $new_label    = sanitize_text_field($_POST['lgw_new_season_label'] ?? '');

        if (!$archive_id || !$new_id) {
            wp_redirect(admin_url('admin.php?page=lgw-seasons&error=missing_ids'));
            exit;
        }

        // Stamp un-tagged scorecards with the outgoing season
        lgw_backfill_scorecard_seasons($archive_id);

        // Mark current active as archived, add new active season
        $updated = array();
        foreach ($seasons as $s) {
            if (!empty($s['active'])) {
                $s['active'] = false;
                $s['id']     = $archive_id;
                $s['label']  = $archive_label ?: ($archive_id . ' Season');
            }
            $updated[] = $s;
        }
        // Prepend new active season (newest first)
        array_unshift($updated, array(
            'id'        => $new_id,
            'label'     => $new_label ?: ($new_id . ' Season'),
            'active'    => true,
            'divisions' => array(), // empty — user fills in after archiving
        ));
        update_option('lgw_seasons', $updated);

        wp_redirect(admin_url('admin.php?page=lgw-seasons&archived=1'));
        exit;
    }

    // ── Add or update backloaded (historical) season ─────────────────────────
    if (isset($_POST['lgw_add_archived_season']) && check_admin_referer('lgw_seasons_nonce')) {
        $id          = sanitize_text_field($_POST['lgw_backload_id']       ?? '');
        $editing_id  = sanitize_text_field($_POST['lgw_editing_season_id'] ?? '');
        $label       = sanitize_text_field($_POST['lgw_backload_label']    ?? '');
        $div_names   = isset($_POST['lgw_bl_div_name'])    ? array_map('sanitize_text_field', $_POST['lgw_bl_div_name'])    : array();
        $div_urls    = isset($_POST['lgw_bl_div_csv_url']) ? array_map('esc_url_raw',         $_POST['lgw_bl_div_csv_url']) : array();

        if (!$id) {
            wp_redirect(admin_url('admin.php?page=lgw-seasons&error=missing_id'));
            exit;
        }

        $divisions = array();
        foreach ($div_names as $i => $name) {
            $name = trim($name);
            $url  = trim($div_urls[$i] ?? '');
            if ($name !== '' && $url !== '') {
                $divisions[] = array('division' => $name, 'csv_url' => $url);
            }
        }

        $seasons = lgw_get_seasons();

        if ($editing_id) {
            // ── Update existing archived season ───────────────────────────────
            $found = false;
            foreach ($seasons as &$s) {
                if ($s['id'] === $editing_id && empty($s['active'])) {
                    $s['label']     = $label ?: ($id . ' Season');
                    $s['start']     = sanitize_text_field($_POST['lgw_backload_start'] ?? '');
                    $s['end']       = sanitize_text_field($_POST['lgw_backload_end']   ?? '');
                    $s['divisions'] = $divisions;
                    $found = true;
                    break;
                }
            }
            unset($s);
            if (!$found) {
                wp_redirect(admin_url('admin.php?page=lgw-seasons&error=not_found'));
                exit;
            }
            update_option('lgw_seasons', $seasons);
            wp_redirect(admin_url('admin.php?page=lgw-seasons&updated=1'));
            exit;
        }

        // ── Add new season — reject duplicates ────────────────────────────────
        if (lgw_get_season_by_id($id)) {
            wp_redirect(admin_url('admin.php?page=lgw-seasons&error=duplicate_id&id=' . urlencode($id)));
            exit;
        }

        $seasons[] = array(
            'id'        => $id,
            'label'     => $label ?: ($id . ' Season'),
            'active'    => false,
            'start'     => sanitize_text_field($_POST['lgw_backload_start'] ?? ''),
            'end'       => sanitize_text_field($_POST['lgw_backload_end']   ?? ''),
            'divisions' => $divisions,
        );
        // Sort: active first, then descending by ID
        usort($seasons, function($a, $b) {
            if (!empty($a['active'])) return -1;
            if (!empty($b['active'])) return 1;
            return strcmp($b['id'], $a['id']);
        });
        update_option('lgw_seasons', $seasons);

        wp_redirect(admin_url('admin.php?page=lgw-seasons&backloaded=1'));
        exit;
    }

    // ── Delete archived season ────────────────────────────────────────────────
    if (isset($_GET['lgw_delete_season']) && isset($_GET['_wpnonce'])) {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'lgw_delete_season_' . $_GET['lgw_delete_season'])) {
            wp_die('Security check failed.');
        }
        $del_id  = sanitize_text_field($_GET['lgw_delete_season']);
        $seasons = lgw_get_seasons();
        $seasons = array_values(array_filter($seasons, function($s) use ($del_id) {
            return $s['id'] !== $del_id || !empty($s['active']); // never delete active
        }));
        update_option('lgw_seasons', $seasons);
        wp_redirect(admin_url('admin.php?page=lgw-seasons&deleted=1'));
        exit;
    }
}

/**
 * Sync the active season divisions into lgw_drive sheets_tabs so that
 * Quick Score Entry and Sheets writeback remain in step.
 * Preserves any existing tab settings (tab name, etc.) by merging.
 */
function lgw_seasons_sync_to_drive($divisions) {
    $drive = get_option('lgw_drive', array());
    $existing_tabs = $drive['sheets_tabs'] ?? array();

    // Build a lookup of existing tab settings by csv_url
    $existing_by_url = array();
    foreach ($existing_tabs as $t) {
        $url = $t['csv_url'] ?? '';
        if ($url) $existing_by_url[$url] = $t;
    }

    $new_tabs = array();
    foreach ($divisions as $d) {
        $url = $d['csv_url'] ?? '';
        if (!$url) continue;
        $existing = $existing_by_url[$url] ?? array();
        $new_tabs[] = array_merge($existing, array(
            'csv_url'  => $url,
            'division' => $d['division'],
        ));
    }

    $drive['sheets_tabs'] = $new_tabs;
    update_option('lgw_drive', $drive);
}

// ── Admin page ─────────────────────────────────────────────────────────────────

function lgw_seasons_admin_page() {
    $seasons       = lgw_get_seasons();
    $active        = lgw_get_active_season();
    $archived      = array_values(array_filter($seasons, function($s) { return empty($s['active']); }));
    // Sort archived descending by ID
    usort($archived, function($a, $b) { return strcmp($b['id'], $a['id']); });
    ?>
    <div class="wrap lgw-admin-wrap">
    <?php lgw_page_header('Seasons'); ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p>Active season saved.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['archived'])): ?>
        <div class="notice notice-success is-dismissible"><p>Season archived. Set up your new season divisions below.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['backloaded'])): ?>
        <div class="notice notice-success is-dismissible"><p>Historical season added.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible"><p>Season updated.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="notice notice-success is-dismissible"><p>Season deleted.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible"><p>
        <?php
        $err = $_GET['error'];
        if ($err === 'missing_ids')   echo 'Season IDs are required.';
        elseif ($err === 'missing_id') echo 'Season ID is required.';
        elseif ($err === 'duplicate_id') echo 'A season with ID <strong>' . esc_html($_GET['id'] ?? '') . '</strong> already exists.';
        else echo esc_html($err);
        ?>
        </p></div>
    <?php endif; ?>

    <style>
    .lgw-seasons-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:4px;align-items:start}
    .lgw-seasons-grid input{width:100%}
    .lgw-season-card{border:1px solid #c3c4c7;border-radius:6px;padding:18px 20px;background:#fff;margin-bottom:20px}
    .lgw-season-card h3{margin:0 0 12px;font-size:15px;color:#1a2e5a}
    .lgw-archived-list{border:1px solid #c3c4c7;border-radius:6px;overflow:hidden;margin-bottom:20px}
    .lgw-archived-row{display:grid;grid-template-columns:1fr auto;align-items:center;padding:10px 16px;border-bottom:1px solid #eee;background:#fff}
    .lgw-archived-row:last-child{border-bottom:none}
    .lgw-archived-row:nth-child(even){background:#f9f9f9}
    .lgw-archived-divs{font-size:12px;color:#666;margin-top:3px}
    .lgw-archived-actions{display:flex;gap:8px;align-items:center}
    .lgw-div-rows{display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
    .lgw-div-row{display:grid;grid-template-columns:180px 1fr auto;gap:8px;align-items:center}
    .lgw-div-row input{width:100%}
    </style>

    <form method="post">
        <?php wp_nonce_field('lgw_seasons_nonce'); ?>

        <!-- ── Active Season ── -->
        <div class="lgw-season-card">
            <h3>⚡ Active Season</h3>
            <?php if ($active): ?>
                <table class="form-table" style="margin-bottom:12px">
                    <tr>
                        <th style="width:160px"><label for="lgw_active_label">Season label</label></th>
                        <td><input type="text" id="lgw_active_label" name="lgw_active_label"
                               value="<?php echo esc_attr($active['label']); ?>"
                               class="regular-text" placeholder="e.g. 2026 Season"></td>
                    </tr>
                    <tr>
                        <th><label for="lgw_active_start">Season start date</label></th>
                        <td>
                            <input type="date" id="lgw_active_start" name="lgw_active_start"
                                   value="<?php echo esc_attr($active['start'] ?? ''); ?>">
                            <p class="description">Used by Player Tracking to filter appearances to this season. Leave blank for all-time totals.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lgw_active_end">Season end date</label></th>
                        <td><input type="date" id="lgw_active_end" name="lgw_active_end"
                               value="<?php echo esc_attr($active['end'] ?? ''); ?>"></td>
                    </tr>
                </table>
            <?php else: ?>
                <p style="color:#666;margin-bottom:12px">No active season yet. Add your first season below — the season ID should be the year (e.g. <strong>2026</strong>).</p>
                <table class="form-table" style="margin-bottom:12px">
                    <tr>
                        <th style="width:160px"><label for="lgw_active_label">Season label</label></th>
                        <td><input type="text" id="lgw_active_label" name="lgw_active_label"
                               value="" class="regular-text" placeholder="e.g. 2026 Season"></td>
                    </tr>
                    <tr>
                        <th><label for="lgw_active_start">Season start date</label></th>
                        <td>
                            <input type="date" id="lgw_active_start" name="lgw_active_start" value="">
                            <p class="description">Used by Player Tracking to filter appearances to this season. Leave blank for all-time totals.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lgw_active_end">Season end date</label></th>
                        <td><input type="date" id="lgw_active_end" name="lgw_active_end" value=""></td>
                    </tr>
                </table>
            <?php endif; ?>

            <p style="font-weight:600;margin-bottom:8px;font-size:13px">Divisions</p>
            <div class="lgw-div-rows" id="lgw-active-div-rows">
                <?php
                $active_divs = $active['divisions'] ?? array(array('division'=>'','csv_url'=>''));
                if (empty($active_divs)) $active_divs = array(array('division'=>'','csv_url'=>''));
                foreach ($active_divs as $d):
                ?>
                <div class="lgw-div-row">
                    <input type="text" name="lgw_div_name[]"
                           value="<?php echo esc_attr($d['division']); ?>"
                           placeholder="Division name (e.g. Div 1)">
                    <input type="url" name="lgw_div_csv_url[]"
                           value="<?php echo esc_url($d['csv_url']); ?>"
                           placeholder="Google Sheets published CSV URL">
                    <button type="button" class="button-link-delete lgw-remove-div-row">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="button" class="button" id="lgw-add-div-row">+ Add Division</button>
            </p>
            <p>
                <input type="submit" name="lgw_save_active_season" class="button button-primary" value="Save Active Season">
            </p>
        </div>

        <!-- ── Archive Season ── -->
        <?php if ($active && !empty($active['divisions'])): ?>
        <div class="lgw-season-card" style="border-color:#e8b400">
            <h3>📦 Archive This Season &amp; Start New</h3>
            <p style="color:#555;font-size:13px;margin-bottom:16px">
                Archives the current season, stamps all untagged scorecards with the current season, and prepares a fresh active season for you to configure.
            </p>
            <table class="form-table">
                <tr>
                    <th style="width:200px">Current season ID</th>
                    <td>
                        <input type="text" name="lgw_archive_id"
                               value="<?php echo esc_attr($active['id']); ?>"
                               class="small-text" readonly style="background:#f6f7f7">
                    </td>
                </tr>
                <tr>
                    <th>Current season label</th>
                    <td>
                        <input type="text" name="lgw_archive_label"
                               value="<?php echo esc_attr($active['label']); ?>"
                               class="regular-text" placeholder="e.g. 2026 Season">
                    </td>
                </tr>
                <tr>
                    <th><label for="lgw_new_season_id">New season ID</label></th>
                    <td>
                        <input type="text" id="lgw_new_season_id" name="lgw_new_season_id"
                               value="<?php echo esc_attr(intval($active['id']) + 1); ?>"
                               class="small-text" placeholder="e.g. 2027">
                        <p class="description">Use the year. You'll configure the new season's divisions after archiving.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgw_new_season_label">New season label</label></th>
                    <td>
                        <input type="text" id="lgw_new_season_label" name="lgw_new_season_label"
                               value="<?php echo esc_attr((intval($active['id']) + 1) . ' Season'); ?>"
                               class="regular-text" placeholder="e.g. 2027 Season">
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="lgw_archive_season" class="button"
                       style="border-color:#e8b400;color:#856404;background:#fff8e1"
                       value="📦 Archive Season &amp; Start New"
                       onclick="return confirm('Archive the <?php echo esc_js($active['label']); ?> season and start a new one?\n\nAll untagged scorecards will be stamped with this season.')">
            </p>
        </div>
        <?php endif; ?>

    </form>

    <!-- ── Archived / Historical Seasons ── -->
    <h2 style="margin-top:8px">Archived &amp; Historical Seasons</h2>
    <p style="color:#666;font-size:13px;margin-top:0">
        Past seasons whose division CSV URLs are saved here can be shown in the front-end season switcher.
        Add the <code>seasons</code> attribute to the <code>[lgw_division]</code> shortcode to enable it.
    </p>

    <?php if (!empty($archived)): ?>
    <div class="lgw-archived-list">
        <?php foreach ($archived as $s):
            $div_summary = implode(', ', array_map(function($d){ return $d['division']; }, $s['divisions'] ?? array()));
        ?>
        <div class="lgw-archived-row">
            <div>
                <strong><?php echo esc_html($s['label']); ?></strong>
                <span style="margin-left:8px;font-size:11px;color:#888;font-family:monospace"><?php echo esc_html($s['id']); ?></span>
                <?php if (!empty($s['start']) || !empty($s['end'])): ?>
                <span style="margin-left:10px;font-size:11px;color:#555">
                    <?php echo esc_html(($s['start'] ?? '?') . ' – ' . ($s['end'] ?? '?')); ?>
                </span>
                <?php endif; ?>
                <div class="lgw-archived-divs">
                    <?php echo $div_summary ? esc_html($div_summary) : '<em>No divisions</em>'; ?>
                    (<?php echo count($s['divisions'] ?? array()); ?> division<?php echo count($s['divisions'] ?? array()) !== 1 ? 's' : ''; ?>)
                </div>
            </div>
            <div class="lgw-archived-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lgw-players&season=' . urlencode($s['id']))); ?>"
                   class="button button-small">👥 Players</a>
                <?php
                $bf_nonce = wp_create_nonce('lgw_backfill_players_' . $s['id']);
                ?>
                <button type="button" class="button button-small lgw-backfill-btn"
                        data-id="<?php echo esc_attr($s['id']); ?>"
                        data-label="<?php echo esc_attr($s['label']); ?>"
                        data-nonce="<?php echo esc_attr($bf_nonce); ?>">🔄 Backfill Players</button>
                <button type="button" class="button button-small lgw-edit-archived-btn"
                        data-id="<?php echo esc_attr($s['id']); ?>">Edit</button>
                <?php
                $del_nonce = wp_create_nonce('lgw_delete_season_' . $s['id']);
                $del_url   = admin_url('admin.php?page=lgw-seasons&lgw_delete_season=' . urlencode($s['id']) . '&_wpnonce=' . $del_nonce);
                ?>
                <a href="<?php echo esc_url($del_url); ?>"
                   class="button button-small"
                   style="color:#c0202a;border-color:#c0202a"
                   onclick="return confirm('Delete the <?php echo esc_js($s['label']); ?> season? This cannot be undone.')">Delete</a>
            </div>
        </div>
        <!-- Inline edit form (hidden by default) -->
        <div id="lgw-edit-archived-<?php echo esc_attr($s['id']); ?>" style="display:none;padding:16px;background:#f9f9f9;border-top:1px solid #eee">
            <form method="post">
                <?php wp_nonce_field('lgw_seasons_nonce'); ?>
                <input type="hidden" name="lgw_backload_id" value="<?php echo esc_attr($s['id']); ?>">
                <input type="hidden" name="lgw_editing_season_id" value="<?php echo esc_attr($s['id']); ?>">
                <p style="font-weight:600;margin-bottom:8px;font-size:13px">Edit: <?php echo esc_html($s['label']); ?></p>
                <table class="form-table" style="margin-bottom:10px">
                    <tr>
                        <th style="width:140px">Label</th>
                        <td><input type="text" name="lgw_backload_label"
                               value="<?php echo esc_attr($s['label']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Season start</th>
                        <td>
                            <input type="date" name="lgw_backload_start"
                                   value="<?php echo esc_attr($s['start'] ?? ''); ?>">
                            <p class="description">Used to filter Player Tracking to this season.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Season end</th>
                        <td><input type="date" name="lgw_backload_end"
                               value="<?php echo esc_attr($s['end'] ?? ''); ?>"></td>
                    </tr>
                </table>
                <p style="font-weight:600;margin-bottom:6px;font-size:13px">Divisions</p>
                <div class="lgw-div-rows lgw-bl-div-rows" data-season="<?php echo esc_attr($s['id']); ?>">
                    <?php
                    $bl_divs = $s['divisions'] ?? array(array('division'=>'','csv_url'=>''));
                    if (empty($bl_divs)) $bl_divs = array(array('division'=>'','csv_url'=>''));
                    foreach ($bl_divs as $d):
                    ?>
                    <div class="lgw-div-row">
                        <input type="text" name="lgw_bl_div_name[]"
                               value="<?php echo esc_attr($d['division']); ?>"
                               placeholder="Division name">
                        <input type="url" name="lgw_bl_div_csv_url[]"
                               value="<?php echo esc_url($d['csv_url']); ?>"
                               placeholder="Google Sheets published CSV URL">
                        <button type="button" class="button-link-delete lgw-remove-div-row">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="button" class="button lgw-add-bl-div-row">+ Add Division</button>
                </p>
                <p>
                    <input type="submit" name="lgw_add_archived_season" class="button button-primary" value="Save Changes">
                    <button type="button" class="button lgw-cancel-edit" data-season="<?php echo esc_attr($s['id']); ?>">Cancel</button>
                </p>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:#888;font-size:13px"><em>No archived seasons yet.</em></p>
    <?php endif; ?>

    <!-- ── Backload Historical Season ── -->
    <h3>Add Historical Season</h3>
    <p style="color:#666;font-size:13px;margin-top:0">Add a previous season (e.g. 2025, 2024…) by entering its year and the published CSV URLs for each division.</p>
    <div class="lgw-season-card" style="border-color:#2a7a2a">
        <form method="post">
            <?php wp_nonce_field('lgw_seasons_nonce'); ?>
            <table class="form-table" style="margin-bottom:12px">
                <tr>
                    <th style="width:160px"><label for="lgw_backload_id">Season year / ID</label></th>
                    <td>
                        <input type="text" id="lgw_backload_id" name="lgw_backload_id"
                               class="small-text" placeholder="e.g. 2025">
                    </td>
                </tr>
                <tr>
                    <th><label for="lgw_backload_label">Season label</label></th>
                    <td>
                        <input type="text" id="lgw_backload_label" name="lgw_backload_label"
                               class="regular-text" placeholder="e.g. 2025 Season">
                    </td>
                </tr>
                <tr>
                    <th><label for="lgw_backload_start">Season start date</label></th>
                    <td>
                        <input type="date" id="lgw_backload_start" name="lgw_backload_start">
                        <p class="description">Used to filter Player Tracking to this season.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgw_backload_end">Season end date</label></th>
                    <td><input type="date" id="lgw_backload_end" name="lgw_backload_end"></td>
                </tr>
            </table>
            <p style="font-weight:600;margin-bottom:8px;font-size:13px">Divisions</p>
            <div class="lgw-div-rows lgw-bl-div-rows" data-season="new">
                <div class="lgw-div-row">
                    <input type="text" name="lgw_bl_div_name[]" placeholder="Division name (e.g. Div 1)">
                    <input type="url" name="lgw_bl_div_csv_url[]" placeholder="Google Sheets published CSV URL">
                    <button type="button" class="button-link-delete lgw-remove-div-row">✕</button>
                </div>
            </div>
            <p>
                <button type="button" class="button lgw-add-bl-div-row" data-target="new">+ Add Division</button>
            </p>
            <p>
                <input type="submit" name="lgw_add_archived_season" class="button button-primary"
                       style="border-color:#2a7a2a;background:#f0faf0;color:#0a3622"
                       value="➕ Add Historical Season">
            </p>
        </form>
    </div>

    <!-- ── Shortcode Reference ── -->
    <h2>Season Switcher — Shortcode Usage</h2>
    <p>Add the <code>seasons</code> attribute to any <code>[lgw_division]</code> shortcode to show a season switcher above the widget.</p>
    <table class="widefat striped" style="max-width:720px">
        <thead><tr><th>Usage</th><th>Effect</th></tr></thead>
        <tbody>
        <tr>
            <td><code>[lgw_division csv="…" seasons="2025,2024"]</code></td>
            <td>Shows a switcher for the current season plus 2025 and 2024</td>
        </tr>
        <tr>
            <td><code>[lgw_division csv="…" seasons="all"]</code></td>
            <td>Automatically includes all archived seasons that have a matching division name</td>
        </tr>
        <tr>
            <td><code>[lgw_division csv="…"]</code></td>
            <td>No switcher — current season only (existing behaviour)</td>
        </tr>
        </tbody>
    </table>
    <p style="font-size:13px;color:#666">The widget matches seasons to the division shortcode by the <strong>division name</strong> stored in each season's divisions list. Make sure division names are consistent across seasons.</p>

    </div><!-- .wrap -->

    <script>
    (function(){
        // ── Remove division row ───────────────────────────────────────────────
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('lgw-remove-div-row')) {
                var row = e.target.closest('.lgw-div-row');
                if (row && row.parentNode.querySelectorAll('.lgw-div-row').length > 1) {
                    row.remove();
                }
            }
        });

        // ── Add division row to active season ─────────────────────────────────
        document.getElementById('lgw-add-div-row').addEventListener('click', function() {
            var container = document.getElementById('lgw-active-div-rows');
            var row = document.createElement('div');
            row.className = 'lgw-div-row';
            row.innerHTML = '<input type="text" name="lgw_div_name[]" placeholder="Division name (e.g. Div 1)">'
                          + '<input type="url" name="lgw_div_csv_url[]" placeholder="Google Sheets published CSV URL">'
                          + '<button type="button" class="button-link-delete lgw-remove-div-row">✕</button>';
            container.appendChild(row);
        });

        // ── Add division row to backload / edit forms ─────────────────────────
        document.querySelectorAll('.lgw-add-bl-div-row').forEach(function(btn) {
            btn.addEventListener('click', function() {
                // Find the closest .lgw-bl-div-rows within the same form/card
                var form = btn.closest('form') || btn.closest('.lgw-season-card');
                var container = form ? form.querySelector('.lgw-bl-div-rows') : null;
                if (!container) return;
                var row = document.createElement('div');
                row.className = 'lgw-div-row';
                row.innerHTML = '<input type="text" name="lgw_bl_div_name[]" placeholder="Division name">'
                              + '<input type="url" name="lgw_bl_div_csv_url[]" placeholder="Google Sheets published CSV URL">'
                              + '<button type="button" class="button-link-delete lgw-remove-div-row">✕</button>';
                container.appendChild(row);
            });
        });

        // ── Edit archived season — inline reveal ──────────────────────────────
        document.querySelectorAll('.lgw-edit-archived-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.getAttribute('data-id');
                var panel = document.getElementById('lgw-edit-archived-' + id);
                if (panel) panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            });
        });

        document.querySelectorAll('.lgw-cancel-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.getAttribute('data-season');
                var panel = document.getElementById('lgw-edit-archived-' + id);
                if (panel) panel.style.display = 'none';
            });
        });

        // ── Backfill players for a season ──────────────────────────────────────
        document.querySelectorAll('.lgw-backfill-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id    = btn.getAttribute('data-id');
                var label = btn.getAttribute('data-label');
                var nonce = btn.getAttribute('data-nonce');
                if (!confirm('Re-run player appearance logging for all scorecards tagged as ' + label + '?\n\nThis is safe to run multiple times — existing records for each scorecard are replaced.')) return;
                btn.disabled = true;
                btn.textContent = '⏳ Backfilling…';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=lgw_backfill_season_players&season_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(nonce)
                })
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data.success) {
                        btn.textContent = '✅ Done (' + data.data.count + ' scorecards)';
                        setTimeout(function(){ btn.textContent = '🔄 Backfill Players'; }, 4000);
                    } else {
                        btn.textContent = '❌ ' + (data.data || 'Error');
                        setTimeout(function(){ btn.textContent = '🔄 Backfill Players'; }, 4000);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = '❌ Request failed';
                    setTimeout(function(){ btn.textContent = '🔄 Backfill Players'; }, 4000);
                });
            });
        });
    })();
    </script>
    <?php
}
