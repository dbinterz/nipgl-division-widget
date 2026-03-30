<?php
/**
 * LGW Player Tracking
 * Records player appearances from confirmed/admin-resolved scorecards.
 * Groups by club, tracks which teams played for, supports season date ranges.
 */

// ── DB table setup ────────────────────────────────────────────────────────────
function lgw_players_table()     { global $wpdb; return $wpdb->prefix . 'lgw_players'; }
function lgw_appearances_table() { global $wpdb; return $wpdb->prefix . 'lgw_appearances'; }

register_activation_hook(LGW_PLUGIN_FILE, 'lgw_create_player_tables');
function lgw_create_player_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS " . lgw_players_table() . " (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        club        VARCHAR(100) NOT NULL,
        name        VARCHAR(150) NOT NULL,
        starred     TINYINT(1)   NOT NULL DEFAULT 0,
        female      TINYINT(1)   NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY club_name (club(100), name(150))
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS " . lgw_appearances_table() . " (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        player_id   INT UNSIGNED NOT NULL,
        team        VARCHAR(150) NOT NULL,
        match_title VARCHAR(255) NOT NULL,
        match_date  VARCHAR(50)  NOT NULL,
        rink        TINYINT      NOT NULL DEFAULT 0,
        scorecard_id INT UNSIGNED NOT NULL DEFAULT 0,
        played_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY player_id (player_id),
        KEY scorecard_id (scorecard_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
}

// Run table creation on every load in case tables are missing (e.g. after plugin update)
add_action('plugins_loaded', 'lgw_maybe_create_player_tables');
function lgw_maybe_create_player_tables() {
    global $wpdb;
    $tbl = lgw_players_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'") !== $tbl) {
        lgw_create_player_tables();
        return;
    }
    // Migrate: add starred + female columns if missing (upgrade from pre-5.11.0)
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $tbl");
    if (!in_array('starred', $cols)) {
        $wpdb->query("ALTER TABLE $tbl ADD COLUMN starred TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('female', $cols)) {
        $wpdb->query("ALTER TABLE $tbl ADD COLUMN female TINYINT(1) NOT NULL DEFAULT 0");
    }
}

// ── Season helpers ────────────────────────────────────────────────────────────
function lgw_get_season() {
    return get_option('lgw_season', array(
        'start' => '',
        'end'   => '',
        'label' => '',
    ));
}

function lgw_season_where() {
    global $wpdb;
    $season = lgw_get_season();
    if (!empty($season['start']) && !empty($season['end'])) {
        // match_date is stored as dd/mm/yyyy — convert via STR_TO_DATE for comparison
        return $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'],
            $season['end']
        );
    }
    return ''; // no filter — show all time
}

// ── Core: log appearances from a scorecard ────────────────────────────────────
function lgw_log_appearances($scorecard_post_id) {
    global $wpdb;
    $sc = get_post_meta($scorecard_post_id, 'lgw_scorecard_data', true);
    if (!$sc || empty($sc['rinks'])) return;

    $home_team  = $sc['home_team'] ?? '';
    $away_team  = $sc['away_team'] ?? '';
    $match_date = $sc['date']      ?? '';
    $match_title = $home_team . ' v ' . $away_team;

    // Resolve clubs from team names using existing prefix-matching
    $home_club = lgw_team_to_club($home_team);
    $away_club = lgw_team_to_club($away_team);

    // Clear any existing appearances for this scorecard (idempotent re-log)
    $wpdb->delete(lgw_appearances_table(), array('scorecard_id' => $scorecard_post_id), array('%d'));

    foreach ($sc['rinks'] as $rink) {
        $rink_num = intval($rink['rink'] ?? 0);

        // Home players
        foreach (($rink['home_players'] ?? array()) as $name) {
            $name = lgw_clean_player_name($name);
            if (!$name) continue;
            $player_id = lgw_get_or_create_player($home_club ?: $home_team, $name);
            $wpdb->insert(lgw_appearances_table(), array(
                'player_id'   => $player_id,
                'team'        => $home_team,
                'match_title' => $match_title,
                'match_date'  => $match_date,
                'rink'        => $rink_num,
                'scorecard_id'=> $scorecard_post_id,
                'played_at'   => current_time('mysql'),
            ), array('%d','%s','%s','%s','%d','%d','%s'));
        }

        // Away players
        foreach (($rink['away_players'] ?? array()) as $name) {
            $name = lgw_clean_player_name($name);
            if (!$name) continue;
            $player_id = lgw_get_or_create_player($away_club ?: $away_team, $name);
            $wpdb->insert(lgw_appearances_table(), array(
                'player_id'   => $player_id,
                'team'        => $away_team,
                'match_title' => $match_title,
                'match_date'  => $match_date,
                'rink'        => $rink_num,
                'scorecard_id'=> $scorecard_post_id,
                'played_at'   => current_time('mysql'),
            ), array('%d','%s','%s','%s','%d','%d','%s'));
        }
    }
}

function lgw_clean_player_name($name) {
    // Strip trailing asterisks (e.g. "SJ Curran*") and extra whitespace
    return trim(rtrim(trim($name), '*'));
}

function lgw_team_to_club($team) {
    // Match team name to a configured club using existing prefix logic
    $clubs = lgw_get_clubs();
    foreach ($clubs as $club) {
        if (lgw_club_matches_team($club['name'], $team)) return $club['name'];
    }
    return ''; // unknown club — caller falls back to team name
}

function lgw_get_or_create_player($club, $name) {
    global $wpdb;
    $tbl = lgw_players_table();
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tbl WHERE club = %s AND name = %s",
        $club, $name
    ));
    if ($existing) return intval($existing);
    $wpdb->insert($tbl, array('club' => $club, 'name' => $name), array('%s','%s'));
    return intval($wpdb->insert_id);
}

// ── Admin menu ────────────────────────────────────────────────────────────────
// ── Admin page ────────────────────────────────────────────────────────────────
function lgw_players_admin_page() {
    global $wpdb;
    $pt  = lgw_players_table();
    $at  = lgw_appearances_table();
    $season = lgw_get_season();
    $nonce  = wp_create_nonce('lgw_players_nonce');
    $season_where = lgw_season_where();

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lgw_players_action'])) {
        check_admin_referer('lgw_players_nonce', 'lgw_players_nonce_field');
        $action = $_POST['lgw_players_action'];

        if ($action === 'save_season') {
            update_option('lgw_season', array(
                'start' => sanitize_text_field($_POST['season_start'] ?? ''),
                'end'   => sanitize_text_field($_POST['season_end']   ?? ''),
                'label' => sanitize_text_field($_POST['season_label'] ?? ''),
            ));
            echo '<div class="notice notice-success"><p>Season settings saved.</p></div>';
            $season = lgw_get_season();
            $season_where = lgw_season_where();
        }

        if ($action === 'merge') {
            $keep_id    = intval($_POST['keep_id']   ?? 0);
            $remove_id  = intval($_POST['remove_id'] ?? 0);
            if ($keep_id && $remove_id && $keep_id !== $remove_id) {
                $wpdb->update($at, array('player_id' => $keep_id), array('player_id' => $remove_id), array('%d'), array('%d'));
                $wpdb->delete($pt, array('id' => $remove_id), array('%d'));
                echo '<div class="notice notice-success"><p>Players merged.</p></div>';
            }
        }

        if ($action === 'add_player') {
            $club = sanitize_text_field($_POST['new_club'] ?? '');
            $name = sanitize_text_field($_POST['new_name'] ?? '');
            if ($club && $name) {
                lgw_get_or_create_player($club, $name);
                echo '<div class="notice notice-success"><p>Player added.</p></div>';
            }
        }

        if ($action === 'delete_player') {
            $pid = intval($_POST['player_id'] ?? 0);
            if ($pid) {
                $wpdb->delete($at, array('player_id' => $pid), array('%d'));
                $wpdb->delete($pt, array('id' => $pid),        array('%d'));
                echo '<div class="notice notice-success"><p>Player deleted.</p></div>';
            }
        }

        if ($action === 'rename_player') {
            $pid  = intval($_POST['player_id']  ?? 0);
            $name = sanitize_text_field($_POST['new_name'] ?? '');
            if ($pid && $name) {
                $wpdb->update($pt, array('name' => $name), array('id' => $pid), array('%s'), array('%d'));
                echo '<div class="notice notice-success"><p>Player renamed.</p></div>';
            }
        }

        if ($action === 'update_flags') {
            $pid     = intval($_POST['player_id'] ?? 0);
            $starred = isset($_POST['starred']) ? 1 : 0;
            $female  = isset($_POST['female'])  ? 1 : 0;
            if ($pid) {
                $wpdb->update($pt,
                    array('starred' => $starred, 'female' => $female),
                    array('id' => $pid),
                    array('%d','%d'), array('%d')
                );
            }
            // Silently return — called via inline form
        }
    }

    // Fetch all players with appearance counts
    $players = $wpdb->get_results("
        SELECT p.id, p.club, p.name, p.starred, p.female,
               COUNT(DISTINCT a.id) as appearances,
               GROUP_CONCAT(DISTINCT a.team ORDER BY a.team SEPARATOR ', ') as teams
        FROM $pt p
        LEFT JOIN $at a ON a.player_id = p.id " .
        ($season_where ? "WHERE 1=1 $season_where " : "") . "
        GROUP BY p.id
        ORDER BY p.club, p.name
    ");

    // Group by club
    $by_club = array();
    foreach ($players as $pl) {
        $by_club[$pl->club][] = $pl;
    }

    // Get clubs list for dropdowns
    $clubs = array_merge(
        array_map(function($c){ return $c['name']; }, lgw_get_clubs()),
        array_keys($by_club)
    );
    $clubs = array_unique($clubs);
    sort($clubs);

    ?>
    <div class="wrap">
    <h1>Player Tracking</h1>

    <style>
    .lgw-pt-tabs{display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #1a2e5a}
    .lgw-pt-tab{padding:8px 18px;cursor:pointer;background:#f0f2f8;border:1px solid #ccc;border-bottom:none;font-weight:600;font-size:13px}
    .lgw-pt-tab.active{background:#1a2e5a;color:#fff;border-color:#1a2e5a}
    .lgw-pt-panel{display:none;padding:16px 0}
    .lgw-pt-panel.active{display:block}
    .lgw-club-section{margin-bottom:24px}
    .lgw-club-section h3{background:#1a2e5a;color:#fff;padding:8px 12px;margin:0 0 0;font-size:14px;border-radius:4px 4px 0 0}
    .lgw-club-section table{margin:0;border-radius:0 0 4px 4px;border-top:none}
    .lgw-player-actions{display:flex;gap:6px;align-items:center}
    .lgw-merge-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px}
    .lgw-merge-form select{min-width:220px}
    .lgw-season-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px;max-width:500px}
    .lgw-season-form label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
    .lgw-season-form input[type=date],.lgw-season-form input[type=text]{width:100%;margin-bottom:12px}
    .lgw-add-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px;max-width:500px}
    .lgw-add-form label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
    .lgw-add-form input,.lgw-add-form select{width:100%;margin-bottom:12px}
    .lgw-appearances-zero{color:#999}
    .lgw-highlight{background:#fff3cd}
    </style>

    <div class="lgw-pt-tabs">
        <div class="lgw-pt-tab active" onclick="lgwTab('players')">Players</div>
        <div class="lgw-pt-tab" onclick="lgwTab('merge')">Merge Duplicates</div>
        <div class="lgw-pt-tab" onclick="lgwTab('add')">Add Player</div>
        <div class="lgw-pt-tab" onclick="lgwTab('season')">Season Settings</div>
    </div>

    <script>
    function lgwTab(tab) {
        document.querySelectorAll('.lgw-pt-tab').forEach(function(t,i){
            t.classList.toggle('active', ['players','merge','add','season'][i]===tab);
        });
        document.querySelectorAll('.lgw-pt-panel').forEach(function(p){
            p.classList.toggle('active', p.id==='lgw-panel-'+tab);
        });
    }
    function lgwConfirmDelete(name) {
        return confirm('Delete player "' + name + '" and all their appearance records? This cannot be undone.');
    }
    function lgwStartRename(id, currentName) {
        var newName = prompt('Rename player:', currentName);
        if (newName && newName.trim() && newName.trim() !== currentName) {
            document.getElementById('rename-id-'+id).value = id;
            document.getElementById('rename-name-'+id).value = newName.trim();
            document.getElementById('rename-form-'+id).submit();
        }
    }
    // Populate merge dropdowns when club changes
    function lgwMergeClub(val) {
        var selA = document.getElementById('merge-keep');
        var selB = document.getElementById('merge-remove');
        [selA, selB].forEach(function(sel) {
            Array.from(sel.options).forEach(function(opt) {
                opt.style.display = (!val || opt.dataset.club === val || opt.value === '') ? '' : 'none';
            });
            sel.value = '';
        });
    }
    </script>

    <?php // ── Tab 1: Players ── ?>
    <div class="lgw-pt-panel active" id="lgw-panel-players">
        <?php if (!empty($season['label'])): ?>
            <p><strong>Season:</strong> <?php echo esc_html($season['label']); ?>
            <?php if ($season['start'] && $season['end']): ?>
                (<?php echo esc_html($season['start']); ?> – <?php echo esc_html($season['end']); ?>)
            <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (empty($by_club)): ?>
            <p>No players recorded yet. Players are logged automatically when scorecards are confirmed.</p>
        <?php else: ?>
            <?php foreach ($by_club as $club => $club_players): ?>
            <div class="lgw-club-section">
                <h3><?php echo esc_html($club); ?> — <?php echo count($club_players); ?> player<?php echo count($club_players)!==1?'s':''; ?></h3>
                <table class="widefat striped">
                <thead><tr>
                    <th>Name</th>
                    <th>Teams played for</th>
                    <th style="text-align:center">Appearances<?php echo $season_where ? ' (this season)' : ''; ?></th>
                    <th style="text-align:center" title="Starred player">⭐</th>
                    <th style="text-align:center" title="Female player">♀</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($club_players as $pl): ?>
                <tr<?php echo $pl->appearances == 0 ? ' class="lgw-appearances-zero"' : ''; ?>>
                    <td><strong><?php echo esc_html($pl->name); ?></strong></td>
                    <td><?php echo esc_html($pl->teams ?: '—'); ?></td>
                    <td style="text-align:center"><?php echo intval($pl->appearances); ?></td>
                    <td style="text-align:center">
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
                            <input type="hidden" name="lgw_players_action" value="update_flags">
                            <input type="hidden" name="player_id" value="<?php echo $pl->id; ?>">
                            <input type="hidden" name="female" value="<?php echo $pl->female ? '1' : '0'; ?>">
                            <input type="checkbox" name="starred" value="1"
                                <?php checked($pl->starred, 1); ?>
                                onchange="this.form.submit()"
                                title="Mark as starred player">
                        </form>
                    </td>
                    <td style="text-align:center">
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
                            <input type="hidden" name="lgw_players_action" value="update_flags">
                            <input type="hidden" name="player_id" value="<?php echo $pl->id; ?>">
                            <input type="hidden" name="starred" value="<?php echo $pl->starred ? '1' : '0'; ?>">
                            <input type="checkbox" name="female" value="1"
                                <?php checked($pl->female, 1); ?>
                                onchange="this.form.submit()"
                                title="Mark as female player">
                        </form>
                    </td>
                    <td>
                        <div class="lgw-player-actions">
                            <button class="button button-small" onclick="lgwStartRename(<?php echo $pl->id; ?>, <?php echo json_encode($pl->name); ?>)">Rename</button>
                            <form method="post" style="display:inline" onsubmit="return lgwConfirmDelete(<?php echo json_encode($pl->name); ?>)">
                                <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
                                <input type="hidden" name="lgw_players_action" value="delete_player">
                                <input type="hidden" name="player_id" value="<?php echo $pl->id; ?>">
                                <button type="submit" class="button button-small button-link-delete">Delete</button>
                            </form>
                            <?php // Hidden rename forms ?>
                            <form method="post" id="rename-form-<?php echo $pl->id; ?>" style="display:none">
                                <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
                                <input type="hidden" name="lgw_players_action" value="rename_player">
                                <input type="hidden" name="player_id" id="rename-id-<?php echo $pl->id; ?>" value="">
                                <input type="hidden" name="new_name"  id="rename-name-<?php echo $pl->id; ?>" value="">
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
            </div>
            <?php endforeach; ?>

            <p style="margin-top:16px">
                <a href="<?php echo admin_url('admin-post.php?action=lgw_export_players&_wpnonce='.wp_create_nonce('lgw_export_players')); ?>" class="button button-primary">⬇ Export to Excel</a>
            </p>
        <?php endif; ?>
    </div>

    <?php // ── Tab 2: Merge ── ?>
    <div class="lgw-pt-panel" id="lgw-panel-merge">
        <h2>Merge Duplicate Players</h2>
        <p>Use this when the same person appears under two different spellings (e.g. "J Smith" and "John Smith"). All appearances will be moved to the player you keep, and the other record deleted.</p>

        <?php if (count($players) < 2): ?>
            <p>Not enough players to merge yet.</p>
        <?php else: ?>
        <form method="post" class="lgw-merge-form" onsubmit="return confirm('Merge these two players? The removed player record cannot be recovered.')">
            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
            <input type="hidden" name="lgw_players_action" value="merge">

            <label>Filter by club:</label>
            <select onchange="lgwMergeClub(this.value)" style="margin-bottom:12px;min-width:200px">
                <option value="">— All clubs —</option>
                <?php foreach ($clubs as $c): ?>
                <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select><br>

            <table style="width:100%;max-width:600px">
            <tr>
                <td style="padding-right:16px">
                    <label><strong>Keep</strong> (canonical name)</label>
                    <select name="keep_id" id="merge-keep" required style="width:100%;margin-top:4px">
                        <option value="">— Select player —</option>
                        <?php foreach ($players as $pl): ?>
                        <option value="<?php echo $pl->id; ?>" data-club="<?php echo esc_attr($pl->club); ?>">
                            <?php echo esc_html($pl->club . ' — ' . $pl->name . ' (' . $pl->appearances . ' apps)'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <label><strong>Remove</strong> (duplicate to delete)</label>
                    <select name="remove_id" id="merge-remove" required style="width:100%;margin-top:4px">
                        <option value="">— Select player —</option>
                        <?php foreach ($players as $pl): ?>
                        <option value="<?php echo $pl->id; ?>" data-club="<?php echo esc_attr($pl->club); ?>">
                            <?php echo esc_html($pl->club . ' — ' . $pl->name . ' (' . $pl->appearances . ' apps)'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            </table>
            <br>
            <button type="submit" class="button button-primary">Merge Players</button>
        </form>
        <?php endif; ?>
    </div>

    <?php // ── Tab 3: Add player ── ?>
    <div class="lgw-pt-panel" id="lgw-panel-add">
        <h2>Add Player Manually</h2>
        <p>Players are added automatically from confirmed scorecards. Use this to add someone who hasn't appeared on a scorecard yet.</p>
        <form method="post" class="lgw-add-form">
            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
            <input type="hidden" name="lgw_players_action" value="add_player">
            <label>Club</label>
            <select name="new_club" required>
                <option value="">— Select club —</option>
                <?php foreach ($clubs as $c): ?>
                <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Player name</label>
            <input type="text" name="new_name" required placeholder="e.g. J. Smith">
            <button type="submit" class="button button-primary">Add Player</button>
        </form>
    </div>

    <?php // ── Tab 4: Season settings ── ?>
    <div class="lgw-pt-panel" id="lgw-panel-season">
        <h2>Season Settings</h2>
        <p>Set the current season date range. Appearance counts on the Players tab will only count matches within this range. Leave blank to show all-time totals.</p>
        <form method="post" class="lgw-season-form">
            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
            <input type="hidden" name="lgw_players_action" value="save_season">
            <label>Season label (e.g. "2025/26")</label>
            <input type="text" name="season_label" value="<?php echo esc_attr($season['label'] ?? ''); ?>" placeholder="2025/26">
            <label>Season start date</label>
            <input type="date" name="season_start" value="<?php echo esc_attr($season['start'] ?? ''); ?>">
            <label>Season end date</label>
            <input type="date" name="season_end" value="<?php echo esc_attr($season['end'] ?? ''); ?>">
            <button type="submit" class="button button-primary">Save Season</button>
        </form>
    </div>

    </div><!-- .wrap -->
    <?php
}

// ── Export to Excel ───────────────────────────────────────────────────────────
add_action('admin_post_lgw_export_players', 'lgw_export_players_xlsx');
function lgw_export_players_xlsx() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_export_players');

    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();
    $season_where = lgw_season_where();
    $season = lgw_get_season();
    $label  = !empty($season['label']) ? ' - ' . $season['label'] : '';
    $filename = 'lgw-players' . str_replace('/', '-', $label) . '.xls';

    // ── Fetch all players ─────────────────────────────────────────────────────
    $all_players = $wpdb->get_results(
        "SELECT id, club, name, starred, female FROM $pt ORDER BY club, name"
    );

    // ── Fetch all appearances within season ───────────────────────────────────
    $app_rows = $wpdb->get_results(
        "SELECT a.player_id, a.team, a.match_date, a.match_title, a.scorecard_id
         FROM $at a
         " . ($season_where ? "WHERE 1=1 $season_where" : "") . "
         ORDER BY a.match_date, a.match_title"
    );

    // ── Build per-player appearance index ─────────────────────────────────────
    // $player_apps[player_id][match_key] = team_label (A/B/MW etc.)
    $player_apps = array();
    foreach ($all_players as $pl) {
        $player_apps[$pl->id] = array();
    }

    // ── Collect match column metadata ─────────────────────────────────────────
    // $match_meta[match_key] = [date, home_team, away_team, comp, venue]
    $match_meta  = array();
    $match_order = array(); // ordered list of match keys

    // We need scorecard data for comp/venue — fetch all relevant scorecards
    $sc_ids = array_unique(array_column($app_rows, 'scorecard_id'));
    $sc_data = array();
    foreach ($sc_ids as $sid) {
        if (!$sid) continue;
        $d = get_post_meta($sid, 'lgw_scorecard_data', true);
        if ($d) $sc_data[$sid] = $d;
    }

    foreach ($app_rows as $row) {
        $match_key = $row->match_date . '||' . $row->match_title;
        if (!isset($match_meta[$match_key])) {
            // Parse home/away from title "Home v Away"
            $parts = explode(' v ', $row->match_title, 2);
            $home  = trim($parts[0] ?? $row->match_title);
            $away  = trim($parts[1] ?? '');
            $sc    = $sc_data[$row->scorecard_id] ?? array();
            $match_meta[$match_key] = array(
                'date'  => $row->match_date,
                'home'  => $home,
                'away'  => $away,
                'comp'  => $sc['competition'] ?? '',
                'venue' => $sc['venue'] ?? '',
            );
            $match_order[] = $match_key;
        }

        // Determine team label for this player in this match
        // Compare team name suffix to get A/B/MW etc.
        $team_label = lgw_team_suffix($row->team);
        $player_apps[$row->player_id][$match_key] = $team_label;
    }
    $match_order = array_unique($match_order);

    // ── Group players by club ─────────────────────────────────────────────────
    $by_club = array();
    foreach ($all_players as $pl) {
        $by_club[$pl->club][] = $pl;
    }

    // ── Build sheet names ─────────────────────────────────────────────────────
    $sheet_names = array('Summary');
    foreach (array_keys($by_club) as $club) {
        $sheet_names[] = lgw_safe_sheet_name($club);
    }
    $sheet_names = array_values(array_unique($sheet_names));

    // ── Output headers ────────────────────────────────────────────────────────
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>';
    foreach ($sheet_names as $sn) {
        echo '<x:ExcelWorksheet><x:Name>' . esc_html($sn) . '</x:Name>'
           . '<x:WorksheetSource HRef="#' . esc_attr($sn) . '"/></x:ExcelWorksheet>';
    }
    echo '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>
        body{font-family:Arial;font-size:10pt}
        td,th{font-family:Arial;font-size:10pt;border:1px solid #ccc;padding:3px 6px;white-space:nowrap}
        .hdr1{background:#1a2e5a;color:#fff;font-weight:bold;text-align:center}
        .hdr2{background:#2e4a8a;color:#fff;font-size:9pt;text-align:center}
        .hdr3{background:#d0d8ee;font-weight:bold;font-size:9pt;text-align:center}
        .total-hdr{background:#c8d4f0;font-weight:bold;text-align:center}
        .flag-hdr{background:#e8eaf6;font-weight:bold;text-align:center;font-size:9pt}
        .player-name{font-weight:600;text-align:left}
        .app-cell{text-align:center;font-weight:700;font-size:10pt}
        .app-a{background:#dff0d8;color:#155724}
        .app-b{background:#d0e8ff;color:#003d7a}
        .app-mw{background:#fff3cd;color:#856404}
        .app-other{background:#f3e5f5;color:#4a235a}
        .total-cell{text-align:center;font-weight:700;background:#eef2ff}
        .starred{background:#fffde7}
        .summary-hdr{background:#1a2e5a;color:#fff;font-weight:bold}
        .summary-club{background:#f0f2f8;font-weight:bold}
        .even{background:#f9f9f9}
    </style></head><body>';

    // ════════════════════════════════════════════════════════════════════════
    // SUMMARY SHEET
    // ════════════════════════════════════════════════════════════════════════
    $total_players = count($all_players);
    $total_ladies  = array_sum(array_column($all_players, 'female'));
    $total_clubs   = count($by_club);

    echo '<table id="Summary">';
    echo '<tr><td class="hdr1" colspan="6">LGW Player Tracking' . esc_html($label) . '</td></tr>';
    echo '<tr><td class="hdr3" colspan="6">&nbsp;</td></tr>';
    echo '<tr>'
       . '<td class="summary-hdr"></td>'
       . '<td class="summary-hdr">No. of Teams</td>'
       . '<td class="summary-hdr">Players Listed</td>'
       . '<td class="summary-hdr">Ladies</td>'
       . '<td class="summary-hdr">% of Total Players</td>'
       . '<td class="summary-hdr">% Ladies</td>'
       . '</tr>';

    // TOTAL row
    echo '<tr>'
       . '<td class="player-name"><strong>TOTAL</strong></td>'
       . '<td class="total-cell">' . $total_clubs . '</td>'
       . '<td class="total-cell">' . $total_players . '</td>'
       . '<td class="total-cell">' . $total_ladies . '</td>'
       . '<td class="total-cell">100%</td>'
       . '<td class="total-cell">' . ($total_players > 0 ? round($total_ladies / $total_players * 100, 2) . '%' : '0%') . '</td>'
       . '</tr>';
    echo '<tr><td colspan="6">&nbsp;</td></tr>';

    $row_i = 0;
    foreach ($by_club as $club => $players) {
        $n_players = count($players);
        $n_ladies  = count(array_filter($players, function($p){ return $p->female; }));
        // Count distinct teams (suffix labels) this club has played
        $teams_set = array();
        foreach ($players as $pl) {
            foreach (($player_apps[$pl->id] ?? array()) as $mk => $lbl) {
                $teams_set[$lbl] = true;
            }
        }
        $n_teams = count($teams_set) ?: 1;

        $pct_total  = $total_players > 0 ? round($n_players / $total_players * 100, 2) . '%' : '0%';
        $pct_ladies = $n_players > 0 ? round($n_ladies / $n_players * 100, 2) . '%' : '0%';
        $row_cls    = ($row_i++ % 2 === 0) ? '' : ' class="even"';

        echo '<tr' . $row_cls . '>'
           . '<td class="summary-club">' . esc_html($club) . '</td>'
           . '<td class="total-cell">' . $n_teams . '</td>'
           . '<td class="total-cell">' . $n_players . '</td>'
           . '<td class="total-cell">' . $n_ladies . '</td>'
           . '<td class="total-cell">' . $pct_total . '</td>'
           . '<td class="total-cell">' . $pct_ladies . '</td>'
           . '</tr>';
    }
    echo '</table>';

    // ════════════════════════════════════════════════════════════════════════
    // PER-CLUB MATRIX SHEETS
    // ════════════════════════════════════════════════════════════════════════
    foreach ($by_club as $club => $players) {
        $sn = lgw_safe_sheet_name($club);

        // Get match keys relevant to this club
        $club_match_keys = array();
        foreach ($players as $pl) {
            foreach (array_keys($player_apps[$pl->id] ?? array()) as $mk) {
                $club_match_keys[$mk] = true;
            }
        }
        // Keep original chronological order, filtered to this club
        $club_matches = array_values(array_filter($match_order, function($mk) use ($club_match_keys) {
            return isset($club_match_keys[$mk]);
        }));

        $n_matches  = count($club_matches);
        $fixed_cols = 7; // Name, T, A, B, MW, Starred, Female
        $total_cols = $fixed_cols + $n_matches;

        echo '<table id="' . esc_attr($sn) . '">';

        // Row 1: Club name header spanning all columns
        echo '<tr><td class="hdr1" colspan="' . $total_cols . '">' . esc_html($club) . esc_html($label) . '</td></tr>';

        // Row 2: match dates
        echo '<tr>'
           . '<td class="hdr2">Player</td>'
           . '<td class="total-hdr">T</td>'
           . '<td class="total-hdr">A</td>'
           . '<td class="total-hdr">B</td>'
           . '<td class="total-hdr">MW</td>'
           . '<td class="flag-hdr">⭐</td>'
           . '<td class="flag-hdr">♀</td>';
        foreach ($club_matches as $mk) {
            echo '<td class="hdr2">' . esc_html($match_meta[$mk]['date']) . '</td>';
        }
        echo '</tr>';

        // Row 3: team playing (home/away label for this club)
        echo '<tr>'
           . '<td class="hdr3">Team</td>'
           . '<td class="hdr3" colspan="4"></td>'
           . '<td class="hdr3"></td>'
           . '<td class="hdr3"></td>';
        foreach ($club_matches as $mk) {
            $m = $match_meta[$mk];
            $club_team = lgw_club_team_in_match($club, $m['home'], $m['away']);
            $label_t   = lgw_team_suffix($club_team);
            echo '<td class="hdr3">' . esc_html($label_t) . '</td>';
        }
        echo '</tr>';

        // Row 4: opposition
        echo '<tr>'
           . '<td class="hdr3">Opposition</td>'
           . '<td class="hdr3" colspan="4"></td>'
           . '<td class="hdr3"></td>'
           . '<td class="hdr3"></td>';
        foreach ($club_matches as $mk) {
            $m   = $match_meta[$mk];
            $opp = lgw_club_team_in_match($club, $m['home'], $m['away']) === $m['home']
                 ? $m['away'] : $m['home'];
            echo '<td class="hdr3">' . esc_html($opp) . '</td>';
        }
        echo '</tr>';

        // Row 5: venue
        echo '<tr>'
           . '<td class="hdr3">Venue</td>'
           . '<td class="hdr3" colspan="4"></td>'
           . '<td class="hdr3"></td>'
           . '<td class="hdr3"></td>';
        foreach ($club_matches as $mk) {
            $m     = $match_meta[$mk];
            $is_home = lgw_club_matches_team($club, $m['home']);
            $venue = $is_home ? 'Home' : 'Away';
            echo '<td class="hdr3">' . esc_html($venue) . '</td>';
        }
        echo '</tr>';

        // Row 6: competition
        echo '<tr>'
           . '<td class="hdr3">Comp</td>'
           . '<td class="hdr3" colspan="4"></td>'
           . '<td class="hdr3"></td>'
           . '<td class="hdr3"></td>';
        foreach ($club_matches as $mk) {
            echo '<td class="hdr3">' . esc_html($match_meta[$mk]['comp'] ?? '') . '</td>';
        }
        echo '</tr>';

        // Player rows
        foreach ($players as $pl) {
            $apps     = $player_apps[$pl->id] ?? array();
            $t_total  = count($apps);
            $t_a      = count(array_filter($apps, function($v){ return strtoupper($v)==='A'; }));
            $t_b      = count(array_filter($apps, function($v){ return strtoupper($v)==='B'; }));
            $t_mw     = count(array_filter($apps, function($v){ return strtoupper($v)==='MW'; }));
            $row_cls  = $pl->starred ? ' class="starred"' : '';

            echo '<tr' . $row_cls . '>'
               . '<td class="player-name">' . esc_html($pl->name) . '</td>'
               . '<td class="total-cell">' . ($t_total ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_a  ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_b  ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_mw ?: '') . '</td>'
               . '<td style="text-align:center">' . ($pl->starred ? 'X' : '') . '</td>'
               . '<td style="text-align:center">' . ($pl->female  ? 'X' : '') . '</td>';

            foreach ($club_matches as $mk) {
                $val = $apps[$mk] ?? '';
                $cls = '';
                switch (strtoupper($val)) {
                    case 'A':  $cls = 'app-a';     break;
                    case 'B':  $cls = 'app-b';     break;
                    case 'MW': $cls = 'app-mw';    break;
                    default:   $cls = $val ? 'app-other' : ''; break;
                }
                echo '<td class="app-cell ' . $cls . '">' . esc_html($val) . '</td>';
            }
            echo '</tr>';
        }

        echo '</table>';
    }

    echo '</body></html>';
    exit;
}

// ── Helper: extract team suffix label (A, B, MW, C etc.) ─────────────────────
function lgw_team_suffix($team_name) {
    $team_name = trim($team_name);
    // Match trailing label: "Salisbury A" -> "A", "Salisbury MW" -> "MW", "Salisbury" -> "A"
    if (preg_match('/\s+([A-Z]{1,3})$/', $team_name, $m)) {
        return $m[1];
    }
    return 'A'; // default — single-team clubs
}

// ── Helper: which team in this match belongs to this club ────────────────────
function lgw_club_team_in_match($club, $home, $away) {
    if (lgw_club_matches_team($club, $home)) return $home;
    if (lgw_club_matches_team($club, $away)) return $away;
    return $home; // fallback
}

// ── Helper: safe Excel sheet name (max 31 chars, no special chars) ────────────
function lgw_safe_sheet_name($name) {
    return substr(preg_replace('/[^A-Za-z0-9 ]/', '', $name), 0, 31);
}
