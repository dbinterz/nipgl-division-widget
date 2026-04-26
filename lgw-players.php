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
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        player_id    INT UNSIGNED NOT NULL,
        team         VARCHAR(150) NOT NULL,
        match_title  VARCHAR(255) NOT NULL,
        match_date   VARCHAR(50)  NOT NULL,
        rink         TINYINT      NOT NULL DEFAULT 0,
        scorecard_id INT UNSIGNED NOT NULL DEFAULT 0,
        played_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        shots_for    SMALLINT     NULL DEFAULT NULL,
        shots_against SMALLINT    NULL DEFAULT NULL,
        result       CHAR(1)      NULL DEFAULT NULL COMMENT 'W, D, or L',
        game_type    VARCHAR(20)  NOT NULL DEFAULT 'league' COMMENT 'league or cup',
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
    // Migrate appearances table: add stats columns if missing
    $at    = lgw_appearances_table();
    $acols = $wpdb->get_col("SHOW COLUMNS FROM $at");
    if (!in_array('shots_for', $acols)) {
        $wpdb->query("ALTER TABLE $at ADD COLUMN shots_for SMALLINT NULL DEFAULT NULL");
    }
    if (!in_array('shots_against', $acols)) {
        $wpdb->query("ALTER TABLE $at ADD COLUMN shots_against SMALLINT NULL DEFAULT NULL");
    }
    if (!in_array('result', $acols)) {
        $wpdb->query("ALTER TABLE $at ADD COLUMN result CHAR(1) NULL DEFAULT NULL COMMENT 'W D L'");
    }
    if (!in_array('game_type', $acols)) {
        $wpdb->query("ALTER TABLE $at ADD COLUMN game_type VARCHAR(20) NOT NULL DEFAULT 'league'");
        // Back-fill game_type for existing rows from scorecard context meta
        $wpdb->query("
            UPDATE {$at} a
            JOIN {$wpdb->postmeta} pm ON pm.post_id = a.scorecard_id AND pm.meta_key = 'lgw_sc_context'
            SET a.game_type = pm.meta_value
            WHERE a.scorecard_id > 0
        ");
    }
}

// ── Season helpers ────────────────────────────────────────────────────────────
/**
 * Returns the current season date range and label.
 * Reads from the active season in lgw_seasons (managed via Seasons admin).
 * Falls back to the legacy lgw_season option for backwards compatibility.
 */
function lgw_get_season() {
    $active = lgw_get_active_season();
    if ($active) {
        return array(
            'start' => $active['start'] ?? '',
            'end'   => $active['end']   ?? '',
            'label' => $active['label'] ?? '',
        );
    }
    // Legacy fallback — pre-seasons-integration installs
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

    // Determine game type from scorecard context meta
    $game_type = get_post_meta($scorecard_post_id, 'lgw_sc_context', true) ?: 'league';

    // Resolve clubs from team names using existing prefix-matching
    $home_club = lgw_team_to_club($home_team);
    $away_club = lgw_team_to_club($away_team);

    // Clear any existing appearances for this scorecard (idempotent re-log)
    $wpdb->delete(lgw_appearances_table(), array('scorecard_id' => $scorecard_post_id), array('%d'));

    // Guard against legacy scorecards where empty rink scores were stored as 0 by floatval().
    // A scorecard has real scores if: at least one rink has a non-zero score, OR the match totals
    // are non-zero. If everything is 0/null we treat scores as absent.
    $has_real_scores = false;
    $home_total = floatval($sc['home_total'] ?? 0);
    $away_total = floatval($sc['away_total'] ?? 0);
    if ($home_total > 0 || $away_total > 0) {
        $has_real_scores = true;
    } else {
        foreach ($sc['rinks'] as $rk) {
            if (floatval($rk['home_score'] ?? 0) > 0 || floatval($rk['away_score'] ?? 0) > 0) {
                $has_real_scores = true;
                break;
            }
        }
    }

    foreach ($sc['rinks'] as $rink) {
        $rink_num    = intval($rink['rink'] ?? 0);
        // Only store scores/result if this scorecard has real score data
        if ($has_real_scores && isset($rink['home_score']) && is_numeric($rink['home_score'])
                             && isset($rink['away_score']) && is_numeric($rink['away_score'])) {
            $home_shots = intval($rink['home_score']);
            $away_shots = intval($rink['away_score']);
        } else {
            $home_shots = null;
            $away_shots = null;
        }

        // Determine per-rink result
        $home_result = null;
        $away_result = null;
        if ($home_shots !== null && $away_shots !== null) {
            if ($home_shots > $away_shots)      { $home_result = 'W'; $away_result = 'L'; }
            elseif ($home_shots < $away_shots)  { $home_result = 'L'; $away_result = 'W'; }
            else                                { $home_result = 'D'; $away_result = 'D'; }
        }

        // Home players
        foreach (($rink['home_players'] ?? array()) as $raw_name) {
            $is_female = (strpos($raw_name, '*') !== false);
            $name = lgw_clean_player_name($raw_name);
            if (!$name) continue;
            $player_id = lgw_get_or_create_player($home_club ?: $home_team, $name);
            if ($is_female) lgw_ensure_female_flag($player_id);
            $wpdb->insert(lgw_appearances_table(), array(
                'player_id'    => $player_id,
                'team'         => $home_team,
                'match_title'  => $match_title,
                'match_date'   => $match_date,
                'rink'         => $rink_num,
                'scorecard_id' => $scorecard_post_id,
                'played_at'    => current_time('mysql'),
                'shots_for'    => $home_shots,
                'shots_against'=> $away_shots,
                'result'       => $home_result,
                'game_type'    => $game_type,
            ), array('%d','%s','%s','%s','%d','%d','%s','%d','%d','%s','%s'));
        }

        // Away players
        foreach (($rink['away_players'] ?? array()) as $raw_name) {
            $is_female = (strpos($raw_name, '*') !== false);
            $name = lgw_clean_player_name($raw_name);
            if (!$name) continue;
            $player_id = lgw_get_or_create_player($away_club ?: $away_team, $name);
            if ($is_female) lgw_ensure_female_flag($player_id);
            $wpdb->insert(lgw_appearances_table(), array(
                'player_id'    => $player_id,
                'team'         => $away_team,
                'match_title'  => $match_title,
                'match_date'   => $match_date,
                'rink'         => $rink_num,
                'scorecard_id' => $scorecard_post_id,
                'played_at'    => current_time('mysql'),
                'shots_for'    => $away_shots,
                'shots_against'=> $home_shots,
                'result'       => $away_result,
                'game_type'    => $game_type,
            ), array('%d','%s','%s','%s','%d','%d','%s','%d','%d','%s','%s'));
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

/**
 * Normalise a player name for consistent storage.
 * Strips trailing dots from initials so "D. Bintley" and "D Bintley" are treated as the same person.
 * Rule: a dot is removed only when it immediately follows a single letter (the initial), optionally
 * preceded/followed by a space — so "St. Helens" (multi-char prefix) is left alone.
 * Examples: "D. Bintley" → "D Bintley", "J.P. Smith" → "JP Smith", "D Bintley" → "D Bintley" (no-op)
 */
function lgw_normalise_player_name($name) {
    // Remove dots that follow a single capital/lowercase letter at a word boundary
    // e.g. "D." → "D", "J.P." → "JP"
    $name = preg_replace('/\b([A-Za-z])\./', '$1', trim($name));
    // Collapse any double spaces that may result
    $name = preg_replace('/ {2,}/', ' ', $name);
    return trim($name);
}

function lgw_get_or_create_player($club, $name) {
    global $wpdb;
    $name = lgw_normalise_player_name($name);
    $tbl = lgw_players_table();
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tbl WHERE club = %s AND name = %s",
        $club, $name
    ));
    if ($existing) return intval($existing);
    $wpdb->insert($tbl, array('club' => $club, 'name' => $name), array('%s','%s'));
    return intval($wpdb->insert_id);
}

// ── Ensure female flag is set (only ever upgrades false→true, never resets) ──
function lgw_ensure_female_flag($player_id) {
    global $wpdb;
    $wpdb->update(
        lgw_players_table(),
        array('female' => 1),
        array('id' => $player_id, 'female' => 0),
        array('%d'), array('%d','%d')
    );
}

// ── Clean up appearances when a scorecard is deleted or trashed ───────────────
add_action('before_delete_post', 'lgw_on_scorecard_deleted');
add_action('wp_trash_post',      'lgw_on_scorecard_deleted');
function lgw_on_scorecard_deleted($post_id) {
    if (get_post_type($post_id) !== 'lgw_scorecard') return;
    global $wpdb;
    $wpdb->delete(lgw_appearances_table(), array('scorecard_id' => $post_id), array('%d'));
    lgw_prune_orphaned_players();
}
function lgw_prune_orphaned_players() {
    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();
    $wpdb->query(
        "DELETE p FROM $pt p
         LEFT JOIN $at a ON a.player_id = p.id
         WHERE a.id IS NULL AND p.starred = 0"
    );
}

/**
 * Find all player pairs within the same club where one name is a dotted-initial
 * variant of the other (e.g. "D. Bintley" vs "D Bintley").
 * Returns array of ['keep_id', 'keep_name', 'remove_id', 'remove_name', 'club', 'appearances_moved']
 * Keep rule: prefer the record with more appearances; on a tie, keep the one without dots (normalised form).
 */
function lgw_find_dotted_initial_duplicates() {
    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();

    $players = $wpdb->get_results(
        "SELECT p.id, p.club, p.name,
                COUNT(a.id) AS appearances
         FROM $pt p
         LEFT JOIN $at a ON a.player_id = p.id
         GROUP BY p.id
         ORDER BY p.club, p.name"
    );

    // Build a lookup: club -> normalised_name -> [player rows]
    $by_club_norm = array();
    foreach ($players as $pl) {
        $norm = lgw_normalise_player_name($pl->name);
        $by_club_norm[$pl->club][$norm][] = $pl;
    }

    $pairs = array();
    foreach ($by_club_norm as $club => $norm_groups) {
        foreach ($norm_groups as $norm => $group) {
            if (count($group) < 2) continue;
            // Sort: most appearances first; on tie, normalised (no-dot) name first
            usort($group, function($a, $b) use ($norm) {
                if ($b->appearances !== $a->appearances) return $b->appearances - $a->appearances;
                // Prefer already-normalised name (no dots) as canonical
                $a_is_norm = (lgw_normalise_player_name($a->name) === $a->name) ? 0 : 1;
                $b_is_norm = (lgw_normalise_player_name($b->name) === $b->name) ? 0 : 1;
                return $a_is_norm - $b_is_norm;
            });
            $keep = $group[0];
            // All others in the group are duplicates to remove
            for ($i = 1; $i < count($group); $i++) {
                $remove = $group[$i];
                $pairs[] = array(
                    'keep_id'         => intval($keep->id),
                    'keep_name'       => $keep->name,
                    'remove_id'       => intval($remove->id),
                    'remove_name'     => $remove->name,
                    'club'            => $club,
                    'appearances_moved' => intval($remove->appearances),
                );
            }
        }
    }
    return $pairs;
}

// ── AJAX: Get player game history ────────────────────────────────────────────
add_action('wp_ajax_lgw_get_player_history', 'lgw_ajax_get_player_history');
function lgw_ajax_get_player_history() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('lgw_players_nonce', 'nonce');

    $player_id = intval($_POST['player_id'] ?? 0);
    if (!$player_id) wp_send_json_error('Missing player ID');

    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();

    $player = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id = %d", $player_id));
    if (!$player) wp_send_json_error('Player not found');

    $appearances = $wpdb->get_results($wpdb->prepare(
        "SELECT a.id, a.team, a.match_title, a.match_date, a.rink, a.scorecard_id, a.played_at,
                a.shots_for, a.shots_against, a.result, a.game_type
         FROM $at a
         WHERE a.player_id = %d
         ORDER BY STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') DESC, a.played_at DESC",
        $player_id
    ));

    // Enrich with scorecard status/division where available
    $enriched = array();
    foreach ($appearances as $app) {
        $sc_data   = $app->scorecard_id ? get_post_meta($app->scorecard_id, 'lgw_scorecard_data', true) : null;
        $sc_status = $app->scorecard_id ? get_post_meta($app->scorecard_id, 'lgw_sc_status', true) : '';
        $enriched[] = array(
            'match_title'   => $app->match_title,
            'match_date'    => $app->match_date,
            'team'          => $app->team,
            'rink'          => $app->rink,
            'division'      => $sc_data['division'] ?? '',
            'home_score'    => $sc_data['home_total'] ?? '',
            'away_score'    => $sc_data['away_total'] ?? '',
            'shots_for'     => $app->shots_for,
            'shots_against' => $app->shots_against,
            'result'        => $app->result,
            'game_type'     => $app->game_type ?: 'league',
            'status'        => $sc_status,
            'scorecard_id'  => $app->scorecard_id,
        );
    }

    wp_send_json_success(array(
        'player'      => array('name' => $player->name, 'club' => $player->club),
        'appearances' => $enriched,
    ));
}

// ── AJAX: Check which players are new (not yet in DB) for preview highlighting ─
add_action('wp_ajax_nopriv_lgw_check_new_players', 'lgw_ajax_check_new_players');
add_action('wp_ajax_lgw_check_new_players',        'lgw_ajax_check_new_players');
function lgw_ajax_check_new_players() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');

    // players: JSON array of {name, club} objects
    $raw = json_decode(stripslashes($_POST['players'] ?? '[]'), true);
    if (!is_array($raw)) wp_send_json_success(array());

    global $wpdb;
    $tbl    = lgw_players_table();
    $at     = lgw_appearances_table();
    $new    = array();

    // Active season date range for appearances check
    $season = lgw_get_season();
    $season_where = '';
    if (!empty($season['start']) && !empty($season['end'])) {
        $season_where = $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'], $season['end']
        );
    }

    foreach ($raw as $entry) {
        $name = lgw_clean_player_name(sanitize_text_field($entry['name'] ?? ''));
        $club = sanitize_text_field($entry['club'] ?? '');
        if (!$name || !$club) continue;

        // Check if player exists at all
        $player_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $tbl WHERE club = %s AND name = %s",
            $club, $name
        ));

        if (!$player_id) {
            // Brand new player — never seen before
            $new[] = array('name' => $name, 'club' => $club, 'reason' => 'new_player');
            continue;
        }

        // Player exists — check if they have any appearances this season
        if ($season_where) {
            $count = $wpdb->get_var(
                "SELECT COUNT(*) FROM " . lgw_appearances_table() . " a
                 WHERE a.player_id = " . intval($player_id) . " $season_where"
            );
            if (!$count) {
                $new[] = array('name' => $name, 'club' => $club, 'reason' => 'new_this_season');
            }
        }
    }

    wp_send_json_success($new);
}

// ── AJAX: Backfill player appearances for a given season ─────────────────────
add_action('wp_ajax_lgw_backfill_season_players', 'lgw_ajax_backfill_season_players');
function lgw_ajax_backfill_season_players() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $season_id = sanitize_text_field($_POST['season_id'] ?? '');
    if (!$season_id) wp_send_json_error('Missing season ID');
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgw_backfill_players_' . $season_id)) {
        wp_send_json_error('Nonce invalid');
    }

    // Strategy 1: scorecards explicitly tagged with this season ID
    $posts_by_tag = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft'),
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => 'lgw_sc_season',
                'value' => $season_id,
            ),
        ),
    ));

    // Strategy 2: match ALL scorecards by date range (catches tagged-to-wrong-season and untagged)
    $posts_by_date = array();
    $season_obj = lgw_get_season_by_id($season_id);
    if ($season_obj && !empty($season_obj['start']) && !empty($season_obj['end'])) {
        $start = $season_obj['start']; // Y-m-d
        $end   = $season_obj['end'];
        $all_scorecards = get_posts(array(
            'post_type'      => 'lgw_scorecard',
            'posts_per_page' => -1,
            'post_status'    => array('publish', 'draft'),
            'fields'         => 'ids',
        ));
        foreach ($all_scorecards as $pid) {
            // Skip if already found by tag — avoid redundant meta reads
            if (in_array($pid, $posts_by_tag)) continue;
            $sc_data  = get_post_meta($pid, 'lgw_scorecard_data', true);
            $raw_date = $sc_data['date'] ?? '';
            if (!$raw_date) continue;
            $parts = explode('/', $raw_date);
            if (count($parts) === 3) {
                $ymd = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                if ($ymd >= $start && $ymd <= $end) {
                    $posts_by_date[] = $pid;
                }
            }
        }
    }

    // Merge, dedupe
    $all_ids = array_unique(array_merge($posts_by_tag, $posts_by_date));

    $count = 0;
    foreach ($all_ids as $pid) {
        $status = get_post_meta($pid, 'lgw_sc_status', true);
        if (in_array($status, array('confirmed', 'admin_resolved', 'auto_confirmed'), true)) {
            lgw_log_appearances($pid);
            $count++;
        }
    }

    wp_send_json_success(array(
        'count'     => $count,
        'total'     => count($all_ids),
        'season_id' => $season_id,
    ));
}

// ── AJAX: Public player stats (current season) ───────────────────────────────
// Returns W/D/L summary and teams played for in the current season.
// Keyed by player name + club (no auth required — public scorecard context).
add_action('wp_ajax_nopriv_lgw_get_player_stats', 'lgw_ajax_get_player_stats');
add_action('wp_ajax_lgw_get_player_stats',        'lgw_ajax_get_player_stats');
function lgw_ajax_get_player_stats() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');

    $name = sanitize_text_field($_POST['player_name'] ?? '');
    $club = sanitize_text_field($_POST['club']        ?? '');
    if (!$name) wp_send_json_error('Missing player name');

    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();

    // Look up player — match by name (normalised), optionally scoped to club
    $norm = lgw_normalise_player_name($name);
    if ($club) {
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pt WHERE name = %s AND club = %s LIMIT 1",
            $norm, $club
        ));
        // Fallback: exact original name
        if (!$player) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $pt WHERE name = %s AND club = %s LIMIT 1",
                $name, $club
            ));
        }
    }
    // Fallback: name only (pick first match)
    if (!$player) {
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pt WHERE name = %s LIMIT 1", $norm
        ));
    }
    if (!$player) {
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $pt WHERE name = %s LIMIT 1", $name
        ));
    }
    if (!$player) wp_send_json_error('Player not found');

    // Current-season WHERE clause
    $sw = lgw_season_where();

    // W / D / L counts for current season
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT result, COUNT(*) AS cnt
         FROM $at a
         WHERE a.player_id = %d AND a.result IS NOT NULL $sw
         GROUP BY a.result",
        $player->id
    ));

    $w = $d = $l = 0;
    foreach ($results as $r) {
        if ($r->result === 'W') $w = (int)$r->cnt;
        if ($r->result === 'D') $d = (int)$r->cnt;
        if ($r->result === 'L') $l = (int)$r->cnt;
    }
    $played = $w + $d + $l;

    // Distinct teams played for this season
    $teams = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT a.team
         FROM $at a
         WHERE a.player_id = %d $sw
         ORDER BY a.team ASC",
        $player->id
    ));

    wp_send_json_success(array(
        'name'   => $player->name,
        'club'   => $player->club,
        'played' => $played,
        'won'    => $w,
        'drawn'  => $d,
        'lost'   => $l,
        'teams'  => array_values($teams),
    ));
}

// ── Admin menu ────────────────────────────────────────────────────────────────
// ── Admin page ────────────────────────────────────────────────────────────────
function lgw_players_admin_page() {
    global $wpdb;
    $pt  = lgw_players_table();
    $at  = lgw_appearances_table();
    $nonce  = wp_create_nonce('lgw_players_nonce');

    // ── Season context: URL param ?season=ID overrides active season ─────────
    $all_seasons    = lgw_get_seasons();
    $viewing_season_id = sanitize_text_field($_GET['season'] ?? '');
    if ($viewing_season_id) {
        $viewing_season = lgw_get_season_by_id($viewing_season_id);
    } else {
        $viewing_season = lgw_get_active_season();
        $viewing_season_id = $viewing_season ? $viewing_season['id'] : '';
    }

    if ($viewing_season) {
        $season = array(
            'start' => $viewing_season['start'] ?? '',
            'end'   => $viewing_season['end']   ?? '',
            'label' => $viewing_season['label'] ?? '',
        );
    } else {
        $season = lgw_get_season(); // fallback
    }

    // Build season_where from the resolved season
    $season_where = '';
    if (!empty($season['start']) && !empty($season['end'])) {
        $season_where = $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'],
            $season['end']
        );
    }

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lgw_players_action'])) {
        check_admin_referer('lgw_players_nonce', 'lgw_players_nonce_field');
        $action = $_POST['lgw_players_action'];

        // save_season action removed — season dates now managed via Seasons admin (lgw-seasons.php)

        if ($action === 'save_paid_counts') {
            $paid_data = get_option('lgw_club_paid_counts', array());
            $season_key = sanitize_key($season['label'] ?? 'default');
            if (!isset($paid_data[$season_key])) $paid_data[$season_key] = array();
            $posted = $_POST['paid'] ?? array();
            foreach ($posted as $club_enc => $count) {
                $club_name = sanitize_text_field(base64_decode($club_enc));
                $paid_data[$season_key][$club_name] = max(0, intval($count));
            }
            update_option('lgw_club_paid_counts', $paid_data);
            echo '<div class="notice notice-success"><p>Paid player counts saved.</p></div>';
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

        if ($action === 'auto_merge_initials') {
            $pairs  = lgw_find_dotted_initial_duplicates();
            $merged = 0;
            foreach ($pairs as $pair) {
                $keep_id   = $pair['keep_id'];
                $remove_id = $pair['remove_id'];
                $wpdb->update($at, array('player_id' => $keep_id), array('player_id' => $remove_id), array('%d'), array('%d'));
                $wpdb->delete($pt, array('id' => $remove_id), array('%d'));
                $merged++;
            }
            lgw_prune_orphaned_players();
            $msg = $merged > 0 ? "Auto-merged $merged duplicate player" . ($merged !== 1 ? 's' : '') . '.' : 'No dotted-initial duplicates found.';
            echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
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
            $starred = intval($_POST['starred'] ?? 0) ? 1 : 0;
            $female  = intval($_POST['female']  ?? 0) ? 1 : 0;
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

    // Fetch all players with appearance counts and aggregated stats
    $players = $wpdb->get_results("
        SELECT p.id, p.club, p.name, p.starred, p.female,
               COUNT(DISTINCT a.id) as appearances,
               GROUP_CONCAT(DISTINCT a.team ORDER BY a.team SEPARATOR ', ') as teams,
               SUM(CASE WHEN a.result='W' THEN 1 ELSE 0 END) as wins,
               SUM(CASE WHEN a.result='D' THEN 1 ELSE 0 END) as draws,
               SUM(CASE WHEN a.result='L' THEN 1 ELSE 0 END) as losses,
               SUM(CASE WHEN a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as shots_for,
               SUM(CASE WHEN a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as shots_against,
               SUM(CASE WHEN a.game_type='league' AND a.result='W' THEN 1 ELSE 0 END) as lge_wins,
               SUM(CASE WHEN a.game_type='league' AND a.result='D' THEN 1 ELSE 0 END) as lge_draws,
               SUM(CASE WHEN a.game_type='league' AND a.result='L' THEN 1 ELSE 0 END) as lge_losses,
               SUM(CASE WHEN a.game_type='league' AND a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as lge_sf,
               SUM(CASE WHEN a.game_type='league' AND a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as lge_sa,
               SUM(CASE WHEN a.game_type='cup' AND a.result='W' THEN 1 ELSE 0 END) as cup_wins,
               SUM(CASE WHEN a.game_type='cup' AND a.result='D' THEN 1 ELSE 0 END) as cup_draws,
               SUM(CASE WHEN a.game_type='cup' AND a.result='L' THEN 1 ELSE 0 END) as cup_losses,
               SUM(CASE WHEN a.game_type='cup' AND a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as cup_sf,
               SUM(CASE WHEN a.game_type='cup' AND a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as cup_sa
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

    // ── Club summary data ─────────────────────────────────────────────────────
    // Load paid counts for this season
    $paid_data   = get_option('lgw_club_paid_counts', array());
    $season_key  = sanitize_key($season['label'] ?? 'default');
    $paid_counts = $paid_data[$season_key] ?? array();

    // Build per-club appearance counts (season-filtered)
    $club_summary = array();
    foreach ($clubs as $cname) {
        $club_players = $by_club[$cname] ?? array();
        $unique_players = count($club_players);
        $total_apps = 0;
        foreach ($club_players as $pl) {
            $total_apps += intval($pl->appearances);
        }
        $ladies = count(array_filter($club_players, function($p){ return $p->female; }));
        $club_summary[$cname] = array(
            'players'  => $unique_players,
            'apps'     => $total_apps,
            'ladies'   => $ladies,
            'paid'     => $paid_counts[$cname] ?? 0,
        );
    }

    ?>
    <div class="wrap">
    <h1>Player Tracking<?php if ($viewing_season && empty($viewing_season['active'])): ?> <span style="font-size:18px;color:#666;font-weight:400">— <?php echo esc_html($viewing_season['label']); ?></span><?php endif; ?></h1>

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
    .lgw-player-link{cursor:pointer;color:#1a2e5a;text-decoration:underline dotted;border:none;background:none;padding:0;font:inherit;font-weight:600}
    .lgw-player-link:hover{color:#0073aa}
    /* History modal */
    #lgw-history-modal{display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);align-items:flex-start;justify-content:center;padding:40px 16px}
    #lgw-history-modal.open{display:flex}
    #lgw-history-inner{background:#fff;border-radius:8px;width:100%;max-width:720px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3)}
    #lgw-history-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:2px solid #1a2e5a;position:sticky;top:0;background:#fff;z-index:1}
    #lgw-history-header h2{margin:0;font-size:16px;color:#1a2e5a}
    #lgw-history-close{background:none;border:none;font-size:22px;cursor:pointer;color:#666;line-height:1}
    #lgw-history-body{padding:16px 20px}
    .lgw-history-table{width:100%;border-collapse:collapse;font-size:13px}
    .lgw-history-table th{background:#1a2e5a;color:#fff;padding:7px 10px;text-align:left}
    .lgw-history-table td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:middle}
    .lgw-history-table tr:last-child td{border-bottom:none}
    .lgw-history-table tr:hover td{background:#f0f4ff}
    .lgw-sc-status{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700}
    .lgw-sc-confirmed{background:#d4edda;color:#155724}
    .lgw-sc-pending{background:#fff3cd;color:#856404}
    .lgw-sc-disputed{background:#f8d7da;color:#721c24}
    </style>

    <?php
    // ── Season switcher bar ───────────────────────────────────────────────────
    $base_url = admin_url('admin.php?page=lgw-players');
    if (!empty($all_seasons)):
    ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <label style="font-weight:600;font-size:13px;margin:0">Viewing season:</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($all_seasons as $sw_s):
            $is_active_s = !empty($sw_s['active']);
            $is_viewing  = ($sw_s['id'] === $viewing_season_id);
            $sw_url = $is_active_s
                ? $base_url
                : esc_url(add_query_arg('season', $sw_s['id'], $base_url));
            $btn_style = $is_viewing
                ? 'background:#1a2e5a;color:#fff;border-color:#1a2e5a'
                : 'background:#f0f2f8;color:#1a2e5a;border-color:#ccc';
        ?>
        <a href="<?php echo $sw_url; ?>"
           class="button button-small"
           style="<?php echo $btn_style; ?>;font-weight:600">
            <?php echo esc_html($sw_s['label']); ?>
            <?php if ($is_active_s): ?><span style="font-size:10px;opacity:.8"> ●</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        </div>
        <?php if (!empty($season['start']) || !empty($season['end'])): ?>
        <span style="font-size:12px;color:#666;margin-left:4px">
            <?php echo esc_html(($season['start'] ?: '?') . ' – ' . ($season['end'] ?: '?')); ?>
        </span>
        <?php elseif ($viewing_season): ?>
        <span style="font-size:12px;color:#aaa;margin-left:4px">no date range — all-time</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lgw-pt-tabs">
        <div class="lgw-pt-tab active" onclick="lgwTab('players')">Players</div>
        <div class="lgw-pt-tab" onclick="lgwTab('clubs')">Club Summary</div>
        <div class="lgw-pt-tab" onclick="lgwTab('merge')">Merge Duplicates</div>
        <div class="lgw-pt-tab" onclick="lgwTab('add')">Add Player</div>
        <div class="lgw-pt-tab" onclick="lgwTab('season')">Season Settings</div>
    </div>

    <script>
    function lgwTab(tab) {
        document.querySelectorAll('.lgw-pt-tab').forEach(function(t,i){
            t.classList.toggle('active', ['players','clubs','merge','add','season'][i]===tab);
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

    // ── Player history modal ──────────────────────────────────────────────────
    var lgwPlayersNonce = '<?php echo wp_create_nonce('lgw_players_nonce'); ?>';
    var lgwAjaxUrl      = '<?php echo admin_url('admin-ajax.php'); ?>';
    var lgwAdminUrl     = '<?php echo admin_url(); ?>';

    function lgwShowPlayerHistory(playerId) {
        var modal = document.getElementById('lgw-history-modal');
        var body  = document.getElementById('lgw-history-body');
        body.innerHTML = '<p>Loading…</p>';
        modal.classList.add('open');

        var fd = new FormData();
        fd.append('action',    'lgw_get_player_history');
        fd.append('nonce',     lgwPlayersNonce);
        fd.append('player_id', playerId);

        fetch(lgwAjaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.success) { body.innerHTML = '<p style="color:red">'+data.data+'</p>'; return; }
                var pl   = data.data.player;
                var apps = data.data.appearances;
                document.getElementById('lgw-history-title').textContent =
                    pl.name + ' \u2014 ' + pl.club;

                if (!apps.length) {
                    body.innerHTML = '<p>No game appearances recorded yet.</p>';
                    return;
                }

                // Calculate stats summary broken down by game type
                var stats = { all:{apps:0,w:0,d:0,l:0,sf:0,sa:0}, league:{apps:0,w:0,d:0,l:0,sf:0,sa:0}, cup:{apps:0,w:0,d:0,l:0,sf:0,sa:0} };
                apps.forEach(function(a) {
                    var gt = (a.game_type === 'cup') ? 'cup' : 'league';
                    stats.all.apps++;
                    stats[gt].apps++;
                    if (a.result === 'W') { stats.all.w++; stats[gt].w++; }
                    else if (a.result === 'D') { stats.all.d++; stats[gt].d++; }
                    else if (a.result === 'L') { stats.all.l++; stats[gt].l++; }
                    if (a.shots_for !== null && a.shots_for !== '') { stats.all.sf += parseInt(a.shots_for)||0; stats[gt].sf += parseInt(a.shots_for)||0; }
                    if (a.shots_against !== null && a.shots_against !== '') { stats.all.sa += parseInt(a.shots_against)||0; stats[gt].sa += parseInt(a.shots_against)||0; }
                });

                function statRow(label, s) {
                    if (!s.apps) return '';
                    var diff = s.sf - s.sa;
                    var diffStr = diff >= 0 ? '+' + diff : String(diff);
                    return '<tr><td><strong>' + label + '</strong></td>'
                        + '<td style="text-align:center">' + s.apps + '</td>'
                        + '<td style="text-align:center">' + s.w + '</td>'
                        + '<td style="text-align:center">' + s.d + '</td>'
                        + '<td style="text-align:center">' + s.l + '</td>'
                        + '<td style="text-align:center">' + s.sf + '</td>'
                        + '<td style="text-align:center">' + s.sa + '</td>'
                        + '<td style="text-align:center">' + diffStr + '</td>'
                        + '</tr>';
                }

                var hasStats = (stats.all.w + stats.all.d + stats.all.l) > 0;
                var statsHtml = '';
                if (hasStats) {
                    statsHtml = '<table class="lgw-history-table" style="margin-bottom:18px">'
                        + '<thead><tr><th>Competition</th><th style="text-align:center">Apps</th>'
                        + '<th style="text-align:center">W</th><th style="text-align:center">D</th><th style="text-align:center">L</th>'
                        + '<th style="text-align:center">SF</th><th style="text-align:center">SA</th><th style="text-align:center">+/−</th></tr></thead><tbody>'
                        + statRow('Total', stats.all)
                        + statRow('League', stats.league)
                        + statRow('Cup', stats.cup)
                        + '</tbody></table>';
                }

                var html = statsHtml
                    + '<p style="margin:0 0 10px;color:#555;font-size:13px">'
                    + apps.length + ' appearance' + (apps.length !== 1 ? 's' : '') + ' recorded</p>'
                    + '<table class="lgw-history-table">'
                    + '<thead><tr><th>Date</th><th>Match</th><th>Division</th>'
                    + '<th style="text-align:center">Rink</th><th>Team</th>'
                    + '<th style="text-align:center">Rink Score</th>'
                    + '<th style="text-align:center">Result</th>'
                    + '<th style="text-align:center">Match</th><th>Status</th>'
                    + '<th style="text-align:center">SC</th></tr></thead><tbody>';

                apps.forEach(function(a) {
                    var rinkScoreTxt = (a.shots_for !== null && a.shots_for !== '' && a.shots_against !== null && a.shots_against !== '')
                        ? a.shots_for + '\u2013' + a.shots_against : '\u2014';
                    var matchScoreTxt = (a.home_score !== '' && a.away_score !== '' && a.home_score !== null && a.away_score !== null)
                        ? a.home_score + '\u2013' + a.away_score : '\u2014';
                    var resultHtml = '\u2014';
                    if (a.result === 'W') resultHtml = '<span style="color:#138211;font-weight:700">W</span>';
                    else if (a.result === 'L') resultHtml = '<span style="color:#c0392b;font-weight:700">L</span>';
                    else if (a.result === 'D') resultHtml = '<span style="color:#e67e22;font-weight:700">D</span>';
                    var statusHtml = '';
                    if (a.status) {
                        var cls = 'lgw-sc-' + ({'confirmed':'confirmed','pending':'pending','disputed':'disputed'}[a.status] || 'pending');
                        statusHtml = '<span class="lgw-sc-status ' + cls + '">'
                            + a.status.charAt(0).toUpperCase() + a.status.slice(1) + '</span>';
                    }
                    var typeLabel = a.game_type === 'cup'
                        ? '<span style="font-size:10px;background:#072a82;color:#fff;border-radius:3px;padding:1px 5px;margin-left:4px">CUP</span>'
                        : '';
                    var scLink = a.scorecard_id
                        ? '<a href="' + lgwAdminUrl + 'post.php?post=' + a.scorecard_id + '&action=edit" target="_blank" title="Edit scorecard #' + a.scorecard_id + '" style="font-size:11px;color:#072a82;text-decoration:none;white-space:nowrap">#' + a.scorecard_id + ' &#8599;</a>'
                        : '\u2014';
                    html += '<tr>'
                        + '<td style="white-space:nowrap">' + lgwEsc(a.match_date) + '</td>'
                        + '<td>' + lgwEsc(a.match_title) + typeLabel + '</td>'
                        + '<td>' + lgwEsc(a.division) + '</td>'
                        + '<td style="text-align:center">' + (a.rink || '\u2014') + '</td>'
                        + '<td>' + lgwEsc(a.team) + '</td>'
                        + '<td style="text-align:center;white-space:nowrap">' + lgwEsc(rinkScoreTxt) + '</td>'
                        + '<td style="text-align:center">' + resultHtml + '</td>'
                        + '<td style="text-align:center;white-space:nowrap">' + lgwEsc(matchScoreTxt) + '</td>'
                        + '<td>' + statusHtml + '</td>'
                        + '<td style="text-align:center">' + scLink + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            })
            .catch(function(){ body.innerHTML = '<p style="color:red">Request failed.</p>'; });
    }

    function lgwEsc(str) {
        return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('lgw-history-close').addEventListener('click', function(){
            document.getElementById('lgw-history-modal').classList.remove('open');
        });
        document.getElementById('lgw-history-modal').addEventListener('click', function(e){
            if (e.target === this) this.classList.remove('open');
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') document.getElementById('lgw-history-modal').classList.remove('open');
        });
    });
    </script>

    <!-- Player history modal -->
    <div id="lgw-history-modal" role="dialog" aria-modal="true" aria-labelledby="lgw-history-title">
        <div id="lgw-history-inner">
            <div id="lgw-history-header">
                <h2 id="lgw-history-title">Player History</h2>
                <button id="lgw-history-close" title="Close">&times;</button>
            </div>
            <div id="lgw-history-body"></div>
        </div>
    </div>

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
                    <th style="text-align:center">Apps<?php echo $season_where ? ' (season)' : ''; ?></th>
                    <th style="text-align:center" title="Wins / Draws / Losses (all games)">W/D/L</th>
                    <th style="text-align:center" title="Shots For – Shots Against (all games)">SF–SA</th>
                    <th style="text-align:center" title="League: Wins / Draws / Losses">Lge W/D/L</th>
                    <th style="text-align:center" title="Cup: Wins / Draws / Losses">Cup W/D/L</th>
                    <th style="text-align:center" title="Starred player">⭐</th>
                    <th style="text-align:center" title="Female player">♀</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($club_players as $pl):
                    $has_stats = ($pl->wins + $pl->draws + $pl->losses) > 0;
                    $wdl_all   = $has_stats
                        ? intval($pl->wins).'/'.intval($pl->draws).'/'.intval($pl->losses)
                        : '—';
                    $sfsa_all  = $has_stats
                        ? intval($pl->shots_for).'–'.intval($pl->shots_against)
                        : '—';
                    $lge_has   = ($pl->lge_wins + $pl->lge_draws + $pl->lge_losses) > 0;
                    $wdl_lge   = $lge_has
                        ? intval($pl->lge_wins).'/'.intval($pl->lge_draws).'/'.intval($pl->lge_losses)
                        : '—';
                    $cup_has   = ($pl->cup_wins + $pl->cup_draws + $pl->cup_losses) > 0;
                    $wdl_cup   = $cup_has
                        ? intval($pl->cup_wins).'/'.intval($pl->cup_draws).'/'.intval($pl->cup_losses)
                        : '—';
                ?>
                <tr<?php echo $pl->appearances == 0 ? ' class="lgw-appearances-zero"' : ''; ?>>
                    <td>
                        <button class="lgw-player-link"
                            onclick="lgwShowPlayerHistory(<?php echo $pl->id; ?>)"
                            title="View game history"><?php echo esc_html($pl->name); ?></button>
                    </td>
                    <td><?php echo esc_html($pl->teams ?: '—'); ?></td>
                    <td style="text-align:center"><?php echo intval($pl->appearances); ?></td>
                    <td style="text-align:center;white-space:nowrap"><?php echo esc_html($wdl_all); ?></td>
                    <td style="text-align:center;white-space:nowrap"><?php echo esc_html($sfsa_all); ?></td>
                    <td style="text-align:center;white-space:nowrap"><?php echo esc_html($wdl_lge); ?></td>
                    <td style="text-align:center;white-space:nowrap"><?php echo esc_html($wdl_cup); ?></td>
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
                <?php
                $export_url = admin_url('admin-post.php?action=lgw_export_players&_wpnonce=' . wp_create_nonce('lgw_export_players'));
                if ($viewing_season_id && $viewing_season && empty($viewing_season['active'])) {
                    $export_url = add_query_arg('season', $viewing_season_id, $export_url);
                }
                ?>
                <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">⬇ Export to Excel</a>
            </p>
        <?php endif; ?>
    </div>

    <?php // ── Tab 2: Club Summary ── ?>
    <div class="lgw-pt-panel" id="lgw-panel-clubs">
        <h2>Club Summary<?php echo $season_key !== 'default' ? ' — ' . esc_html($season['label'] ?? '') : ''; ?></h2>

        <?php if (empty($clubs)): ?>
        <p>No player data available yet.</p>
        <?php else: ?>

        <form method="post" id="lgw-paid-form">
            <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
            <input type="hidden" name="lgw_players_action" value="save_paid_counts">

        <table class="widefat striped" style="margin-bottom:16px">
            <thead>
                <tr>
                    <th>Club</th>
                    <th style="text-align:center">Players<br><small style="font-weight:400">in tracker</small></th>
                    <th style="text-align:center">Appearances<br><small style="font-weight:400"><?php echo $season_where ? 'this season' : 'all-time'; ?></small></th>
                    <th style="text-align:center">Ladies</th>
                    <th style="text-align:center" title="Number of players the club has paid for">Players Paid<br><small style="font-weight:400">enter below</small></th>
                    <th style="text-align:center">Balance<br><small style="font-weight:400">paid − played</small></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $grand_players = 0; $grand_apps = 0; $grand_ladies = 0; $grand_paid = 0;
            foreach ($club_summary as $cname => $cs):
                $balance = $cs['paid'] - $cs['players'];
                $bal_col = $balance < 0 ? '#c0392b' : ($balance > 0 ? '#1a6e1a' : '#555');
                $grand_players += $cs['players']; $grand_apps += $cs['apps'];
                $grand_ladies  += $cs['ladies'];  $grand_paid += $cs['paid'];
                $enc = base64_encode($cname);
            ?>
                <tr>
                    <td><strong><?php echo esc_html($cname); ?></strong></td>
                    <td style="text-align:center"><?php echo $cs['players']; ?></td>
                    <td style="text-align:center"><?php echo $cs['apps']; ?></td>
                    <td style="text-align:center"><?php echo $cs['ladies']; ?></td>
                    <td style="text-align:center">
                        <input type="number" name="paid[<?php echo esc_attr($enc); ?>]"
                            value="<?php echo intval($cs['paid']); ?>"
                            min="0" style="width:70px;text-align:center">
                    </td>
                    <td style="text-align:center;font-weight:700;color:<?php echo $bal_col; ?>">
                        <?php echo ($balance > 0 ? '+' : '') . $balance; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#1a2e5a;color:#fff;font-weight:700">
                    <td>TOTAL</td>
                    <td style="text-align:center"><?php echo $grand_players; ?></td>
                    <td style="text-align:center"><?php echo $grand_apps; ?></td>
                    <td style="text-align:center"><?php echo $grand_ladies; ?></td>
                    <td style="text-align:center"><?php echo $grand_paid; ?></td>
                    <td style="text-align:center"><?php
                        $grand_bal = $grand_paid - $grand_players;
                        echo ($grand_bal > 0 ? '+' : '') . $grand_bal;
                    ?></td>
                </tr>
            </tfoot>
        </table>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button type="submit" class="button button-primary">💾 Save Paid Counts</button>

            <?php
            $export_qs = '?action=lgw_export_club_summary&_wpnonce=' . wp_create_nonce('lgw_export_club_summary');
            if (!empty($viewing_season['id'])) $export_qs .= '&season=' . urlencode($viewing_season['id']);
            ?>
            <a href="<?php echo esc_url(admin_url('admin-post.php') . $export_qs); ?>"
               class="button button-secondary" title="Download as spreadsheet">
                📊 Export as Spreadsheet
            </a>
            <a href="<?php echo esc_url(admin_url('admin-post.php') . str_replace('lgw_export_club_summary','lgw_export_club_summary_pdf', $export_qs) . '&_wpnonce=' . wp_create_nonce('lgw_export_club_summary_pdf')); ?>"
               class="button button-secondary" title="Download as PDF">
                🖨 Export as PDF
            </a>
        </div>
        </form>
        <?php endif; ?>
    </div>

    <?php // ── Tab 3: Merge ── ?>
    <div class="lgw-pt-panel" id="lgw-panel-merge">
        <h2>Merge Duplicate Players</h2>
        <p>Use this when the same person appears under two different spellings (e.g. "J Smith" and "John Smith"). All appearances will be moved to the player you keep, and the other record deleted.</p>

        <?php
        // ── Auto-merge: dotted initial duplicates ──
        $auto_pairs = lgw_find_dotted_initial_duplicates();
        if (!empty($auto_pairs)): ?>
        <div style="background:#fff8e1;border:1px solid #f0c040;border-radius:6px;padding:16px 20px;margin-bottom:20px">
            <h3 style="margin:0 0 8px">⚡ Auto-merge: dotted initial variants found</h3>
            <p style="margin:0 0 12px;font-size:13px">The following players appear to be the same person with/without dotted initials (e.g. "D. Bintley" vs "D Bintley"). The record with <em>more appearances</em> will be kept; all appearances from the other will be merged into it.</p>
            <table class="widefat striped" style="max-width:700px;margin-bottom:12px;font-size:13px">
                <thead><tr><th>Club</th><th>Keep (canonical)</th><th>Remove (duplicate)</th><th>Apps moved</th></tr></thead>
                <tbody>
                <?php foreach ($auto_pairs as $ap): ?>
                <tr>
                    <td><?php echo esc_html($ap['club']); ?></td>
                    <td><strong><?php echo esc_html($ap['keep_name']); ?></strong></td>
                    <td style="color:#a00"><?php echo esc_html($ap['remove_name']); ?></td>
                    <td><?php echo intval($ap['appearances_moved']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" onsubmit="return confirm('Merge <?php echo count($auto_pairs); ?> duplicate pair(s)? This cannot be undone.')">
                <?php wp_nonce_field('lgw_players_nonce','lgw_players_nonce_field'); ?>
                <input type="hidden" name="lgw_players_action" value="auto_merge_initials">
                <button type="submit" class="button button-primary">⚡ Merge <?php echo count($auto_pairs); ?> duplicate<?php echo count($auto_pairs) !== 1 ? 's' : ''; ?></button>
            </form>
        </div>
        <?php else: ?>
        <p style="color:#0a5a0a;font-size:13px">✅ No dotted-initial duplicates detected.</p>
        <?php endif; ?>


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
        <?php $disp_s = $viewing_season ?: lgw_get_active_season(); ?>
        <?php if ($disp_s): ?>
        <div style="background:#f0f6fc;border:1px solid #b8d4f0;border-radius:6px;padding:16px 20px;margin-bottom:16px">
            <p style="margin:0 0 8px;font-weight:600">
                <?php echo !empty($disp_s['active']) ? 'Active season' : 'Archived season'; ?>:
                <?php echo esc_html($disp_s['label']); ?>
            </p>
            <?php if (!empty($disp_s['start']) || !empty($disp_s['end'])): ?>
            <p style="margin:0 0 4px;font-size:13px;color:#555">
                <strong>Start:</strong> <?php echo esc_html($disp_s['start'] ?: '—'); ?>
                &nbsp;&nbsp;
                <strong>End:</strong> <?php echo esc_html($disp_s['end'] ?: '—'); ?>
            </p>
            <p style="margin:8px 0 0;font-size:13px;color:#555">Appearance counts are filtered to matches within this date range.</p>
            <?php else: ?>
            <p style="margin:0;font-size:13px;color:#888">No date range set — showing all-time totals. Add start/end dates in Seasons admin to filter by season.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="background:#fff8e5;border:1px solid #e8b400;border-radius:6px;padding:16px 20px;margin-bottom:16px">
            <p style="margin:0;font-size:13px;color:#555">No season configured — showing all-time totals.</p>
        </div>
        <?php endif; ?>
        <p>Season dates are managed in the <strong>Seasons</strong> admin page alongside your divisions.</p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=lgw-seasons')); ?>" class="button button-primary">Go to Seasons Admin →</a>
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

    // Respect ?season=ID param so export matches what user is viewing
    $export_season_id = sanitize_text_field($_GET['season'] ?? '');
    if ($export_season_id) {
        $export_season_obj = lgw_get_season_by_id($export_season_id);
    } else {
        $export_season_obj = lgw_get_active_season();
    }
    if ($export_season_obj) {
        $season = array(
            'start' => $export_season_obj['start'] ?? '',
            'end'   => $export_season_obj['end']   ?? '',
            'label' => $export_season_obj['label'] ?? '',
        );
    } else {
        $season = lgw_get_season();
    }
    $season_where = '';
    if (!empty($season['start']) && !empty($season['end'])) {
        $season_where = $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'], $season['end']
        );
    }
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

    // ── Fetch per-player aggregate stats ─────────────────────────────────────
    $stats_rows = $wpdb->get_results(
        "SELECT a.player_id,
                SUM(CASE WHEN a.result='W' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN a.result='D' THEN 1 ELSE 0 END) as draws,
                SUM(CASE WHEN a.result='L' THEN 1 ELSE 0 END) as losses,
                SUM(CASE WHEN a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as shots_for,
                SUM(CASE WHEN a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as shots_against,
                SUM(CASE WHEN a.game_type='league' AND a.result='W' THEN 1 ELSE 0 END) as lge_w,
                SUM(CASE WHEN a.game_type='league' AND a.result='D' THEN 1 ELSE 0 END) as lge_d,
                SUM(CASE WHEN a.game_type='league' AND a.result='L' THEN 1 ELSE 0 END) as lge_l,
                SUM(CASE WHEN a.game_type='league' AND a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as lge_sf,
                SUM(CASE WHEN a.game_type='league' AND a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as lge_sa,
                SUM(CASE WHEN a.game_type='cup' AND a.result='W' THEN 1 ELSE 0 END) as cup_w,
                SUM(CASE WHEN a.game_type='cup' AND a.result='D' THEN 1 ELSE 0 END) as cup_d,
                SUM(CASE WHEN a.game_type='cup' AND a.result='L' THEN 1 ELSE 0 END) as cup_l,
                SUM(CASE WHEN a.game_type='cup' AND a.shots_for IS NOT NULL THEN a.shots_for ELSE 0 END) as cup_sf,
                SUM(CASE WHEN a.game_type='cup' AND a.shots_against IS NOT NULL THEN a.shots_against ELSE 0 END) as cup_sa
         FROM $at a
         " . ($season_where ? "WHERE 1=1 $season_where" : "") . "
         GROUP BY a.player_id"
    );
    $player_stats = array();
    foreach ($stats_rows as $sr) {
        $player_stats[$sr->player_id] = $sr;
    }

    // ── Group players by club ─────────────────────────────────────────────────
    $by_club = array();
    foreach ($all_players as $pl) {
        $by_club[$pl->club][] = $pl;
    }

    // ── Build sheet names ─────────────────────────────────────────────────────
    $sheet_names = array('Summary', 'Stats');
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
    // STATS SHEET
    // ════════════════════════════════════════════════════════════════════════
    echo '<table id="Stats">';
    echo '<tr><td class="hdr1" colspan="18">Player Statistics' . esc_html($label) . '</td></tr>';
    echo '<tr>'
       . '<td class="hdr2">Club</td>'
       . '<td class="hdr2">Player</td>'
       . '<td class="hdr2">Apps</td>'
       . '<td class="total-hdr">W</td><td class="total-hdr">D</td><td class="total-hdr">L</td>'
       . '<td class="total-hdr">SF</td><td class="total-hdr">SA</td><td class="total-hdr">+/−</td>'
       . '<td class="hdr3">Lge W</td><td class="hdr3">Lge D</td><td class="hdr3">Lge L</td>'
       . '<td class="hdr3">Lge SF</td><td class="hdr3">Lge SA</td>'
       . '<td class="flag-hdr">Cup W</td><td class="flag-hdr">Cup D</td><td class="flag-hdr">Cup L</td>'
       . '<td class="flag-hdr">Cup SF</td>'
       . '</tr>';

    foreach ($all_players as $pl) {
        $st  = $player_stats[$pl->id] ?? null;
        $apps = count($player_apps[$pl->id] ?? array());
        $w   = $st ? intval($st->wins)         : 0;
        $d   = $st ? intval($st->draws)        : 0;
        $l   = $st ? intval($st->losses)       : 0;
        $sf  = $st ? intval($st->shots_for)    : 0;
        $sa  = $st ? intval($st->shots_against): 0;
        $diff= $sf - $sa;
        echo '<tr>'
           . '<td class="player-name">' . esc_html($pl->club) . '</td>'
           . '<td class="player-name">' . esc_html($pl->name) . '</td>'
           . '<td class="total-cell">' . $apps . '</td>'
           . '<td class="total-cell">' . ($w ?: '') . '</td>'
           . '<td class="total-cell">' . ($d ?: '') . '</td>'
           . '<td class="total-cell">' . ($l ?: '') . '</td>'
           . '<td class="total-cell">' . ($sf ?: '') . '</td>'
           . '<td class="total-cell">' . ($sa ?: '') . '</td>'
           . '<td class="total-cell">' . ($w+$d+$l > 0 ? ($diff >= 0 ? '+':'').$diff : '') . '</td>'
           . '<td class="app-a">'    . ($st ? ($st->lge_w ?: '') : '') . '</td>'
           . '<td class="app-a">'    . ($st ? ($st->lge_d ?: '') : '') . '</td>'
           . '<td class="app-a">'    . ($st ? ($st->lge_l ?: '') : '') . '</td>'
           . '<td class="app-a">'    . ($st ? ($st->lge_sf ?: '') : '') . '</td>'
           . '<td class="app-a">'    . ($st ? ($st->lge_sa ?: '') : '') . '</td>'
           . '<td class="app-b">'    . ($st ? ($st->cup_w ?: '') : '') . '</td>'
           . '<td class="app-b">'    . ($st ? ($st->cup_d ?: '') : '') . '</td>'
           . '<td class="app-b">'    . ($st ? ($st->cup_l ?: '') : '') . '</td>'
           . '<td class="app-b">'    . ($st ? ($st->cup_sf ?: '') : '') . '</td>'
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
        $fixed_cols = 12; // Name, T, A, B, MW, W, D, L, SF, SA, Starred, Female
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
           . '<td class="total-hdr">W</td>'
           . '<td class="total-hdr">D</td>'
           . '<td class="total-hdr">L</td>'
           . '<td class="total-hdr">SF</td>'
           . '<td class="total-hdr">SA</td>'
           . '<td class="flag-hdr">⭐</td>'
           . '<td class="flag-hdr">♀</td>';
        foreach ($club_matches as $mk) {
            echo '<td class="hdr2">' . esc_html($match_meta[$mk]['date']) . '</td>';
        }
        echo '</tr>';

        // Row 3: team playing (home/away label for this club)
        echo '<tr>'
           . '<td class="hdr3">Team</td>'
           . '<td class="hdr3" colspan="9"></td>'
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
           . '<td class="hdr3" colspan="9"></td>'
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
           . '<td class="hdr3" colspan="9"></td>'
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
           . '<td class="hdr3" colspan="9"></td>'
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
            $st       = $player_stats[$pl->id] ?? null;
            $p_w      = $st ? intval($st->wins)         : 0;
            $p_d      = $st ? intval($st->draws)        : 0;
            $p_l      = $st ? intval($st->losses)       : 0;
            $p_sf     = $st ? intval($st->shots_for)    : 0;
            $p_sa     = $st ? intval($st->shots_against): 0;

            echo '<tr' . $row_cls . '>'
               . '<td class="player-name">' . esc_html($pl->name) . '</td>'
               . '<td class="total-cell">' . ($t_total ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_a  ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_b  ?: '') . '</td>'
               . '<td class="total-cell">' . ($t_mw ?: '') . '</td>'
               . '<td class="total-cell">' . ($p_w  ?: '') . '</td>'
               . '<td class="total-cell">' . ($p_d  ?: '') . '</td>'
               . '<td class="total-cell">' . ($p_l  ?: '') . '</td>'
               . '<td class="total-cell">' . ($p_sf ?: '') . '</td>'
               . '<td class="total-cell">' . ($p_sa ?: '') . '</td>'
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

// ── Club Summary: XLSX export ────────────────────────────────────────────────
add_action('admin_post_lgw_export_club_summary', 'lgw_export_club_summary_xlsx');
function lgw_export_club_summary_xlsx() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_export_club_summary');

    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();

    $sid = sanitize_text_field($_GET['season'] ?? '');
    $season_obj = $sid ? lgw_get_season_by_id($sid) : lgw_get_active_season();
    if ($season_obj) {
        $season = array('start' => $season_obj['start'] ?? '', 'end' => $season_obj['end'] ?? '', 'label' => $season_obj['label'] ?? '');
    } else {
        $season = lgw_get_season();
    }
    $season_key = sanitize_key($season['label'] ?? 'default');
    $label = !empty($season['label']) ? $season['label'] : 'All Time';

    $season_where = '';
    if (!empty($season['start']) && !empty($season['end'])) {
        $season_where = $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'], $season['end']
        );
    }

    $players = $wpdb->get_results(
        "SELECT p.id, p.club, p.name, p.female,
                COUNT(DISTINCT a.id) as appearances
         FROM $pt p
         LEFT JOIN $at a ON a.player_id = p.id " .
        ($season_where ? "WHERE 1=1 $season_where " : "") . "
         GROUP BY p.id ORDER BY p.club, p.name"
    );

    $by_club = array();
    foreach ($players as $pl) { $by_club[$pl->club][] = $pl; }

    $paid_data   = get_option('lgw_club_paid_counts', array());
    $paid_counts = $paid_data[$season_key] ?? array();

    $all_clubs = array_unique(array_merge(
        array_map(function($c){ return $c['name']; }, lgw_get_clubs()),
        array_keys($by_club)
    ));
    sort($all_clubs);

    $filename = 'lgw-club-summary-' . str_replace('/', '-', $label) . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "
";
    echo '<?mso-application progid="Excel.Sheet"?>' . "
";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
                   xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
    <Styles>
        <Style ss:ID="h1"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1A2E5A" ss:Pattern="Solid"/></Style>
        <Style ss:ID="h2"><Font ss:Bold="1"/><Interior ss:Color="#D0D8EE" ss:Pattern="Solid"/></Style>
        <Style ss:ID="bold"><Font ss:Bold="1"/></Style>
        <Style ss:ID="ctr"><Alignment ss:Horizontal="Center"/></Style>
        <Style ss:ID="ctr_bold"><Alignment ss:Horizontal="Center"/><Font ss:Bold="1"/></Style>
        <Style ss:ID="red"><Alignment ss:Horizontal="Center"/><Font ss:Bold="1" ss:Color="#C0392B"/></Style>
        <Style ss:ID="grn"><Alignment ss:Horizontal="Center"/><Font ss:Bold="1" ss:Color="#1A6E1A"/></Style>
        <Style ss:ID="tot"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1A2E5A" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
    </Styles>
    <Worksheet ss:Name="Club Summary">
    <Table>';

    $row = function($cells) { echo '<Row>' . implode('', $cells) . '</Row>' . "
"; };
    $c   = function($val, $style='', $type='String') {
        $s = $style ? ' ss:StyleID="'.$style.'"' : '';
        return '<Cell'.$s.'><Data ss:Type="'.$type.'">'.esc_html($val).'</Data></Cell>';
    };

    $row(array($c('LGW Club Summary — ' . $label, 'h1'), '<Cell/><Cell/><Cell/><Cell/><Cell/>'));
    $row(array($c('Club','h2'), $c('Players in Tracker','h2'), $c('Appearances','h2'), $c('Ladies','h2'), $c('Players Paid','h2'), $c('Balance','h2')));

    $g_pl = $g_ap = $g_la = $g_pd = 0;
    foreach ($all_clubs as $cname) {
        $cps  = $by_club[$cname] ?? array();
        $n_pl = count($cps);
        $n_ap = array_sum(array_column($cps, 'appearances'));
        $n_la = count(array_filter($cps, function($p){ return $p->female; }));
        $n_pd = intval($paid_counts[$cname] ?? 0);
        $bal  = $n_pd - $n_pl;
        $bal_style = $bal < 0 ? 'red' : ($bal > 0 ? 'grn' : 'ctr_bold');
        $g_pl += $n_pl; $g_ap += $n_ap; $g_la += $n_la; $g_pd += $n_pd;
        $row(array($c($cname,'bold'), $c($n_pl,'ctr','Number'), $c($n_ap,'ctr','Number'), $c($n_la,'ctr','Number'), $c($n_pd,'ctr','Number'), $c(($bal>0?'+':'').$bal, $bal_style)));
    }
    $g_bal = $g_pd - $g_pl;
    $row(array($c('TOTAL','tot'), $c($g_pl,'tot','Number'), $c($g_ap,'tot','Number'), $c($g_la,'tot','Number'), $c($g_pd,'tot','Number'), $c(($g_bal>0?'+':'').$g_bal,'tot')));

    echo '</Table></Worksheet></Workbook>';
    exit;
}

// ── Club Summary: PDF export ──────────────────────────────────────────────────
add_action('admin_post_lgw_export_club_summary_pdf', 'lgw_export_club_summary_pdf');
function lgw_export_club_summary_pdf() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_export_club_summary_pdf');

    global $wpdb;
    $pt = lgw_players_table();
    $at = lgw_appearances_table();

    $sid = sanitize_text_field($_GET['season'] ?? '');
    $season_obj = $sid ? lgw_get_season_by_id($sid) : lgw_get_active_season();
    if ($season_obj) {
        $season = array('start' => $season_obj['start'] ?? '', 'end' => $season_obj['end'] ?? '', 'label' => $season_obj['label'] ?? '');
    } else {
        $season = lgw_get_season();
    }
    $season_key = sanitize_key($season['label'] ?? 'default');
    $label = !empty($season['label']) ? $season['label'] : 'All Time';

    $season_where = '';
    if (!empty($season['start']) && !empty($season['end'])) {
        $season_where = $wpdb->prepare(
            "AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') >= %s AND STR_TO_DATE(a.match_date, '%%d/%%m/%%Y') <= %s",
            $season['start'], $season['end']
        );
    }

    $players = $wpdb->get_results(
        "SELECT p.id, p.club, p.name, p.female,
                COUNT(DISTINCT a.id) as appearances
         FROM $pt p
         LEFT JOIN $at a ON a.player_id = p.id " .
        ($season_where ? "WHERE 1=1 $season_where " : "") . "
         GROUP BY p.id ORDER BY p.club, p.name"
    );

    $by_club = array();
    foreach ($players as $pl) { $by_club[$pl->club][] = $pl; }

    $paid_data   = get_option('lgw_club_paid_counts', array());
    $paid_counts = $paid_data[$season_key] ?? array();

    $all_clubs = array_unique(array_merge(
        array_map(function($c){ return $c['name']; }, lgw_get_clubs()),
        array_keys($by_club)
    ));
    sort($all_clubs);

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="lgw-club-summary.html"');
    // Print-ready HTML that opens as PDF via browser print dialog
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>LGW Club Summary — ' . esc_html($label) . '</title>
    <style>
        @media print { body { margin: 10mm; } .no-print { display:none; } }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #222; }
        h1 { color: #1a2e5a; font-size: 16pt; margin-bottom: 4px; }
        h2 { color: #555; font-size: 11pt; font-weight: normal; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { background: #1a2e5a; color: #fff; padding: 8px 10px; text-align: center; font-size: 10pt; }
        th:first-child { text-align: left; }
        td { padding: 7px 10px; border-bottom: 1px solid #ddd; font-size: 10pt; text-align: center; }
        td:first-child { text-align: left; font-weight: 600; }
        tr:nth-child(even) { background: #f5f7fb; }
        .total-row { background: #1a2e5a !important; color: #fff; font-weight: 700; }
        .total-row td { color: #fff; border: none; }
        .neg { color: #c0392b; font-weight: 700; }
        .pos { color: #1a6e1a; font-weight: 700; }
        .btn { display:inline-block;margin-top:12px;padding:8px 18px;background:#1a2e5a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11pt; }
    </style>
    </head><body>
    <button class="btn no-print" onclick="window.print()">🖨 Print / Save as PDF</button>
    <h1>LGW Club Summary</h1>
    <h2>' . esc_html($label) . ($season_where ? ' &nbsp;|&nbsp; ' . esc_html($season['start'] . ' – ' . $season['end']) : '') . '</h2>
    <table>
        <thead><tr>
            <th>Club</th>
            <th>Players in Tracker</th>
            <th>Appearances</th>
            <th>Ladies</th>
            <th>Players Paid</th>
            <th>Balance</th>
        </tr></thead><tbody>';

    $g_pl = $g_ap = $g_la = $g_pd = 0;
    foreach ($all_clubs as $cname) {
        $cps  = $by_club[$cname] ?? array();
        $n_pl = count($cps);
        $n_ap = array_sum(array_column($cps, 'appearances'));
        $n_la = count(array_filter($cps, function($p){ return $p->female; }));
        $n_pd = intval($paid_counts[$cname] ?? 0);
        $bal  = $n_pd - $n_pl;
        $g_pl += $n_pl; $g_ap += $n_ap; $g_la += $n_la; $g_pd += $n_pd;
        $bal_cls = $bal < 0 ? 'neg' : ($bal > 0 ? 'pos' : '');
        echo '<tr>'
           . '<td>' . esc_html($cname) . '</td>'
           . '<td>' . $n_pl . '</td>'
           . '<td>' . $n_ap . '</td>'
           . '<td>' . $n_la . '</td>'
           . '<td>' . $n_pd . '</td>'
           . '<td class="' . $bal_cls . '">' . ($bal > 0 ? '+' : '') . $bal . '</td>'
           . '</tr>';
    }
    $g_bal = $g_pd - $g_pl;
    echo '<tr class="total-row">'
       . '<td>TOTAL</td>'
       . '<td>' . $g_pl . '</td>'
       . '<td>' . $g_ap . '</td>'
       . '<td>' . $g_la . '</td>'
       . '<td>' . $g_pd . '</td>'
       . '<td>' . ($g_bal > 0 ? '+' : '') . $g_bal . '</td>'
       . '</tr>';
    echo '</tbody></table>';
    echo '<p style="margin-top:20px;font-size:9pt;color:#999">Generated by LGW v' . LGW_VERSION . ' on ' . date('d/m/Y H:i') . '</p>';
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
