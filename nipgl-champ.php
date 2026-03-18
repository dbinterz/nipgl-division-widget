<?php
/**
 * NIPGL National Championships - v6.4.29
 *
 * Single-elimination bracket competitions for singles, pairs, triples, fours.
 * Based on the cup draw system with two key differences:
 *   1. Entries are individual players/pairs with a home club (not club teams).
 *   2. Max 6 entries from the same club can be at home in any one round.
 *   3. Large fields are split into balanced sections (up to 4); section winners
 *      feed a cross-section Final Stage.
 *
 * Shortcode: [nipgl_champ id="singles-2025"]
 */

// ── Enqueue assets ─────────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'nipgl_champ_enqueue');
function nipgl_champ_enqueue() {
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'nipgl_champ')) return;
    wp_enqueue_style('nipgl-saira',  'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null);
    wp_enqueue_style('nipgl-widget', plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-widget.css', array('nipgl-saira'), NIPGL_VERSION);
    wp_enqueue_style('nipgl-champ',  plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-champ.css',  array('nipgl-widget'), NIPGL_VERSION);
    wp_enqueue_script('nipgl-champ', plugin_dir_url(NIPGL_PLUGIN_FILE) . 'nipgl-champ.js',   array(), NIPGL_VERSION, true);
    if (!wp_script_is('nipgl-widget', 'enqueued')) {
        wp_localize_script('nipgl-champ', 'nipglData', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'badges'     => get_option('nipgl_badges',      array()),
            'clubBadges' => get_option('nipgl_club_badges', array()),
            'sponsors'   => get_option('nipgl_sponsors',    array()),
        ));
    }
    wp_localize_script('nipgl-champ', 'nipglChampData', array(
        'ajaxUrl'           => admin_url('admin-ajax.php'),
        'isAdmin'           => current_user_can('manage_options') ? 1 : 0,
        'scoreNonce'        => wp_create_nonce('nipgl_champ_score'),
        'drawPassphraseSet' => get_option('nipgl_draw_passphrase', '') !== '' ? 1 : 0,
        'champNonce'        => wp_create_nonce('nipgl_champ_nonce'),
        'drawSpeed'         => (float) get_option('nipgl_draw_speed', 1.0),
        'badges'            => get_option('nipgl_badges',      array()),
        'clubBadges'        => get_option('nipgl_club_badges', array()),
    ));
}

// ── AJAX: draw auth (reuses cup passphrase) ────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_champ_draw_auth', 'nipgl_ajax_champ_draw_auth');
add_action('wp_ajax_nipgl_champ_draw_auth',        'nipgl_ajax_champ_draw_auth');
function nipgl_ajax_champ_draw_auth() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_champ_nonce')) {
        wp_send_json_error('Session expired — please refresh and try again.');
    }
    $raw    = strtolower(trim(sanitize_text_field(wp_unslash($_POST['passphrase'] ?? ''))));
    $stored = get_option('nipgl_draw_passphrase', '');
    if ($stored === '') wp_send_json_error('No draw passphrase configured.');
    if (!hash_equals($stored, hash('sha256', $raw))) wp_send_json_error('Incorrect passphrase.');
    $token = wp_generate_password(32, false);
    set_transient('nipgl_draw_auth_' . $token, 1, HOUR_IN_SECONDS);
    wp_send_json_success(array('token' => $token));
}

function nipgl_champ_check_draw_auth() {
    $token = sanitize_text_field($_POST['draw_token'] ?? '');
    if ($token && get_transient('nipgl_draw_auth_' . $token)) return true;
    if (current_user_can('manage_options') && empty($_POST['draw_token'])) return true;
    return false;
}

// ── AJAX: save score ───────────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_champ_save_score', 'nipgl_ajax_champ_save_score');
function nipgl_ajax_champ_save_score() {
    check_ajax_referer('nipgl_champ_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id   = sanitize_key($_POST['champ_id']  ?? '');
    $section    = sanitize_key($_POST['section']   ?? 'main');
    $round_idx  = intval($_POST['round_idx']       ?? -1);
    $match_idx  = intval($_POST['match_idx']       ?? -1);
    $home_score = $_POST['home_score'] !== '' ? intval($_POST['home_score']) : null;
    $away_score = $_POST['away_score'] !== '' ? intval($_POST['away_score']) : null;

    if (!$champ_id || $round_idx < 0 || $match_idx < 0) wp_send_json_error('Invalid parameters');

    $champ = get_option('nipgl_champ_' . $champ_id, array());
    if (empty($champ)) wp_send_json_error('Championship not found');

    // Determine which bracket to update
    $bracket_key = ($section === 'final') ? 'final_bracket' : 'section_' . $section . '_bracket';
    $bracket = &$champ[$bracket_key];
    if (!isset($bracket['matches'][$round_idx][$match_idx])) wp_send_json_error('Match not found');

    $match = &$bracket['matches'][$round_idx][$match_idx];
    $match['home_score'] = $home_score;
    $match['away_score'] = $away_score;

    if ($home_score !== null && $away_score !== null && $home_score !== $away_score) {
        $winner      = $home_score > $away_score ? $match['home'] : $match['away'];
        $next_round  = $round_idx + 1;
        $this_game   = $match['game_num'] ?? null;

        if (isset($bracket['matches'][$next_round]) && $this_game) {
            // Find the next-round match whose prev_game_home or prev_game_away
            // equals this match's game_num — this is correct regardless of bracket position.
            $found = false;
            foreach ($bracket['matches'][$next_round] as $nm => &$next_match_ref) {
                if (($next_match_ref['prev_game_home'] ?? null) == $this_game) {
                    $next_match_ref['home']       = $winner;
                    $next_match_ref['home_score'] = null;
                    $found = true;
                    break;
                }
                if (($next_match_ref['prev_game_away'] ?? null) == $this_game) {
                    $next_match_ref['away']       = $winner;
                    $next_match_ref['away_score'] = null;
                    $found = true;
                    break;
                }
            }
            unset($next_match_ref);
            // Fallback for rounds that don't have prev_game annotations (shouldn't happen)
            if (!$found) {
                $fb_match = intval(floor($match_idx / 2));
                $fb_slot  = $match_idx % 2 === 0 ? 'home' : 'away';
                if (isset($bracket['matches'][$next_round][$fb_match])) {
                    $bracket['matches'][$next_round][$fb_match][$fb_slot]            = $winner;
                    $bracket['matches'][$next_round][$fb_match][$fb_slot . '_score'] = null;
                }
            }
        }
        // If this is a section bracket, try seeding the final stage (no-op if not ready)
        if ($section !== 'final') {
            nipgl_champ_try_seed_final($champ_id, $champ);
        }
    }

    update_option('nipgl_champ_' . $champ_id, $champ);
    wp_send_json_success(array('bracket' => $bracket));
}

/**
 * Extract qualifiers from a section bracket.
 * $q_per_section controls how many players qualify from this section:
 *   1 → section winner (final match must have a scored result)
 *   2 → both finalists (final match slots must be populated — SF results in)
 *   4 → all four semi-finalists (SF slots must be populated — QF results in)
 * Returns array of entry strings, or null if not yet ready.
 */
function nipgl_champ_get_section_qualifiers($bracket, $q_per_section) {
    $matches  = $bracket['matches'] ?? array();
    $n_rounds = count($matches);
    if ($n_rounds === 0) return null;

    if ($q_per_section === 1) {
        // Winner of the section final
        $final_match = reset($matches[$n_rounds - 1]);
        if (!$final_match) return null;
        $hs = $final_match['home_score']; $as = $final_match['away_score'];
        if ($hs === null || $as === null || $hs === $as) return null;
        return array($hs > $as ? $final_match['home'] : $final_match['away']);
    }

    if ($q_per_section === 2) {
        // Both finalists — populated in the Final match slots once SFs complete
        $final_match = reset($matches[$n_rounds - 1]);
        if (!$final_match || empty($final_match['home']) || empty($final_match['away'])) return null;
        return array($final_match['home'], $final_match['away']);
    }

    if ($q_per_section === 4) {
        // All four semi-finalists — populated in the SF round slots once QFs complete
        if ($n_rounds < 2) return null;
        $sf_round    = $matches[$n_rounds - 2];
        $qualifiers  = array();
        foreach ($sf_round as $m) {
            if (empty($m['home']) || empty($m['away'])) return null;
            $qualifiers[] = $m['home'];
            $qualifiers[] = $m['away'];
        }
        return count($qualifiers) === 4 ? $qualifiers : null;
    }

    return null;
}

/**
 * Called after every section score save. Collects qualifiers from all sections
 * and triggers the Final Stage draw once all 4 qualifiers are known.
 * Number of qualifiers per section: 4/n_sections (4 sec→1, 2 sec→2, 1 sec→4).
 */
function nipgl_champ_try_seed_final($champ_id, &$champ) {
    if (isset($champ['final_bracket'])) return; // already seeded

    $sections   = $champ['sections'] ?? array();
    $n_sections = count($sections);
    if ($n_sections < 1) return;

    $q_per_section = intval(4 / $n_sections); // 1, 2, or 4

    $all_qualifiers = array();
    foreach ($sections as $idx => $sec) {
        $bracket = $champ['section_' . $idx . '_bracket'] ?? null;
        if (!$bracket) return; // section not yet drawn
        $q = nipgl_champ_get_section_qualifiers($bracket, $q_per_section);
        if ($q === null) return; // this section not ready yet
        $all_qualifiers = array_merge($all_qualifiers, $q);
    }

    // All 4 qualifiers known — perform the final stage draw
    nipgl_champ_perform_final_draw($champ_id, $champ, $all_qualifiers);
}

// ── AJAX: poll ─────────────────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_champ_poll',        'nipgl_ajax_champ_poll');
add_action('wp_ajax_nopriv_nipgl_champ_poll', 'nipgl_ajax_champ_poll');
function nipgl_ajax_champ_poll() {
    $champ_id   = sanitize_key($_POST['champ_id'] ?? '');
    $section    = sanitize_key($_POST['section']  ?? 'main');
    $client_ver = intval($_POST['version']        ?? 0);
    $client_cur = intval($_POST['cursor']         ?? -1);
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ = get_option('nipgl_champ_' . $champ_id, array());

    $ver_key    = ($section === 'final') ? 'final_draw_version'    : 'section_' . $section . '_draw_version';
    $cursor_key = ($section === 'final') ? 'final_pairs_cursor'    : 'section_' . $section . '_pairs_cursor';
    $pairs_key  = ($section === 'final') ? 'final_draw_pairs'      : 'section_' . $section . '_draw_pairs';
    $prog_key   = ($section === 'final') ? 'final_draw_in_progress': 'section_' . $section . '_draw_in_progress';
    $brkt_key   = ($section === 'final') ? 'final_bracket'         : 'section_' . $section . '_bracket';

    $version   = intval($champ[$ver_key]    ?? 0);
    $cursor    = intval($champ[$cursor_key] ?? 0);
    $in_prog   = !empty($champ[$prog_key]);
    $all_pairs = $champ[$pairs_key] ?? array();
    $total     = count($all_pairs);

    if ($in_prog && $cursor >= $total && $total > 0) {
        $champ[$prog_key] = false;
        $in_prog = false;
        update_option('nipgl_champ_' . $champ_id, $champ);
    }

    if ($client_cur < 0) {
        if ($version === $client_ver) {
            wp_send_json_success(array('version' => $version, 'changed' => false, 'in_progress' => $in_prog, 'cursor' => $cursor, 'total' => $total));
        }
        wp_send_json_success(array('version' => $version, 'changed' => true, 'in_progress' => $in_prog, 'cursor' => $cursor, 'total' => $total, 'bracket' => $champ[$brkt_key] ?? null, 'pairs' => array_slice($all_pairs, 0, $cursor)));
    }

    $new_pairs   = $cursor > $client_cur ? array_slice($all_pairs, $client_cur, $cursor - $client_cur) : array();
    $is_complete = !$in_prog && $cursor >= $total && $total > 0;

    wp_send_json_success(array(
        'version'     => $version,
        'changed'     => $cursor !== $client_cur || $version !== $client_ver,
        'in_progress' => $in_prog,
        'cursor'      => $cursor,
        'total'       => $total,
        'complete'    => $is_complete,
        'bracket'     => $is_complete ? ($champ[$brkt_key] ?? null) : null,
        'pairs'       => $new_pairs,
    ));
}

// ── AJAX: advance cursor ───────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_champ_advance_cursor',        'nipgl_ajax_champ_advance_cursor');
add_action('wp_ajax_nopriv_nipgl_champ_advance_cursor', 'nipgl_ajax_champ_advance_cursor');
function nipgl_ajax_champ_advance_cursor() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_champ_nonce')) wp_send_json_error('Session expired.');
    if (!nipgl_champ_check_draw_auth()) wp_send_json_error('Unauthorised.');

    $champ_id = sanitize_key($_POST['champ_id'] ?? '');
    $section  = sanitize_key($_POST['section']  ?? 'main');
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ      = get_option('nipgl_champ_' . $champ_id, array());
    $cursor_key = ($section === 'final') ? 'final_pairs_cursor'     : 'section_' . $section . '_pairs_cursor';
    $pairs_key  = ($section === 'final') ? 'final_draw_pairs'       : 'section_' . $section . '_draw_pairs';
    $prog_key   = ($section === 'final') ? 'final_draw_in_progress' : 'section_' . $section . '_draw_in_progress';

    $total  = count($champ[$pairs_key] ?? array());
    $cursor = intval($champ[$cursor_key] ?? 0);
    if ($cursor < $total) $champ[$cursor_key] = $cursor + 1;
    if ($champ[$cursor_key] >= $total) $champ[$prog_key] = false;

    update_option('nipgl_champ_' . $champ_id, $champ);
    wp_send_json_success(array('cursor' => $champ[$cursor_key], 'total' => $total));
}

// ── AJAX: perform draw ─────────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_champ_perform_draw',        'nipgl_ajax_champ_perform_draw');
add_action('wp_ajax_nopriv_nipgl_champ_perform_draw', 'nipgl_ajax_champ_perform_draw');
function nipgl_ajax_champ_perform_draw() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'nipgl_champ_nonce')) wp_send_json_error('Session expired — please refresh.');
    if (!nipgl_champ_check_draw_auth()) wp_send_json_error('Unauthorised — please authenticate first.');

    $champ_id = sanitize_key($_POST['champ_id'] ?? '');
    $section  = sanitize_key($_POST['section']  ?? '0');
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ = get_option('nipgl_champ_' . $champ_id, array());
    if (empty($champ['entries'])) wp_send_json_error('No entries configured.');

    $ver_key = ($section === 'final') ? 'final_draw_version' : 'section_' . $section . '_draw_version';
    if (!empty($champ[$ver_key]) && (int) $champ[$ver_key] > 0) wp_send_json_error('Draw already performed for this section.');

    $result = nipgl_champ_perform_draw($champ_id, $champ, $section);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    $updated = get_option('nipgl_champ_' . $champ_id, array());
    $brkt_key   = ($section === 'final') ? 'final_bracket' : 'section_' . $section . '_bracket';
    $pairs_key  = ($section === 'final') ? 'final_draw_pairs' : 'section_' . $section . '_draw_pairs';
    wp_send_json_success(array('bracket' => $updated[$brkt_key], 'pairs' => $updated[$pairs_key]));
}

// ── Draw logic ─────────────────────────────────────────────────────────────────

/**
 * Extract the club from an entry string.
 * Entry format: "Player Name(s), Club Name"
 * Returns lowercased club name for comparison.
 */
function nipgl_champ_entry_club($entry) {
    $parts = array_map('trim', explode(',', $entry, 2));
    return strtolower($parts[1] ?? '');
}

/**
 * Return the home-game limit for a given club (lowercased).
 * Default is 6. Multi-green clubs (stored as "Club: 2" lines in champ['multi_green'])
 * get limit = 6 * greens.
 */
function nipgl_champ_home_limit($club, $multi_green) {
    $club = strtolower(trim($club));
    foreach ($multi_green as $line) {
        $parts = array_map('trim', explode(':', $line, 2));
        if (strtolower($parts[0]) === $club && isset($parts[1])) {
            $greens = max(1, intval($parts[1]));
            return 6 * $greens;
        }
    }
    return 6;
}

/**
 * Try to separate same-club pairs in a list of numbered entries.
 * After the initial shuffle, scans for consecutive pairs where both entries
 * share a club and swaps one of them with a later entry from a different club.
 * Best-effort: if separation is impossible (e.g. all entries from same club)
 * it leaves the draw as-is rather than looping indefinitely.
 *
 * @param array $entries  Array of ['name'=>..., 'draw_num'=>...] — modified in place.
 */
function nipgl_champ_separate_clubs(&$entries) {
    $n = count($entries);
    for ($i = 0; $i < $n - 1; $i += 2) {
        $ca = nipgl_champ_entry_club($entries[$i]['name']);
        $cb = nipgl_champ_entry_club($entries[$i + 1]['name']);
        if ($ca !== $cb) continue; // already different clubs, fine
        // Find the nearest later entry with a different club to swap with
        $swapped = false;
        for ($j = $i + 2; $j < $n; $j++) {
            $cj = nipgl_champ_entry_club($entries[$j]['name']);
            if ($cj !== $ca) {
                // Swap entries[$i+1] with entries[$j]
                list($entries[$i + 1], $entries[$j]) = array($entries[$j], $entries[$i + 1]);
                $swapped = true;
                break;
            }
        }
        // If no swap possible, leave it — unavoidable same-club pairing
    }
}

/**
 * Try to separate same-club adjacent pairs in $r2_slots (bye entries only).
 * Null slots (prelim winner placeholders) are ignored — those pairings are
 * acceptable as per requirements.
 */
function nipgl_champ_separate_r2_slots(&$slots, &$from_game) {
    $n = count($slots);
    for ($i = 0; $i < $n - 1; $i += 2) {
        $a = $slots[$i];
        $b = $slots[$i + 1];
        // Only check pairs where both are known bye entries (not null placeholders)
        if (!$a || !$b) continue;
        $ca = nipgl_champ_entry_club($a['name']);
        $cb = nipgl_champ_entry_club($b['name']);
        if ($ca !== $cb) continue;
        // Same club — try to swap $b with a later known bye entry from different club
        for ($j = $i + 2; $j < $n; $j++) {
            if (!$slots[$j]) continue; // skip null slots
            $cj = nipgl_champ_entry_club($slots[$j]['name']);
            if ($cj !== $ca) {
                list($slots[$i + 1], $slots[$j])       = array($slots[$j], $slots[$i + 1]);
                list($from_game[$i + 1], $from_game[$j]) = array($from_game[$j], $from_game[$i + 1]);
                break;
            }
        }
    }
}

/**
 * Perform the draw for one section (or the final stage).
 *
 * Key difference from cup: instead of max 1 home match per club per round,
 * championships allow up to 6 home matches per club per round.
 * We count home assignments and swap home/away only when a club would exceed 6.
 */
function nipgl_champ_perform_draw($champ_id, $champ, $section = '0') {
    if ($section === 'final') {
        return nipgl_champ_perform_final_draw($champ_id, $champ);
    }

    $section_idx = intval($section);
    $sections    = $champ['sections'] ?? array();
    if (!isset($sections[$section_idx])) return new WP_Error('no_section', 'Section not found.');

    $raw_entries = $sections[$section_idx]['entries'] ?? array();
    if (count($raw_entries) < 2) return new WP_Error('too_few', 'At least 2 entries required.');

    shuffle($raw_entries);
    $n           = count($raw_entries);
    $multi_green = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));
    // Per-section dates override global dates
    $dates = !empty($champ['section_' . $section_idx . '_dates'])
        ? array_values(array_filter(array_map('trim', explode("\n", $champ['section_' . $section_idx . '_dates']))))
        : ($champ['dates'] ?? array());

    $numbered = array();
    foreach ($raw_entries as $i => $entry) {
        $numbered[] = array('name' => $entry, 'draw_num' => $i + 1);
    }

    $stored_rounds = $champ['rounds'] ?? array();
    $r2_label      = $stored_rounds[1] ?? 'Round 1 Draw';

    $result = nipgl_draw_build_bracket($numbered, array(
        'get_club'      => 'nipgl_champ_entry_club',
        // Champ rule: max 6 (or multi-green override) home matches per club per round
        'home_at_limit' => function($club, $counts) use ($multi_green) {
            return ($counts[$club] ?? 0) >= nipgl_champ_home_limit($club, $multi_green);
        },
        'separate_prelim' => 'nipgl_champ_separate_clubs',
        'separate_r2'     => 'nipgl_champ_separate_r2_slots',
        'stored_rounds'   => $stored_rounds,
        'dates'           => $dates,
        'r2_label'        => $r2_label,
        'game_nums'       => true,
    ));

    if (!$result) return new WP_Error('too_few', 'At least 2 entries required.');

    $bracket_key = 'section_' . $section_idx . '_bracket';
    $champ[$bracket_key] = array(
        'title'   => ($champ['title'] ?? 'Championship') . ' — Section ' . ($sections[$section_idx]['label'] ?? ($section_idx + 1)),
        'rounds'  => $result['rounds'],
        'dates'   => $dates,
        'matches' => $result['matches'],
    );
    $champ['section_' . $section_idx . '_draw_pairs']       = $result['pairs'];
    $champ['section_' . $section_idx . '_draw_version']     = intval($champ['section_' . $section_idx . '_draw_version'] ?? 0) + 1;
    $champ['section_' . $section_idx . '_pairs_cursor']     = 0;
    $champ['section_' . $section_idx . '_draw_in_progress'] = true;

    // Integrity check — bail if the option would exceed safe WP option size (~800KB)
    if (strlen(serialize($champ)) > 800000) {
        return new WP_Error('too_large', 'Bracket data exceeds safe storage limit. Reduce the number of entries.');
    }

    update_option('nipgl_champ_' . $champ_id, $champ);
    return true;
}

/**
 * Draw the Final Stage.
 * $qualifiers may be passed directly (from try_seed_final) or derived from
 * completed section brackets (admin "Draw Final Stage Now" button path).
 */
function nipgl_champ_perform_final_draw($champ_id, &$champ, $qualifiers = null) {
    $sections    = $champ['sections'] ?? array();
    $n_sections  = count($sections);
    $multi_green = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));

    if ($qualifiers === null) {
        // Admin manual trigger — derive qualifiers using the same logic as try_seed_final
        $q_per_section  = $n_sections > 0 ? intval(4 / $n_sections) : 1;
        $qualifiers = array();
        foreach ($sections as $idx => $sec) {
            $bracket = $champ['section_' . $idx . '_bracket'] ?? null;
            if (!$bracket) return new WP_Error('section_incomplete', 'Section ' . ($sec['label'] ?? $idx) . ' has not been drawn yet.');
            $q = nipgl_champ_get_section_qualifiers($bracket, $q_per_section);
            if ($q === null) return new WP_Error('section_incomplete', 'Section ' . ($sec['label'] ?? $idx) . ' does not have enough results yet.');
            $qualifiers = array_merge($qualifiers, $q);
        }
    }

    if (count($qualifiers) < 2) return new WP_Error('too_few', 'Need at least 2 qualifiers for Final Stage.');

    shuffle($qualifiers);

    $numbered = array();
    foreach ($qualifiers as $i => $name) {
        $numbered[] = array('name' => $name, 'draw_num' => $i + 1);
    }

    $result = nipgl_draw_build_bracket($numbered, array(
        'get_club'      => 'nipgl_champ_entry_club',
        'home_at_limit' => function($club, $counts) use ($multi_green) {
            return ($counts[$club] ?? 0) >= nipgl_champ_home_limit($club, $multi_green);
        },
        'separate_prelim' => 'nipgl_champ_separate_clubs',
        'separate_r2'     => 'nipgl_champ_separate_r2_slots',
        // Force round names based on full bracket size so we get e.g. Semi-Final/Final
        // rather than Preliminary Round/Final when prelim_count > 0
        'stored_rounds'   => nipgl_draw_default_rounds(count($numbered)),
        'dates'           => array(),
        'r2_label'        => 'Final Stage Draw',
        'game_nums'       => true,
    ));

    if (!$result) return new WP_Error('too_few', 'Need at least 2 section winners for Final Stage.');

    $champ['final_bracket'] = array(
        'title'   => ($champ['title'] ?? 'Championship') . ' — Final Stage',
        'rounds'  => $result['rounds'],
        'dates'   => array(),
        'matches' => $result['matches'],
    );
    $champ['final_draw_pairs']       = $result['pairs'];
    $champ['final_draw_version']     = 1;
    $champ['final_pairs_cursor']     = 0;
    $champ['final_draw_in_progress'] = true;

    // Integrity check — bail if the option would exceed safe WP option size (~800KB)
    if (strlen(serialize($champ)) > 800000) {
        return new WP_Error('too_large', 'Bracket data exceeds safe storage limit. Reduce the number of entries.');
    }

    update_option('nipgl_champ_' . $champ_id, $champ);
    return true;
}

/**
 * Compute sections: <= 32 entries = 1 section, <= 64 = 2, > 64 = 4.
 * Entries shuffled randomly before splitting.
 */
function nipgl_champ_build_sections($entries) {
    $n = count($entries);
    $num_sections = $n <= 32 ? 1 : ($n <= 64 ? 2 : 4);
    $shuffled = $entries;
    shuffle($shuffled);
    $labels   = array('A','B','C','D');
    $size     = (int) ceil($n / $num_sections);
    $sections = array();
    $chunks   = array_chunk($shuffled, $size);
    foreach ($chunks as $i => $chunk) {
        $sections[] = array('label' => $labels[$i] ?? ('Sec'.($i+1)), 'entries' => $chunk);
    }
    return $sections;
}

// ── Admin: handle saves and resets ─────────────────────────────────────────────
add_action('admin_init', 'nipgl_champ_handle_admin_actions');
function nipgl_champ_handle_admin_actions() {
    if (!current_user_can('manage_options')) return;

    // Save championship
    if (isset($_POST['nipgl_champ_save_nonce']) && wp_verify_nonce($_POST['nipgl_champ_save_nonce'], 'nipgl_champ_save')) {
        $champ_id = sanitize_key($_POST['champ_id'] ?? '');
        if (!$champ_id) {
            wp_redirect(admin_url('admin.php?page=nipgl-champs&action=edit&champ_error=missing_id'));
            exit;
        }

        $existing = get_option('nipgl_champ_' . $champ_id, array());

        $entries_raw = sanitize_textarea_field(wp_unslash($_POST['nipgl_champ_entries'] ?? ''));
        $entries     = array_values(array_filter(array_map('trim', explode("\n", $entries_raw))));

        $dates_raw   = sanitize_textarea_field($_POST['nipgl_champ_dates'] ?? '');
        $dates       = array_values(array_filter(array_map('trim', explode("\n", $dates_raw))));
        $multi_green = sanitize_textarea_field(wp_unslash($_POST['nipgl_champ_multi_green'] ?? ''));

        // Rebuild sections only if entries changed and no draw in progress
        $draw_started = false;
        $existing_sections = $existing['sections'] ?? array();
        foreach ($existing_sections as $idx => $sec) {
            if (!empty($existing['section_' . $idx . '_draw_version'])) { $draw_started = true; break; }
        }

        $sections = $draw_started ? $existing_sections : nipgl_champ_build_sections($entries);

        $champ_data = array_merge($existing, array(
            'title'       => sanitize_text_field(wp_unslash($_POST['nipgl_champ_title'] ?? '')),
            'discipline'  => sanitize_text_field($_POST['nipgl_champ_discipline'] ?? 'singles'),
            'entries'     => $entries,
            'sections'    => $sections,
            'dates'       => $dates,
            'multi_green' => $multi_green,
        ));
        // Save per-section dates
        $sec_dates_post = $_POST['nipgl_champ_section_dates'] ?? array();
        foreach ($sections as $idx => $sec) {
            $key = 'section_' . $idx . '_dates';
            $champ_data[$key] = sanitize_textarea_field(wp_unslash($sec_dates_post[$idx] ?? ''));
        }

        update_option('nipgl_champ_' . $champ_id, $champ_data);
        wp_redirect(admin_url('admin.php?page=nipgl-champs&edit=' . $champ_id . '&saved=1'));
        exit;
    }

    // Reset draw
    if (isset($_GET['reset_draw']) && isset($_GET['edit'])) {
        $champ_id = sanitize_key($_GET['edit']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nipgl_champ_reset_' . $champ_id)) {
            $champ    = get_option('nipgl_champ_' . $champ_id, array());
            $sections = $champ['sections'] ?? array();
            foreach ($sections as $idx => $sec) {
                unset($champ['section_' . $idx . '_bracket'], $champ['section_' . $idx . '_draw_pairs'],
                      $champ['section_' . $idx . '_draw_version'], $champ['section_' . $idx . '_pairs_cursor'],
                      $champ['section_' . $idx . '_draw_in_progress']);
            }
            unset($champ['final_bracket'], $champ['final_draw_pairs'], $champ['final_draw_version'],
                  $champ['final_pairs_cursor'], $champ['final_draw_in_progress']);
            // Reshuffle sections
            if (!empty($champ['entries'])) {
                $champ['sections'] = nipgl_champ_build_sections($champ['entries']);
            }
            update_option('nipgl_champ_' . $champ_id, $champ);
            wp_redirect(admin_url('admin.php?page=nipgl-champs&edit=' . $champ_id . '&saved=1'));
            exit;
        }
    }

    // Delete
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $del_id = sanitize_key($_GET['id']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nipgl_champ_delete_' . $del_id)) {
            delete_option('nipgl_champ_' . $del_id);
            wp_redirect(admin_url('admin.php?page=nipgl-champs&deleted=1'));
            exit;
        }
    }
}

// ── Admin menu ─────────────────────────────────────────────────────────────────
function nipgl_champs_register_submenu() {
    add_submenu_page('nipgl-scorecards', 'Championships', '🏅 Championships', 'manage_options', 'nipgl-champs', 'nipgl_champs_admin_page');
}

function nipgl_champs_admin_page() {
    $action   = $_GET['action'] ?? '';
    $champ_id = sanitize_key($_GET['edit'] ?? '');
    if ($champ_id && ($action === 'edit' || isset($_GET['edit']))) { nipgl_champ_edit_page($champ_id); return; }
    if ($action === 'new') { nipgl_champ_edit_page(''); return; }
    nipgl_champs_list_page();
}

function nipgl_champs_list_page() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'nipgl_champ_%' ORDER BY option_name");
    $champs = array();
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('nipgl_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (is_array($val) && isset($val['title'])) $champs[$id] = $val;
    }
    ?>
    <div class="wrap">
    <h1>Championship Management</h1>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Championship saved.</p></div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success is-dismissible"><p>Championship deleted.</p></div><?php endif; ?>

    <h2 style="display:flex;align-items:center;gap:16px">Championships
      <a href="<?php echo admin_url('admin.php?page=nipgl-champs&action=new'); ?>" class="button button-primary">+ New Championship</a>
    </h2>
    <?php if (empty($champs)): ?>
      <p>No championships yet. <a href="<?php echo admin_url('admin.php?page=nipgl-champs&action=new'); ?>">Create one →</a></p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:900px">
      <thead><tr><th>Title</th><th>Discipline</th><th>Entries</th><th>Sections</th><th>Draw</th><th>Shortcode</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($champs as $id => $champ):
        $n_sections = count($champ['sections'] ?? array());
        $drawn = false;
        foreach (($champ['sections'] ?? array()) as $idx => $sec) {
            if (!empty($champ['section_' . $idx . '_draw_version'])) { $drawn = true; break; }
        }
      ?>
      <tr>
        <td><strong><?php echo esc_html($champ['title'] ?? $id); ?></strong></td>
        <td><?php echo esc_html(ucfirst($champ['discipline'] ?? 'singles')); ?></td>
        <td><?php echo count($champ['entries'] ?? array()); ?></td>
        <td><?php echo $n_sections; ?></td>
        <td><?php echo $drawn ? '✅ Drawn' : '⏳ Not drawn'; ?></td>
        <td><code>[nipgl_champ id="<?php echo esc_html($id); ?>"]</code></td>
        <td style="white-space:nowrap">
          <a href="<?php echo admin_url('admin.php?page=nipgl-champs&edit=' . urlencode($id)); ?>" class="button button-small">Edit</a>
          <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=nipgl-champs&action=delete&id=' . urlencode($id)), 'nipgl_champ_delete_' . $id); ?>"
             class="button button-small button-link-delete" onclick="return confirm('Delete this championship?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
    <?php
}

function nipgl_champ_edit_page($champ_id) {
    $champ   = $champ_id ? get_option('nipgl_champ_' . $champ_id, array()) : array();
    $is_new  = !$champ_id;
    $nonce   = wp_create_nonce('nipgl_champ_nonce');
    $drawn   = false;
    foreach (($champ['sections'] ?? array()) as $idx => $sec) {
        if (!empty($champ['section_' . $idx . '_draw_version'])) { $drawn = true; break; }
    }

    $entries_str = implode("\n", $champ['entries'] ?? array());
    $dates_str   = implode("\n", $champ['dates']   ?? array());
    $sections    = $champ['sections'] ?? array();
    $disciplines = array('singles' => 'Singles', 'pairs' => 'Pairs', 'triples' => 'Triples', 'fours' => 'Fours');
    ?>
    <div class="wrap">
    <h1><?php echo $is_new ? 'New Championship' : 'Edit: ' . esc_html($champ['title'] ?? $champ_id); ?></h1>
    <p><a href="<?php echo admin_url('admin.php?page=nipgl-champs'); ?>">← Back to championships</a></p>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>

    <form method="post">
      <?php wp_nonce_field('nipgl_champ_save', 'nipgl_champ_save_nonce'); ?>
      <input type="hidden" name="champ_id" value="<?php echo esc_attr($champ_id ?: sanitize_key(($_POST['nipgl_champ_title'] ?? 'champ') . '-' . date('Y'))); ?>">

      <table class="form-table" style="max-width:760px">
        <?php if ($is_new): ?>
        <tr>
          <th><label for="nipgl_champ_id_field">Championship ID</label></th>
          <td>
            <input type="text" id="nipgl_champ_id_field" name="champ_id"
                   value="<?php echo esc_attr($champ_id); ?>"
                   placeholder="e.g. singles-2025" class="regular-text">
            <p class="description">Used in the shortcode: <code>[nipgl_champ id="…"]</code>. Lowercase, hyphens only.</p>
          </td>
        </tr>
        <?php else: ?>
        <tr><th>Championship ID</th><td><code><?php echo esc_html($champ_id); ?></code>
          <p class="description">Shortcode: <code>[nipgl_champ id="<?php echo esc_attr($champ_id); ?>"]</code></p></td></tr>
        <?php endif; ?>
        <tr>
          <th><label for="nipgl_champ_title">Title</label></th>
          <td><input type="text" id="nipgl_champ_title" name="nipgl_champ_title"
                     value="<?php echo esc_attr($champ['title'] ?? ''); ?>"
                     placeholder="e.g. NIPGL Singles Championship 2025" class="regular-text" style="width:360px"></td>
        </tr>
        <tr>
          <th><label for="nipgl_champ_discipline">Discipline</label></th>
          <td>
            <select id="nipgl_champ_discipline" name="nipgl_champ_discipline">
              <?php foreach ($disciplines as $val => $label): ?>
              <option value="<?php echo $val; ?>" <?php selected($champ['discipline'] ?? 'singles', $val); ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_champ_entries">Entries</label></th>
          <td>
            <textarea id="nipgl_champ_entries" name="nipgl_champ_entries" rows="20"
                      style="width:400px;font-family:monospace;font-size:13px"
                      placeholder="One entry per line. Format: Player Name(s), Club&#10;J. Smith, Salisbury&#10;A. Jones / B. Brown, Ballymena"><?php echo esc_textarea($entries_str); ?></textarea>
            <p class="description">
              Format: <code>Player Name(s), Club</code> — the club is used for the 6-per-green draw constraint.<br>
              For pairs/triples/fours separate players with <code>/</code>: <code>Smith / Jones, Salisbury</code><br>
              Currently: <strong><?php echo count($champ['entries'] ?? array()); ?></strong> entries.
              <?php if ($drawn): ?><br><strong>Draw in progress — entries cannot be changed without resetting.</strong><?php endif; ?>
            </p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_champ_dates">Default Round Dates</label></th>
          <td>
            <textarea id="nipgl_champ_dates" name="nipgl_champ_dates" rows="5"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="One date per line (optional)&#10;01/05/2025&#10;05/06/2025"><?php echo esc_textarea($dates_str); ?></textarea>
            <p class="description">Used when no per-section dates are set. One date per line aligned with round order.</p>
          </td>
        </tr>
        <tr>
          <th><label for="nipgl_champ_multi_green">Multi-Green Clubs</label></th>
          <td>
            <textarea id="nipgl_champ_multi_green" name="nipgl_champ_multi_green" rows="4"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="Club: greens&#10;Ballymena: 2&#10;Salisbury: 2"><?php echo esc_textarea($champ['multi_green'] ?? ''); ?></textarea>
            <p class="description">Clubs with more than one green can host more than 6 home games per round.<br>
            Format: <code>Club Name: number_of_greens</code> — one per line. The draw limit becomes 6 × greens.</p>
          </td>
        </tr>
      </table>

      <?php if (!$is_new && !empty($sections)): ?>
      <h2>Per-Section Round Dates</h2>
      <p>Override default dates for individual sections — useful when different sections play on different nights.</p>
      <table class="form-table" style="max-width:760px">
        <?php foreach ($sections as $idx => $sec): ?>
        <tr>
          <th><label>Section <?php echo esc_html($sec['label']); ?> Dates</label></th>
          <td>
            <?php
            $sec_dates_key = 'section_' . $idx . '_dates';
            $sec_dates     = $champ[$sec_dates_key] ?? '';
            ?>
            <textarea name="nipgl_champ_section_dates[<?php echo $idx; ?>]" rows="4"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="Leave blank to use default dates&#10;01/05/2025&#10;05/06/2025"><?php echo esc_textarea($sec_dates); ?></textarea>
            <p class="description">One date per line per round. Leave blank to use default dates above.</p>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

      <?php submit_button($is_new ? 'Create Championship' : 'Save Championship'); ?>
    </form>

    <?php if (!empty($sections)): ?>
    <hr>
    <h2>Sections</h2>
    <p>
      <?php echo count($sections); ?> section<?php echo count($sections) > 1 ? 's' : ''; ?> —
      entries randomly allocated (re-randomised on each save unless draw is in progress).
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:20px">
    <?php foreach ($sections as $idx => $sec):
        $sec_drawn = !empty($champ['section_' . $idx . '_draw_version']);
    ?>
      <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:12px 16px;min-width:200px;max-width:300px">
        <strong>Section <?php echo esc_html($sec['label']); ?></strong>
        <span style="color:#666;font-size:12px"> — <?php echo count($sec['entries']); ?> entries</span>
        <?php if ($sec_drawn): ?> <span style="color:#2a7a2a;font-size:12px">✅ Drawn</span><?php endif; ?>
        <ol style="margin:8px 0 0;padding-left:20px;font-size:12px">
          <?php foreach ($sec['entries'] as $e): ?>
          <li><?php echo esc_html($e); ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$is_new): ?>
    <hr>
    <?php if (!$is_new && !empty($sections)):
      // ── Home games report (merged by date across sections) ──────────────────
      $multi_green_lines = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));

      // $by_date[date][club]       = confirmed home matches already drawn
      // $max_by_date[date][club]   = worst-case (confirmed + all potential prelim winners who'd be home)
      $by_date       = array();
      $max_by_date   = array();
      $date_to_round = array();
      $round_names   = array();

      // ── Pass 1: confirmed homes per date ─────────────────────────────────────
      foreach ($sections as $sidx => $sec) {
          $bk      = 'section_' . $sidx . '_bracket';
          $bracket = $champ[$bk] ?? null;
          if (!$bracket) continue;
          $sec_dates_key = 'section_' . $sidx . '_dates';
          $sec_dates = !empty($champ[$sec_dates_key])
              ? array_values(array_filter(array_map('trim', explode("\n", $champ[$sec_dates_key]))))
              : ($champ['dates'] ?? array());
          $bracket_rounds = $bracket['rounds'] ?? array();
          foreach ($bracket['matches'] as $ri => $round_matches) {
              $date_label = $sec_dates[$ri] ?? ('Round ' . ($ri + 1));
              $round_name = $bracket_rounds[$ri] ?? ('Round ' . ($ri + 1));
              if (!isset($date_to_round[$date_label]) || $ri < $date_to_round[$date_label]) {
                  $date_to_round[$date_label] = $ri;
              }
              $round_names[$ri] = $round_name;
              foreach ($round_matches as $m) {
                  if (empty($m['home'])) continue;
                  $parts = explode(',', $m['home'], 2);
                  $club  = strtolower(trim($parts[1] ?? $m['home']));
                  $by_date[$date_label][$club]     = ($by_date[$date_label][$club] ?? 0) + 1;
                  $max_by_date[$date_label][$club] = ($max_by_date[$date_label][$club] ?? 0) + 1;
              }
          }
      }

      // ── Pass 2: add potential prelim winners to max_by_date for R2 date ──────
      // For each prelim match where the winner would be at home in R2, both
      // the home and away entrant are possible winners — count each club once
      // per match (worst case: the club that would cause the most homes wins).
      foreach ($sections as $sidx => $sec) {
          $bk      = 'section_' . $sidx . '_bracket';
          $bracket = $champ[$bk] ?? null;
          if (!$bracket) continue;
          $sec_dates_key = 'section_' . $sidx . '_dates';
          $sec_dates = !empty($champ[$sec_dates_key])
              ? array_values(array_filter(array_map('trim', explode("\n", $champ[$sec_dates_key]))))
              : ($champ['dates'] ?? array());
          $all_matches = $bracket['matches'];
          $has_prelims = count($all_matches) > 1 &&
                         count($all_matches[0]) < count($all_matches[1]);
          $r1_idx  = $has_prelims ? 0 : -1;
          $r2_idx  = $has_prelims ? 1 : 0;
          if ($r1_idx < 0) continue; // no prelims = no unknown R2 homes
          $r2_date    = $sec_dates[$r2_idx] ?? ('Round ' . ($r2_idx + 1));
          $r2_matches = $all_matches[$r2_idx] ?? array();

          // Which game_nums feed into the HOME slot of an R2 match?
          $feeds_r2_home = array();
          foreach ($r2_matches as $r2m) {
              if (!empty($r2m['prev_game_home'])) $feeds_r2_home[] = $r2m['prev_game_home'];
          }

          // For each such prelim match, count any club that appears in it
          // (worst case: that club's entry wins and becomes home in R2)
          foreach ($all_matches[$r1_idx] as $r1m) {
              $game_num = $r1m['game_num'] ?? null;
              if (!$game_num || !in_array($game_num, $feeds_r2_home)) continue;
              $clubs_in_match = array();
              foreach (['home', 'away'] as $slot) {
                  if (!empty($r1m[$slot])) {
                      $parts = explode(',', $r1m[$slot], 2);
                      $clubs_in_match[] = strtolower(trim($parts[1] ?? $r1m[$slot]));
                  }
              }
              foreach (array_unique($clubs_in_match) as $club) {
                  $max_by_date[$r2_date][$club] = ($max_by_date[$r2_date][$club] ?? 0) + 1;
              }
          }
      }

      $parse_date = function($s) {
          if (preg_match('|^(\d{1,2})/(\d{1,2})/(\d{2,4})$|', trim($s), $m)) {
              $y = intval($m[3]); if ($y < 100) $y += 2000;
              return mktime(0, 0, 0, intval($m[2]), intval($m[1]), $y);
          }
          return false;
      };
      $sort_dates = function(&$arr) use ($parse_date) {
          uksort($arr, function($a, $b) use ($parse_date) {
              $ta = $parse_date($a); $tb = $parse_date($b);
              if ($ta !== false && $tb !== false) return $ta <=> $tb;
              return strcmp($a, $b);
          });
      };
      $sort_dates($by_date);
      $sort_dates($max_by_date);

      // Merge all date columns
      $all_cols = array_unique(array_merge(array_keys($by_date), array_keys($max_by_date)));
      usort($all_cols, function($a, $b) use ($parse_date) {
          $ta = $parse_date($a); $tb = $parse_date($b);
          if ($ta !== false && $tb !== false) return $ta <=> $tb;
          return strcmp($a, $b);
      });

      // Group columns by round for top header row
      $round_groups = array();
      $prev_ri = null;
      foreach ($all_cols as $col) {
          $ri   = $date_to_round[$col] ?? 99;
          $name = $round_names[$ri] ?? ('Round ' . ($ri + 1));
          if ($prev_ri !== $ri) $round_groups[] = array('name' => $name, 'cols' => array());
          $round_groups[count($round_groups)-1]['cols'][] = $col;
          $prev_ri = $ri;
      }

      if (!empty($by_date) || !empty($max_by_date)):
    ?>
    <h2>Home Games Report</h2>
    <p>Each cell shows <strong>confirmed / limit</strong> and in brackets <strong>(worst-case)</strong> if R2 prelims could increase the total. Red = over limit confirmed. Amber = worst-case would exceed limit.</p>
    <style>
    .nipgl-hr-table { font-size:11px; border-collapse:collapse; width:100%; }
    .nipgl-hr-table th, .nipgl-hr-table td { padding:3px 5px; border:1px solid #ddd; }
    .nipgl-hr-head1 th { background:#1a2e5a; color:#fff; text-align:center; }
    .nipgl-hr-head2 th { background:#243d78; color:#fff; text-align:center; font-size:10px; font-weight:600; }
    .nipgl-hr-table td { text-align:center; }
    .nipgl-hr-table td.club { text-align:left; font-weight:700; min-width:110px; }
    .nipgl-hr-over   { background:#f8d7da; color:#842029; font-weight:700; }
    .nipgl-hr-amber  { background:#fff3cd; color:#856404; font-weight:700; }
    .nipgl-hr-ok     { background:#d1e7dd; color:#0a3622; }
    .nipgl-hr-muted  { color:#ccc; }
    </style>
    <div style="overflow-x:auto;margin-bottom:24px">
    <table class="nipgl-hr-table">
      <thead>
        <tr class="nipgl-hr-head1">
          <th rowspan="2" style="text-align:left">Club</th>
          <th rowspan="2">Entries</th>
          <?php foreach ($round_groups as $grp): ?>
          <th colspan="<?php echo count($grp['cols']); ?>" style="border-left:2px solid rgba(255,255,255,.3)">
            <?php echo esc_html($grp['name']); ?>
          </th>
          <?php endforeach; ?>
        </tr>
        <tr class="nipgl-hr-head2">
          <?php foreach ($all_cols as $col): ?>
          <th style="border-left:1px solid rgba(255,255,255,.2)"><?php echo esc_html($col); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $all_clubs = array();
        foreach ($by_date as $d) foreach (array_keys($d) as $c) $all_clubs[$c] = true;
        foreach ($max_by_date as $d) foreach (array_keys($d) as $c) $all_clubs[$c] = true;
        ksort($all_clubs);
        // Build entry count per club
        $entry_counts = array();
        foreach ($sections as $sec) {
            foreach ($sec['entries'] as $entry) {
                $parts = explode(',', $entry, 2);
                $ec    = strtolower(trim($parts[1] ?? $entry));
                $entry_counts[$ec] = ($entry_counts[$ec] ?? 0) + 1;
            }
        }
        foreach (array_keys($all_clubs) as $club):
          $limit = nipgl_champ_home_limit($club, $multi_green_lines);
        ?>
        <tr>
          <td class="club"><?php echo esc_html(ucwords($club)); ?></td>
          <td class="nipgl-hr-muted"><?php echo $entry_counts[$club] ?? 0; ?></td>
          <?php foreach ($all_cols as $col):
            $actual = $by_date[$col][$club]   ?? 0;
            $max    = $max_by_date[$col][$club] ?? $actual;
            $has_max_extra = $max > $actual; // prelims could add more homes
            $over_actual = $actual > $limit;
            $over_max    = $max > $limit && !$over_actual;
            if ($actual === 0 && $max === 0):
          ?>
          <td class="nipgl-hr-muted">—</td>
          <?php elseif ($over_actual): ?>
          <td class="nipgl-hr-over"><?php echo $actual; ?>/<?php echo $limit;
            if ($has_max_extra) echo ' (' . $max . ')'; ?></td>
          <?php elseif ($over_max): ?>
          <td class="nipgl-hr-amber"><?php echo $actual; ?>/<?php echo $limit;
            echo ' (' . $max . ')'; ?></td>
          <?php elseif ($actual > 0): ?>
          <td class="nipgl-hr-ok"><?php echo $actual; ?>/<?php echo $limit;
            if ($has_max_extra) echo ' (' . $max . ')'; ?></td>
          <?php else: // max > 0 but actual = 0 — green if within limit, amber if over ?>
          <td class="<?php echo $max > $limit ? 'nipgl-hr-amber' : 'nipgl-hr-ok'; ?>" style="font-style:italic">(<?php echo $max; ?>)/<?php echo $limit; ?></td>
          <?php endif; ?>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; endif; ?>

    <h2>Draw</h2>

    <?php foreach ($sections as $idx => $sec):
        $sec_drawn = !empty($champ['section_' . $idx . '_draw_version']);
    ?>
    <h3>Section <?php echo esc_html($sec['label']); ?></h3>
    <?php if ($sec_drawn): ?>
      <p>✅ Draw performed.</p>
    <?php else: ?>
      <p>No draw yet.</p>
      <button class="button button-primary nipgl-champ-admin-draw-btn"
              data-champ-id="<?php echo esc_attr($champ_id); ?>"
              data-section="<?php echo $idx; ?>"
              data-nonce="<?php echo esc_attr($nonce); ?>">
        🎲 Draw Section <?php echo esc_html($sec['label']); ?> Now
      </button>
      <span class="nipgl-champ-draw-msg" style="margin-left:12px;font-size:13px;display:none"></span>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if (count($sections) > 1):
        $all_drawn = true;
        foreach ($sections as $idx => $sec) {
            $bracket_key = 'section_' . $idx . '_bracket';
            $bracket = $champ[$bracket_key] ?? null;
            if (!$bracket) { $all_drawn = false; break; }
            $last = end($bracket['matches']); $fm = reset($last);
            if (!$fm || $fm['home_score'] === null || $fm['away_score'] === null || $fm['home_score'] === $fm['away_score']) { $all_drawn = false; break; }
        }
        $final_drawn = !empty($champ['final_draw_version']);
    ?>
    <hr>
    <h3>Final Stage</h3>
    <?php if ($final_drawn): ?>
      <p>✅ Final Stage draw performed.</p>
    <?php elseif ($all_drawn): ?>
      <p>All sections complete — ready for Final Stage draw.</p>
      <button class="button button-primary nipgl-champ-admin-draw-btn"
              data-champ-id="<?php echo esc_attr($champ_id); ?>"
              data-section="final"
              data-nonce="<?php echo esc_attr($nonce); ?>">
        🎲 Draw Final Stage Now
      </button>
      <span class="nipgl-champ-draw-msg" style="margin-left:12px;font-size:13px;display:none"></span>
    <?php else: ?>
      <p style="color:#666">Available once all section finals are complete.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($drawn): ?>
    <hr>
    <p>
      <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=nipgl-champs&edit=' . $champ_id . '&reset_draw=1'), 'nipgl_champ_reset_' . $champ_id); ?>"
         class="button button-secondary"
         onclick="return confirm('Reset ALL draws? Brackets cleared, sections re-randomised.')">
        🔄 Reset Draw &amp; Redo
      </a>
    </p>
    <?php endif; ?>

    <hr>
    <h2>Shortcode</h2>
    <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block">[nipgl_champ id="<?php echo esc_html($champ_id); ?>"]</pre>
    <?php endif; ?>
    </div>
    <?php
}

// ── Shortcode ──────────────────────────────────────────────────────────────────
add_shortcode('nipgl_champ', 'nipgl_champ_shortcode');
function nipgl_champ_shortcode($atts) {
    $atts     = shortcode_atts(array(
        'id'           => '',
        'title'        => '',
        'sponsor_img'  => '',
        'sponsor_url'  => '',
        'sponsor_name' => '',
    ), $atts);
    $champ_id = sanitize_key($atts['id']);
    if (!$champ_id) return '<p>No championship ID provided.</p>';

    $champ    = get_option('nipgl_champ_' . $champ_id, array());
    $title    = !empty($atts['title']) ? $atts['title'] : ($champ['title'] ?? 'Championship');
    $sections = $champ['sections'] ?? array();
    $nonce           = wp_create_nonce('nipgl_champ_nonce');
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
    <div class="nipgl-champ-tabs-outer">
      <?php echo $primary_html; ?>
      <div class="nipgl-champ-section-tabs">
        <?php foreach ($sections as $idx => $sec): ?>
        <button class="nipgl-champ-section-tab<?php echo $idx === 0 ? ' active' : ''; ?>" data-section="<?php echo $idx; ?>">
          Section <?php echo esc_html($sec['label']); ?>
        </button>
        <?php endforeach; ?>
        <?php if (!empty($champ['final_bracket'])): ?>
        <button class="nipgl-champ-section-tab" data-section="final">🏆 Final Stage</button>
        <?php endif; ?>
      </div>

      <?php foreach ($sections as $idx => $sec):
        $bracket_key = 'section_' . $idx . '_bracket';
        $bracket     = $champ[$bracket_key] ?? null;
        $version     = intval($champ['section_' . $idx . '_draw_version'] ?? 0);
        $in_progress = !empty($champ['section_' . $idx . '_draw_in_progress']) && !$bracket;
        $bracket_json = $bracket ? wp_json_encode($bracket) : '';
        $is_admin = current_user_can('manage_options');
        $draw_passphrase_set = get_option('nipgl_draw_passphrase', '') !== '';
        $show_draw_btn = !$bracket && $draw_passphrase_set;
      ?>
      <div class="nipgl-champ-section-pane<?php echo $idx === 0 ? ' active' : ''; ?>" data-section="<?php echo $idx; ?>">
        <div class="nipgl-champ-wrap"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="<?php echo $idx; ?>"
             data-draw-version="<?php echo esc_attr($version); ?>"
             data-draw-in-progress="<?php echo ($in_progress) ? '1' : '0'; ?>"
             data-bracket="<?php echo esc_attr($bracket_json); ?>"
             data-sponsors="<?php echo $extra_json; ?>">

          <div class="nipgl-champ-header">
            <span class="nipgl-champ-title">🏅 <?php echo esc_html($title); ?> — Section <?php echo esc_html($sec['label']); ?></span>
            <?php if ($show_draw_btn): ?>
            <div class="nipgl-champ-header-actions">
              <button class="nipgl-champ-btn nipgl-champ-btn-ghost nipgl-champ-draw-login-btn">🔑 Login to Draw</button>
            </div>
            <?php elseif ($bracket): ?>
            <div class="nipgl-champ-header-actions nipgl-champ-post-draw-actions">
              <button class="nipgl-champ-btn nipgl-champ-btn-ghost nipgl-champ-print-btn">🖨 Print Draw</button>
            </div>
            <?php endif; ?>
          </div>

          <div class="nipgl-champ-tabs"><div class="nipgl-champ-tabs-inner"></div></div>

          <div class="nipgl-champ-bracket-outer">
            <?php if (!$bracket): ?>
            <div class="nipgl-champ-empty">
              <div class="nipgl-champ-empty-icon">🎲</div>
              <p><?php echo $is_admin ? 'No draw performed yet.' : 'The draw has not taken place yet. Check back soon!'; ?></p>
            </div>
            <?php endif; ?>
            <div class="nipgl-champ-bracket"></div>
          </div>

          <div class="nipgl-champ-status">
            <span class="nipgl-champ-status-dot<?php echo (!$bracket && $version == 0) ? ' live' : ''; ?>"></span>
            <span class="nipgl-champ-status-text">
              <?php if (!$bracket): ?>Waiting for draw…
              <?php else: ?><?php echo count($sec['entries']); ?> entries · Round 1: <?php echo esc_html($champ['dates'][0] ?? 'TBC'); ?>
              <?php endif; ?>
            </span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (!empty($champ['final_bracket'])):
        $fb           = $champ['final_bracket'];
        $fb_json      = wp_json_encode($fb);
        $fb_version   = intval($champ['final_draw_version'] ?? 0);
        $fb_in_prog   = !empty($champ['final_draw_in_progress']);
        $draw_passphrase_set = get_option('nipgl_draw_passphrase', '') !== '';
      ?>
      <div class="nipgl-champ-section-pane" data-section="final">
        <div class="nipgl-champ-wrap"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="final"
             data-draw-version="<?php echo esc_attr($fb_version); ?>"
             data-draw-in-progress="<?php echo $fb_in_prog ? '1' : '0'; ?>"
             data-bracket="<?php echo esc_attr($fb_json); ?>"
             data-sponsors="<?php echo $extra_json; ?>">

          <div class="nipgl-champ-header">
            <span class="nipgl-champ-title">🏆 <?php echo esc_html($title); ?> — Final Stage</span>
            <?php if ($draw_passphrase_set && !$fb): ?>
            <div class="nipgl-champ-header-actions">
              <button class="nipgl-champ-btn nipgl-champ-btn-ghost nipgl-champ-draw-login-btn">🔑 Login to Draw</button>
            </div>
            <?php elseif ($fb): ?>
            <div class="nipgl-champ-header-actions nipgl-champ-post-draw-actions">
              <button class="nipgl-champ-btn nipgl-champ-btn-ghost nipgl-champ-print-btn">🖨 Print Draw</button>
            </div>
            <?php endif; ?>
          </div>

          <div class="nipgl-champ-tabs"><div class="nipgl-champ-tabs-inner"></div></div>
          <div class="nipgl-champ-bracket-outer"><div class="nipgl-champ-bracket"></div></div>
          <div class="nipgl-champ-status">
            <span class="nipgl-champ-status-dot"></span>
            <span class="nipgl-champ-status-text">Final Stage</span>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <style>
    .nipgl-champ-section-tabs { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:0; background:#e8edf4; padding:8px 12px 0; }
    .nipgl-champ-section-tab  { padding:8px 18px; background:#fff; border:1px solid #d0d5e8; border-bottom:none; border-radius:4px 4px 0 0; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; color:#1a2e5a; }
    .nipgl-champ-section-tab.active { background:#1a4e6e; color:#fff; border-color:#1a4e6e; }
    .nipgl-champ-section-pane { display:none; }
    .nipgl-champ-section-pane.active { display:block; }
    </style>
    <?php
    return ob_get_clean();
}
