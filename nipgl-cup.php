<?php
/**
 * NIPGL Cup Bracket Feature - v6.4.29
 * Single-elimination knockout bracket widget with live animated draw.
 */

// ── Enqueue cup assets ─────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'nipgl_cup_enqueue');
function nipgl_cup_enqueue() {
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'nipgl_cup')) return;
    wp_enqueue_style('nipgl-saira',  'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null);
    wp_enqueue_style('nipgl-widget', plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-widget.css', array('nipgl-saira'), NIPGL_VERSION);
    wp_enqueue_style('nipgl-cup',    plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-cup.css',    array('nipgl-widget'), NIPGL_VERSION);
    wp_enqueue_script('nipgl-cup',   plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-cup.js',     array(), NIPGL_VERSION, true);
    // Reuse nipglData (badges, clubBadges, ajaxUrl) if already localised by main widget
    if (!wp_script_is('nipgl-widget', 'enqueued')) {
        $badges      = get_option('nipgl_badges',      array());
        $club_badges = get_option('nipgl_club_badges', array());
        $sponsors    = get_option('nipgl_sponsors',    array());
        wp_localize_script('nipgl-cup', 'nipglData', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'badges'     => $badges,
            'clubBadges' => $club_badges,
            'sponsors'   => $sponsors,
        ));
    }
    // Always localise cup-specific data (score entry, admin flag)
    wp_localize_script('nipgl-cup', 'nipglCupData', array(
        'ajaxUrl'            => admin_url('admin-ajax.php'),
        'isAdmin'            => current_user_can('manage_options') ? 1 : 0,
        'scoreNonce'         => wp_create_nonce('nipgl_cup_score'),
        'drawPassphraseSet'  => get_option('nipgl_draw_passphrase', '') !== '' ? 1 : 0,
        'cupNonce'           => wp_create_nonce('nipgl_cup_nonce'),
        'drawSpeed'          => (float) get_option('nipgl_draw_speed', 1.0),
    ));
}

// ── AJAX: Verify draw passphrase ───────────────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_cup_draw_auth', 'nipgl_ajax_cup_draw_auth');
add_action('wp_ajax_nipgl_cup_draw_auth',        'nipgl_ajax_cup_draw_auth');
function nipgl_ajax_cup_draw_auth() {
    // wp_verify_nonce instead of check_ajax_referer so nonce failures return JSON not plain -1
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_cup_nonce')) {
        wp_send_json_error('Session expired — please refresh the page and try again.');
    }
    $raw    = strtolower(trim(sanitize_text_field($_POST['passphrase'] ?? '')));
    $stored = get_option('nipgl_draw_passphrase', '');
    if ($stored === '') wp_send_json_error('No draw passphrase configured.');
    if (!hash_equals($stored, hash('sha256', $raw))) wp_send_json_error('Incorrect passphrase.');
    // Store auth in session (WP session via transient keyed to session cookie)
    $token = wp_generate_password(32, false);
    set_transient('nipgl_draw_auth_' . $token, 1, HOUR_IN_SECONDS);
    wp_send_json_success(array('token' => $token));
}

// ── AJAX: Validate draw auth token before performing draw ─────────────────────
// Called internally — nipgl_ajax_cup_perform_draw checks this
function nipgl_cup_check_draw_auth() {
    // Check token passed from JS (required for everyone on the public page)
    $token = sanitize_text_field($_POST['draw_token'] ?? '');
    if ($token && get_transient('nipgl_draw_auth_' . $token)) return true;
    // WP admins triggering from wp-admin (inline draw button) don't pass a token
    if (current_user_can('manage_options') && empty($_POST['draw_token'])) return true;
    return false;
}


add_action('wp_ajax_nipgl_cup_save_score', 'nipgl_ajax_cup_save_score');
function nipgl_ajax_cup_save_score() {
    check_ajax_referer('nipgl_cup_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $cup_id    = sanitize_key($_POST['cup_id']    ?? '');
    $round_idx = intval($_POST['round_idx']       ?? -1);
    $match_idx = intval($_POST['match_idx']       ?? -1);
    $home_score = $_POST['home_score'] !== '' ? intval($_POST['home_score']) : null;
    $away_score = $_POST['away_score'] !== '' ? intval($_POST['away_score']) : null;

    if (!$cup_id || $round_idx < 0 || $match_idx < 0) wp_send_json_error('Invalid parameters');

    $cup = get_option('nipgl_cup_' . $cup_id, array());
    if (empty($cup)) wp_send_json_error('Cup not found');

    $bracket = &$cup['bracket'];
    if (!isset($bracket['matches'][$round_idx][$match_idx])) wp_send_json_error('Match not found');

    $match = &$bracket['matches'][$round_idx][$match_idx];
    $match['home_score'] = $home_score;
    $match['away_score'] = $away_score;

    // Advance winner to next round if both scores set
    if ($home_score !== null && $away_score !== null && $home_score !== $away_score) {
        $winner = $home_score > $away_score ? $match['home'] : $match['away'];
        $next_round = $round_idx + 1;
        $next_match = intval(floor($match_idx / 2));
        $next_slot  = $match_idx % 2 === 0 ? 'home' : 'away';
        if (isset($bracket['matches'][$next_round][$next_match])) {
            $bracket['matches'][$next_round][$next_match][$next_slot]           = $winner;
            $bracket['matches'][$next_round][$next_match][$next_slot . '_score'] = null;
        }
    }

    update_option('nipgl_cup_' . $cup_id, $cup);
    wp_send_json_success(array('bracket' => $bracket));
}

// ── AJAX: Get scorecard for a cup match ───────────────────────────────────────
add_action('wp_ajax_nipgl_cup_get_scorecard',        'nipgl_ajax_cup_get_scorecard');
add_action('wp_ajax_nopriv_nipgl_cup_get_scorecard', 'nipgl_ajax_cup_get_scorecard');
function nipgl_ajax_cup_get_scorecard() {
    $home = sanitize_text_field($_POST['home'] ?? '');
    $away = sanitize_text_field($_POST['away'] ?? '');
    if (!$home || !$away) wp_send_json_error('Missing teams');

    $post = nipgl_get_scorecard($home, $away);
    if (!$post) wp_send_json_error('No scorecard found');

    $sc      = get_post_meta($post->ID, 'nipgl_scorecard_data', true);
    $conf_by = get_post_meta($post->ID, 'nipgl_confirmed_by',   true);
    if (!$sc)  wp_send_json_error('No scorecard data');

    wp_send_json_success(array(
        'id'           => $post->ID,
        'confirmed_by' => $conf_by,
        'sc'           => array(
            'division'    => $sc['division']    ?? '',
            'venue'       => $sc['venue']       ?? '',
            'date'        => $sc['date']        ?? '',
            'home_team'   => $sc['home_team']   ?? '',
            'away_team'   => $sc['away_team']   ?? '',
            'home_total'  => $sc['home_total']  ?? null,
            'away_total'  => $sc['away_total']  ?? null,
            'home_points' => $sc['home_points'] ?? null,
            'away_points' => $sc['away_points'] ?? null,
            'rinks'       => $sc['rinks']       ?? array(),
        ),
    ));
}


add_shortcode('nipgl_cup', 'nipgl_cup_shortcode');
function nipgl_cup_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id'           => '',
        'title'        => '',
        'sponsor_img'  => '',
        'sponsor_url'  => '',
        'sponsor_name' => '',
    ), $atts);

    $cup_id = sanitize_key($atts['id']);
    if (!$cup_id) return '<p>No cup ID provided.</p>';

    $cup      = get_option('nipgl_cup_' . $cup_id, array());
    $title    = !empty($atts['title']) ? $atts['title'] : ($cup['title'] ?? 'Cup');
    $bracket  = $cup['bracket'] ?? null;
    $version  = $cup['draw_version'] ?? 0;
    $drawn    = $version > 0;
    $is_admin = current_user_can('manage_options');

    $bracket_json    = $bracket ? wp_json_encode($bracket) : '';
    $nonce           = wp_create_nonce('nipgl_cup_nonce');
    $global_sponsors = get_option('nipgl_sponsors', array());
    if (!empty($atts['sponsor_img'])) {
        $primary_sponsor = array(
            'image' => esc_url($atts['sponsor_img']),
            'url'   => esc_url($atts['sponsor_url']),
            'name'  => esc_attr($atts['sponsor_name']),
        );
        $extra_sponsors = array_slice($global_sponsors, 1);
    } else {
        $primary_sponsor = !empty($global_sponsors[0]) ? $global_sponsors[0] : null;
        $extra_sponsors  = array_slice($global_sponsors, 1);
    }
    $extra_json      = esc_attr(wp_json_encode($extra_sponsors));
    $primary_html    = '';
    if ($primary_sponsor && !empty($primary_sponsor['image'])) {
        $img          = '<img src="' . esc_url($primary_sponsor['image']) . '" alt="' . esc_attr($primary_sponsor['name'] ?: 'Sponsor') . '" class="nipgl-sponsor-img">';
        $primary_html = '<div class="nipgl-sponsor-bar nipgl-sponsor-primary">'
            . (!empty($primary_sponsor['url']) ? '<a href="' . esc_url($primary_sponsor['url']) . '" target="_blank" rel="noopener">' . $img . '</a>' : $img)
            . '</div>';
    }

    ob_start();
    ?>
    <div class="nipgl-cup-wrap" data-cup-id="<?php echo esc_attr($cup_id); ?>"
         data-draw-version="<?php echo esc_attr($version); ?>"
         data-draw-in-progress="<?php echo (!empty($cup['draw_in_progress']) && empty($cup['bracket'])) ? '1' : '0'; ?>"
         data-bracket="<?php echo esc_attr($bracket_json); ?>"
         data-sponsors="<?php echo $extra_json; ?>">

      <?php echo $primary_html; ?>
      <div class="nipgl-cup-header">
        <span class="nipgl-cup-title">🏆 <?php echo esc_html($title); ?></span>
        <?php
        $draw_passphrase_set = get_option('nipgl_draw_passphrase', '') !== '';
        $show_draw_btn = !$drawn && $draw_passphrase_set;
        if ($show_draw_btn): ?>
        <div class="nipgl-cup-header-actions">
          <button class="nipgl-cup-btn nipgl-cup-btn-ghost nipgl-cup-draw-login-btn">
            🔑 Login to Draw
          </button>
        </div>
        <?php elseif ($drawn): ?>
        <div class="nipgl-cup-header-actions nipgl-cup-post-draw-actions">
          <button class="nipgl-cup-btn nipgl-cup-btn-ghost nipgl-cup-print-btn">
            🖨 Print Draw
          </button>
        </div>
        <?php endif; ?>
      </div>

      <div class="nipgl-cup-tabs"><div class="nipgl-cup-tabs-inner"></div></div>

      <div class="nipgl-cup-bracket-outer">
        <?php if (!$bracket): ?>
          <div class="nipgl-cup-empty">
            <div class="nipgl-cup-empty-icon">🎲</div>
            <?php if ($is_admin): ?>
              <p>No draw has been performed yet. Click <strong>Perform Draw</strong> above to randomise the bracket.</p>
            <?php else: ?>
              <p>The draw has not yet taken place. Check back soon!</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="nipgl-cup-bracket"></div>
      </div>

      <div class="nipgl-cup-status">
        <span class="nipgl-cup-status-dot<?php echo (!$bracket && $version == 0) ? ' live' : ''; ?>"></span>
        <span class="nipgl-cup-status-text">
          <?php if (!$bracket): ?>
            Waiting for draw…
          <?php else: ?>
            <?php echo esc_html(count($cup['entries'] ?? array())); ?> teams entered
            &nbsp;·&nbsp; Round 1: <?php echo esc_html($cup['dates'][0] ?? 'TBC'); ?>
          <?php endif; ?>
        </span>
      </div>

    </div>
    <?php
    return ob_get_clean();
}

// ── AJAX: Poll for draw state changes (visitors) ──────────────────────────────
add_action('wp_ajax_nipgl_cup_poll',        'nipgl_ajax_cup_poll');
add_action('wp_ajax_nopriv_nipgl_cup_poll', 'nipgl_ajax_cup_poll');
function nipgl_ajax_cup_poll() {
    $cup_id     = sanitize_key($_POST['cup_id'] ?? '');
    $client_ver = intval($_POST['version']      ?? 0);
    $client_cur = intval($_POST['cursor']       ?? -1); // -1 = not yet in cursor mode
    if (!$cup_id) wp_send_json_error('Missing cup_id');

    $cup     = get_option('nipgl_cup_' . $cup_id, array());
    $version = intval($cup['draw_version']    ?? 0);
    $cursor  = intval($cup['pairs_cursor']    ?? 0);
    $in_prog = !empty($cup['draw_in_progress']);
    $all_pairs = $cup['draw_pairs'] ?? array();
    $total   = count($all_pairs);

    // Auto-clear draw_in_progress if cursor has reached the end
    // (handles case where draw master disconnected before final advance)
    if ($in_prog && $cursor >= $total && $total > 0) {
        $cup['draw_in_progress'] = false;
        $in_prog = false;
        update_option('nipgl_cup_' . $cup_id, $cup);
    }

    // Legacy version-change poll (no cursor mode yet)
    if ($client_cur < 0) {
        if ($version === $client_ver) {
            wp_send_json_success(array(
                'version'     => $version,
                'changed'     => false,
                'in_progress' => $in_prog,
                'cursor'      => $cursor,
                'total'       => $total,
            ));
        }
        wp_send_json_success(array(
            'version'     => $version,
            'changed'     => true,
            'in_progress' => $in_prog,
            'cursor'      => $cursor,
            'total'       => $total,
            'bracket'     => $cup['bracket'] ?? null,
            'pairs'       => array_slice($all_pairs, 0, $cursor),
        ));
    }

    // Cursor mode — return only newly revealed pairs since client_cur
    $new_pairs = $cursor > $client_cur
        ? array_slice($all_pairs, $client_cur, $cursor - $client_cur)
        : array();

    // Return bracket whenever draw is complete so viewers can detect finish
    // regardless of whether they have received all pairs yet
    $is_complete = !$in_prog && $cursor >= $total && $total > 0;

    wp_send_json_success(array(
        'version'     => $version,
        'changed'     => $cursor !== $client_cur || $version !== $client_ver,
        'in_progress' => $in_prog,
        'cursor'      => $cursor,
        'total'       => $total,
        'complete'    => $is_complete,
        'bracket'     => $is_complete ? ($cup['bracket'] ?? null) : null,
        'pairs'       => $new_pairs,
    ));
}

// ── AJAX: Advance draw cursor (draw master only) ───────────────────────────────
add_action('wp_ajax_nipgl_cup_advance_cursor',        'nipgl_ajax_cup_advance_cursor');
add_action('wp_ajax_nopriv_nipgl_cup_advance_cursor', 'nipgl_ajax_cup_advance_cursor');
function nipgl_ajax_cup_advance_cursor() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_cup_nonce')) {
        wp_send_json_error('Session expired.');
    }
    if (!nipgl_cup_check_draw_auth()) wp_send_json_error('Unauthorised.');

    $cup_id = sanitize_key($_POST['cup_id'] ?? '');
    if (!$cup_id) wp_send_json_error('Missing cup_id');

    $cup    = get_option('nipgl_cup_' . $cup_id, array());
    $total  = count($cup['draw_pairs'] ?? array());
    $cursor = intval($cup['pairs_cursor'] ?? 0);

    if ($cursor < $total) {
        $cup['pairs_cursor'] = $cursor + 1;
    }
    // Mark draw complete when all pairs revealed
    if ($cup['pairs_cursor'] >= $total) {
        $cup['draw_in_progress'] = false;
    }
    update_option('nipgl_cup_' . $cup_id, $cup);
    wp_send_json_success(array('cursor' => $cup['pairs_cursor'], 'total' => $total));
}

// ── AJAX: Perform draw (admin or passphrase-authenticated) ─────────────────────
add_action('wp_ajax_nipgl_cup_perform_draw',        'nipgl_ajax_cup_perform_draw');
add_action('wp_ajax_nopriv_nipgl_cup_perform_draw', 'nipgl_ajax_cup_perform_draw');
function nipgl_ajax_cup_perform_draw() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_cup_nonce')) {
        wp_send_json_error('Session expired — please refresh the page and try again.');
    }
    if (!nipgl_cup_check_draw_auth()) wp_send_json_error('Unauthorised — please authenticate first.');

    $cup_id = sanitize_key($_POST['cup_id'] ?? '');
    if (!$cup_id) wp_send_json_error('Missing cup_id');

    $cup = get_option('nipgl_cup_' . $cup_id, array());
    if (empty($cup['entries'])) wp_send_json_error('No entries configured for this cup.');

    // Prevent double-draw if another authenticated user triggered it first
    if (!empty($cup['draw_version']) && (int) $cup['draw_version'] > 0) {
        wp_send_json_error('The draw has already been performed.');
    }

    $result = nipgl_cup_perform_draw($cup_id, $cup);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $cup_updated = get_option('nipgl_cup_' . $cup_id, array());
    wp_send_json_success(array(
        'bracket' => $cup_updated['bracket'],
        'pairs'   => $cup_updated['draw_pairs'],
    ));
}

// ── Draw logic ─────────────────────────────────────────────────────────────────
/**
 * Randomly seeds a single-elimination bracket from the entry list.
 * Delegates geometry and assembly to nipgl_draw_build_bracket() in nipgl-draw.php.
 * Cup home-conflict rule: max 1 home match per club per round.
 *
 * @param string $cup_id
 * @param array  $cup  Full cup option array
 * @return true|WP_Error
 */
function nipgl_cup_perform_draw($cup_id, $cup) {
    $entries = array_values(array_filter(array_map('trim', $cup['entries'] ?? array())));
    if (count($entries) < 2) return new WP_Error('too_few', 'At least 2 entries required.');

    shuffle($entries);

    $numbered = array();
    foreach ($entries as $i => $name) {
        $numbered[] = array('name' => $name, 'draw_num' => $i + 1);
    }

    $stored_rounds = $cup['rounds'] ?? array();
    $r2_label      = $stored_rounds[1] ?? 'Round 1 Draw';

    $result = nipgl_draw_build_bracket($numbered, array(
        'get_club'      => 'nipgl_draw_cup_club',
        // Cup rule: max 1 home match per club per round
        'home_at_limit' => function($club, $counts) { return ($counts[$club] ?? 0) >= 1; },
        'stored_rounds' => $stored_rounds,
        'dates'         => $cup['dates'] ?? array(),
        'r2_label'      => $r2_label,
        'game_nums'     => false,
    ));

    if (!$result) return new WP_Error('too_few', 'At least 2 entries required.');

    $cup['bracket'] = array(
        'title'   => $cup['title'] ?? 'Cup',
        'rounds'  => $result['rounds'],
        'dates'   => $cup['dates'] ?? array(),
        'matches' => $result['matches'],
    );
    $cup['draw_pairs']       = $result['pairs'];
    $cup['draw_version']     = intval($cup['draw_version'] ?? 0) + 1;
    $cup['pairs_cursor']     = 0;
    $cup['draw_in_progress'] = true;

    // Integrity check — bail if the option would exceed safe WP option size (~800KB)
    if (strlen(serialize($cup)) > 800000) {
        return new WP_Error('too_large', 'Bracket data exceeds safe storage limit. Reduce the number of entries.');
    }

    update_option('nipgl_cup_' . $cup_id, $cup);
    return true;
}

// ── AJAX: Update bracket results from Google Sheets CSV ───────────────────────
// (Optional: admin can paste a CSV URL in cup settings and results are merged in)
add_action('wp_ajax_nipgl_cup_sync_results', 'nipgl_ajax_cup_sync_results');
function nipgl_ajax_cup_sync_results() {
    check_ajax_referer('nipgl_cup_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $cup_id = sanitize_key($_POST['cup_id'] ?? '');
    $cup    = get_option('nipgl_cup_' . $cup_id, array());
    $csv    = $cup['csv_url'] ?? '';

    if (!$csv) wp_send_json_error('No CSV URL configured for this cup.');

    $result = nipgl_cup_merge_csv_results($cup_id, $cup, $csv);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    wp_send_json_success(array('bracket' => get_option('nipgl_cup_' . $cup_id)['bracket']));
}

/**
 * Parse NIPGL cup bracket CSV (same column layout as the spreadsheet):
 * Col A = draw number, Col B = R1, Col C = R2, Col D = QF, Col E = SF, Col F = Final
 * Each team name in a column = that team reached that round.
 * We map this back onto the stored bracket.
 */
function nipgl_cup_merge_csv_results($cup_id, &$cup, $csv_url) {
    $response = wp_remote_get(
        admin_url('admin-ajax.php') . '?action=nipgl_csv&url=' . urlencode($csv_url),
        array('timeout' => 15)
    );
    // Actually: use the proxy for consistency
    $response = wp_remote_get($csv_url, array('timeout' => 15, 'user-agent' => 'Mozilla/5.0'));
    if (is_wp_error($response)) return $response;
    $body = wp_remote_retrieve_body($response);
    if (!$body) return new WP_Error('empty', 'Empty CSV response.');

    $rows = array_map('str_getcsv', explode("\n", trim($body)));
    // Find round headers row (contains ROUND 1, ROUND 2 etc)
    $round_row = -1;
    foreach ($rows as $ri => $row) {
        foreach ($row as $cell) {
            if (stripos(trim($cell), 'ROUND') !== false || stripos(trim($cell), 'FINAL') !== false || stripos(trim($cell), 'SEMI') !== false) {
                $round_row = $ri;
                break 2;
            }
        }
    }

    if (!isset($cup['bracket'])) return new WP_Error('no_bracket', 'No bracket drawn yet.');

    // Build a map: draw_num => team_name (from stored bracket)
    $draw_map = array();
    foreach (($cup['bracket']['matches'][0] ?? array()) as $match) {
        if ($match['draw_num_home'] && $match['home']) $draw_map[$match['draw_num_home']] = $match['home'];
        if ($match['draw_num_away'] && $match['away']) $draw_map[$match['draw_num_away']] = $match['away'];
    }

    // Map each data row: col A = draw num, subsequent cols = teams at each round
    // Advancing team in col X = winner of that round
    // We infer scores: if team A is in R2 col, they won R1 match. Score stored as 'W'/'L' markers
    // For now we mark round advancement and let future score entry flesh out scores
    $num_rounds = count($cup['bracket']['rounds'] ?? array());
    foreach ($rows as $ri => $row) {
        if ($ri <= $round_row) continue;
        $draw_num = isset($row[0]) ? intval($row[0]) : 0;
        if (!$draw_num) continue;
        // For each round col (B=1, C=2, D=3, E=4, F=5 = indices 1..5)
        for ($col = 1; $col <= $num_rounds && $col < count($row); $col++) {
            $team = isset($row[$col]) ? trim($row[$col]) : '';
            if (!$team) continue;
            $round_idx = $col - 1;
            if (!isset($cup['bracket']['matches'][$round_idx])) continue;
            // Find the match in this round that this team belongs to
            nipgl_cup_mark_winner($cup['bracket']['matches'], $round_idx, $draw_num, $team);
        }
    }

    update_option('nipgl_cup_' . $cup_id, $cup);
    return true;
}

/**
 * Mark the winner of a match in round $round_idx for team identified by draw number.
 * The draw number traces back to round 1 to find which match slot this team is in.
 */
function nipgl_cup_mark_winner(&$all_matches, $round_idx, $draw_num, $team) {
    if ($round_idx === 0) {
        foreach ($all_matches[0] as &$m) {
            if ($m['draw_num_home'] == $draw_num) { $m['home'] = $team; return; }
            if ($m['draw_num_away'] == $draw_num) { $m['away'] = $team; return; }
        }
        return;
    }
    // For later rounds: find the match where home or away = this team
    foreach ($all_matches[$round_idx] as &$m) {
        if (nipgl_cup_normalise($m['home']) === nipgl_cup_normalise($team) ||
            nipgl_cup_normalise($m['away']) === nipgl_cup_normalise($team)) {
            return; // already set
        }
        // Fill in the next available slot
        if (!$m['home']) { $m['home'] = $team; return; }
        if (!$m['away']) { $m['away'] = $team; return; }
    }
}

function nipgl_cup_normalise($name) {
    return strtolower(trim(preg_replace('/\s+/', ' ', $name ?? '')));
}

// ── Handle cup save and draw-reset on admin_init (before any output) ──────────
add_action('admin_init', 'nipgl_cup_handle_admin_actions');
function nipgl_cup_handle_admin_actions() {
    if (!current_user_can('manage_options')) return;

    // ── Save cup form ─────────────────────────────────────────────────────────
    if (isset($_POST['nipgl_cup_save_nonce']) &&
        wp_verify_nonce($_POST['nipgl_cup_save_nonce'], 'nipgl_cup_save')) {

        $cup_id  = sanitize_key($_POST['cup_id_original'] ?? ''); // original ID (empty = new)
        $new_id  = sanitize_key($_POST['cup_id']          ?? '');
        if (!$new_id) {
            // Let the page re-render with an error — add a query arg and bail
            wp_redirect(admin_url('admin.php?page=nipgl-cups&action=edit&edit=' . urlencode($cup_id) . '&cup_error=missing_id'));
            exit;
        }

        $existing = get_option('nipgl_cup_' . ($cup_id ?: $new_id), array());

        $entries_raw = sanitize_textarea_field($_POST['nipgl_cup_entries'] ?? '');
        $entries     = array_values(array_filter(array_map('trim', explode("\n", $entries_raw))));

        $rounds_raw  = sanitize_textarea_field($_POST['nipgl_cup_rounds'] ?? '');
        $rounds      = array_values(array_filter(array_map('trim', explode("\n", $rounds_raw))));
        if (empty($rounds)) $rounds = nipgl_cup_default_rounds(count($entries));

        $dates_raw   = sanitize_textarea_field($_POST['nipgl_cup_dates'] ?? '');
        $dates       = array_values(array_filter(array_map('trim', explode("\n", $dates_raw))));

        $cup_data = array_merge($existing, array(
            'title'   => sanitize_text_field($_POST['nipgl_cup_title'] ?? ''),
            'entries' => $entries,
            'rounds'  => $rounds,
            'dates'   => $dates,
            'csv_url' => esc_url_raw($_POST['nipgl_cup_csv'] ?? ''),
        ));

        // If ID changed, delete old key
        if ($cup_id && $cup_id !== $new_id) {
            delete_option('nipgl_cup_' . $cup_id);
        }
        update_option('nipgl_cup_' . $new_id, $cup_data);
        wp_redirect(admin_url('admin.php?page=nipgl-cups&edit=' . $new_id . '&saved=1'));
        exit;
    }

    // ── Reset draw ────────────────────────────────────────────────────────────
    if (isset($_GET['reset_draw']) && isset($_GET['edit'])) {
        $cup_id = sanitize_key($_GET['edit']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nipgl_cup_reset_' . $cup_id)) {
            $cup = get_option('nipgl_cup_' . $cup_id, array());
            $cup['bracket']      = null;
            $cup['draw_pairs']       = array();
            $cup['draw_version']     = 0;
            $cup['pairs_cursor']     = 0;
            $cup['draw_in_progress'] = false;
            update_option('nipgl_cup_' . $cup_id, $cup);
            wp_redirect(admin_url('admin.php?page=nipgl-cups&edit=' . $cup_id . '&saved=1'));
            exit;
        }
    }

    // ── Delete cup ────────────────────────────────────────────────────────────
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $del_id = sanitize_key($_GET['id']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nipgl_cup_delete_' . $del_id)) {
            delete_option('nipgl_cup_' . $del_id);
            wp_redirect(admin_url('admin.php?page=nipgl-cups&deleted=1'));
            exit;
        }
    }

    // ── Save draw passphrase ──────────────────────────────────────────────────
    if (isset($_POST['nipgl_draw_passphrase_nonce']) &&
        wp_verify_nonce($_POST['nipgl_draw_passphrase_nonce'], 'nipgl_draw_passphrase_save')) {
        $raw = strtolower(trim(sanitize_text_field($_POST['nipgl_draw_passphrase'] ?? '')));
        update_option('nipgl_draw_passphrase', $raw !== '' ? hash('sha256', $raw) : '');
        $valid_speeds = array('0.5', '0.75', '1.0', '1.5', '2.0');
        $speed = $_POST['nipgl_draw_speed'] ?? '1.0';
        update_option('nipgl_draw_speed', in_array($speed, $valid_speeds) ? $speed : '1.0');
        wp_redirect(admin_url('admin.php?page=nipgl-cups&passphrase_saved=1'));
        exit;
    }
}


function nipgl_cups_register_submenu() {
    add_submenu_page(
        'nipgl-scorecards',
        'Cups',
        'Cups',
        'manage_options',
        'nipgl-cups',
        'nipgl_cups_admin_page'
    );
}

function nipgl_cups_admin_page() {
    $action = $_GET['action'] ?? '';
    $cup_id = sanitize_key($_GET['edit'] ?? '');

    if ($cup_id && ($action === 'edit' || isset($_GET['edit']))) {
        nipgl_cup_edit_page($cup_id);
        return;
    }
    if (isset($_GET['action']) && $_GET['action'] === 'new') {
        nipgl_cup_edit_page('');
        return;
    }
    nipgl_cups_list_page();
}

function nipgl_cups_list_page() {
    // Find all nipgl_cup_* options
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'nipgl_cup_%' ORDER BY option_name"
    );
    $cups = array();
    foreach ($rows as $row) {
        $id = substr($row->option_name, strlen('nipgl_cup_'));
        $val = maybe_unserialize($row->option_value);
        if (is_array($val) && isset($val['title'])) {
            $cups[$id] = $val;
        }
    }
    ?>
    <div class="wrap">
    <h1>Cup Management</h1>

    <?php if (isset($_GET['saved'])): ?>
      <div class="notice notice-success is-dismissible"><p>Cup saved.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
      <div class="notice notice-success is-dismissible"><p>Cup deleted.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['passphrase_saved'])): ?>
      <div class="notice notice-success is-dismissible"><p>Draw passphrase updated.</p></div>
    <?php endif; ?>

    <h2>Draw Passphrase</h2>
    <p>Required to trigger a cup draw from the public page. Leave blank to hide the draw button from the public entirely (wp-admin draw only).</p>
    <form method="post">
      <?php wp_nonce_field('nipgl_draw_passphrase_save', 'nipgl_draw_passphrase_nonce'); ?>
      <table class="form-table" style="max-width:600px">
        <tr>
          <th scope="row"><label for="nipgl_draw_passphrase">Draw Passphrase</label></th>
          <td>
            <input type="text" id="nipgl_draw_passphrase" name="nipgl_draw_passphrase"
                   value=""
                   placeholder="<?php echo get_option('nipgl_draw_passphrase','') !== '' ? '(passphrase set — enter new value to change)' : 'e.g. word.word.word'; ?>"
                   autocomplete="off" autocapitalize="none" spellcheck="false"
                   style="width:300px">
            <?php if (get_option('nipgl_draw_passphrase','') !== ''): ?>
              <span style="color:#2a7a2a;margin-left:8px">✅ Passphrase is set</span>
            <?php else: ?>
              <span style="color:#999;margin-left:8px">Not set</span>
            <?php endif; ?>
            <p class="description">Three-word format recommended: <code>word.word.word</code>. Stored as a SHA-256 hash. Leave blank to clear.</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="nipgl_draw_speed">Draw Animation Speed</label></th>
          <td>
            <select id="nipgl_draw_speed" name="nipgl_draw_speed" style="width:200px">
              <?php
              $current_speed = (float) get_option('nipgl_draw_speed', 1.0);
              $speeds = array(
                  '0.5' => 'Fast (0.5×)',
                  '0.75' => 'Fairly fast (0.75×)',
                  '1.0' => 'Normal (1×) — default',
                  '1.5' => 'Slow (1.5×)',
                  '2.0' => 'Very slow (2×)',
              );
              foreach ($speeds as $val => $label):
              ?>
              <option value="<?php echo $val; ?>" <?php selected((string)$current_speed, $val); ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <p class="description">Controls the cadence of the live draw animation. Normal = 2.6s per match.</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Save Passphrase', 'secondary'); ?>
    </form>

    <hr>
    <h2 style="display:flex;align-items:center;gap:16px">Cups
      <a href="<?php echo admin_url('admin.php?page=nipgl-cups&action=new'); ?>" class="button button-primary">+ New Cup</a>
    </h2>

    <?php if (empty($cups)): ?>
      <p>No cups configured yet. <a href="<?php echo admin_url('admin.php?page=nipgl-cups&action=new'); ?>">Create your first cup →</a></p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:800px">
      <thead><tr><th>Cup</th><th>Entries</th><th>Draw</th><th>Shortcode</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cups as $id => $cup): ?>
      <tr>
        <td><strong><?php echo esc_html($cup['title'] ?? $id); ?></strong></td>
        <td><?php echo count($cup['entries'] ?? array()); ?></td>
        <td><?php echo ($cup['draw_version'] ?? 0) > 0 ? '✅ Drawn' : '⏳ Not drawn'; ?></td>
        <td><code>[nipgl_cup id="<?php echo esc_html($id); ?>"]</code></td>
        <td style="white-space:nowrap">
          <a href="<?php echo admin_url('admin.php?page=nipgl-cups&edit=' . urlencode($id)); ?>" class="button button-small">Edit</a>
          <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=nipgl-cups&action=delete&id=' . urlencode($id)), 'nipgl_cup_delete_' . $id); ?>"
             class="button button-small button-link-delete"
             onclick="return confirm('Delete this cup and all its data?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
    <?php
}

function nipgl_cup_edit_page($cup_id) {
    $cup    = $cup_id ? get_option('nipgl_cup_' . $cup_id, array()) : array();
    $is_new = !$cup_id;
    $nonce  = wp_create_nonce('nipgl_cup_nonce');

    // Show error if ID was missing on save attempt
    $cup_error = $_GET['cup_error'] ?? '';

    $entries_str = implode("\n", $cup['entries'] ?? array());
    $rounds_str  = implode("\n", $cup['rounds']  ?? array());
    $dates_str   = implode("\n", $cup['dates']   ?? array());
    $drawn       = ($cup['draw_version'] ?? 0) > 0;
    ?>
    <div class="wrap">
    <h1><?php echo $is_new ? 'New Cup' : 'Edit Cup: ' . esc_html($cup['title'] ?? $cup_id); ?></h1>
    <p><a href="<?php echo admin_url('admin.php?page=nipgl-cups'); ?>">← Back to cups</a></p>

    <?php if (isset($_GET['saved'])): ?>
      <div class="notice notice-success is-dismissible"><p>Cup saved.</p></div>
    <?php endif; ?>
    <?php if ($cup_error === 'missing_id'): ?>
      <div class="notice notice-error"><p>Cup ID is required.</p></div>
    <?php endif; ?>

    <form method="post">
      <?php wp_nonce_field('nipgl_cup_save', 'nipgl_cup_save_nonce'); ?>
      <!-- Pass the original ID so the save handler knows if the ID changed -->
      <input type="hidden" name="cup_id_original" value="<?php echo esc_attr($cup_id); ?>">

      <table class="form-table" style="max-width:760px">
        <tr>
          <th><label for="nipgl_cup_title">Cup Name</label></th>
          <td><input type="text" id="nipgl_cup_title" name="nipgl_cup_title"
                     value="<?php echo esc_attr($cup['title'] ?? ''); ?>"
                     placeholder="e.g. NIPGBL Senior Cup 2025" class="regular-text" style="width:360px"></td>
        </tr>
        <tr>
          <th><label for="nipgl_cup_id_field">Cup ID</label></th>
          <td>
            <input type="text" id="nipgl_cup_id_field" name="cup_id"
                   value="<?php echo esc_attr($cup_id); ?>"
                   placeholder="e.g. senior-cup-2025" class="regular-text"
                   <?php echo $drawn ? 'readonly' : ''; ?>>
            <p class="description">Used in the shortcode: <code>[nipgl_cup id="…"]</code>. Lowercase letters, numbers, hyphens only.<?php echo $drawn ? ' Cannot change after draw.' : ''; ?></p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_cup_entries">Entered Teams</label></th>
          <td>
            <textarea id="nipgl_cup_entries" name="nipgl_cup_entries" rows="14"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="One team per line&#10;Ards&#10;Ballymena A&#10;Belmont A&#10;…"><?php echo esc_textarea($entries_str); ?></textarea>
            <p class="description">One team per line. The draw will randomly seed these into the bracket. Currently: <strong><?php echo count($cup['entries'] ?? array()); ?></strong> entries.</p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_cup_rounds">Round Names</label></th>
          <td>
            <textarea id="nipgl_cup_rounds" name="nipgl_cup_rounds" rows="6"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="One round name per line&#10;Round 1&#10;Quarter Final&#10;Semi-Final&#10;Final"><?php echo esc_textarea($rounds_str); ?></textarea>
            <p class="description">One per line, in order from first round to final. Leave blank to auto-generate based on entry count.</p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_cup_dates">Round Dates</label></th>
          <td>
            <textarea id="nipgl_cup_dates" name="nipgl_cup_dates" rows="6"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="One date per line (optional)&#10;01/05/2025&#10;05/06/2025&#10;…"><?php echo esc_textarea($dates_str); ?></textarea>
            <p class="description">Optional. One date per line aligned with the round names above.</p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_cup_csv">Results CSV URL</label></th>
          <td>
            <input type="text" id="nipgl_cup_csv" name="nipgl_cup_csv"
                   value="<?php echo esc_attr($cup['csv_url'] ?? ''); ?>"
                   placeholder="https://docs.google.com/spreadsheets/…/pub?output=csv"
                   class="regular-text" style="width:480px">
            <p class="description">Optional. Published Google Sheets CSV of the bracket. If provided, results can be synced from the sheet.</p>
          </td>
        </tr>
      </table>

      <?php submit_button($is_new ? 'Create Cup' : 'Save Cup'); ?>
    </form>

    <?php if (!$is_new): ?>
    <hr>
    <h2>Draw</h2>
    <?php if ($drawn): ?>
      <p>✅ Draw performed (version <?php echo intval($cup['draw_version']); ?>). The bracket has been published.</p>
      <p>
        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=nipgl-cups&edit=' . $cup_id . '&reset_draw=1'), 'nipgl_cup_reset_' . $cup_id); ?>"
           class="button button-secondary"
           onclick="return confirm('Reset the draw? This will clear the bracket and allow a fresh draw. Existing results will be lost.')">
          🔄 Reset Draw &amp; Redo
        </a>
      </p>
    <?php else: ?>
      <p>No draw performed yet. Use the <strong>🎲 Perform Draw</strong> button on the public page, or perform it here:</p>
      <button class="button button-primary nipgl-cup-admin-draw-btn-inline"
              data-cup-id="<?php echo esc_attr($cup_id); ?>"
              data-nonce="<?php echo esc_attr($nonce); ?>">
        🎲 Perform Draw Now
      </button>
      <p id="nipgl-draw-inline-msg" style="margin-top:8px;color:#0a3622;display:none"></p>
    <?php endif; ?>

    <?php if (!empty($cup['csv_url'])): ?>
    <hr>
    <h2>Sync Results from Sheet</h2>
    <p>Pull team advancement data from the Google Sheet to update the bracket display.</p>
    <button class="button button-secondary" id="nipgl-cup-sync-btn"
            data-cup-id="<?php echo esc_attr($cup_id); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>">
      🔄 Sync Results Now
    </button>
    <span id="nipgl-sync-msg" style="margin-left:12px;font-size:13px;color:#0a3622;display:none"></span>
    <?php endif; ?>

    <hr>
    <h2>Shortcode</h2>
    <p>Add this shortcode to any page to display the cup bracket:</p>
    <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block">[nipgl_cup id="<?php echo esc_html($cup_id); ?>" title="<?php echo esc_attr($cup['title'] ?? ''); ?>"]</pre>

    <?php endif; ?>
    </div>
    <?php
}
