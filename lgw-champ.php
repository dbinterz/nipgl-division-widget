<?php
/**
 * LGW National Championships - v7.1.117
 *
 * Single-elimination bracket competitions for singles, pairs, triples, fours.
 * Based on the cup draw system with two key differences:
 *   1. Entries are individual players/pairs with a home club (not club teams).
 *   2. Max 6 entries from the same club can be at home in any one round.
 *   3. Large fields are split into balanced sections (up to 4); section winners
 *      feed a cross-section Final Stage.
 *
 * Shortcode: [lgw_champ id="singles-2025"]
 */

// ── Enqueue assets ─────────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'lgw_champ_enqueue');
function lgw_champ_enqueue() {
    global $post;
    if (!is_singular() || !is_a($post, 'WP_Post')) return;
    // Check raw post_content; also check the filtered content so page builders work
    $content = $post->post_content . ' ' . get_the_content(null, false, $post);
    if (!has_shortcode($content, 'lgw_champ') && !has_shortcode($content, 'lgw_finalists')) return;
    wp_enqueue_style('lgw-saira',  'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null);
    wp_enqueue_style('lgw-widget', plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-widget.css', array('lgw-saira'), LGW_VERSION);
    wp_enqueue_style('lgw-champ',  plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-champ.css',  array('lgw-widget'), LGW_VERSION);
    wp_enqueue_script('lgw-champ', plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-champ.js',   array(), LGW_VERSION, true);
    if (!wp_script_is('lgw-widget', 'enqueued')) {
        wp_localize_script('lgw-champ', 'lgwData', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'badges'     => get_option('lgw_badges',      array()),
            'clubBadges' => get_option('lgw_club_badges', array()),
            'sponsors'   => get_option('lgw_sponsors',    array()),
        ));
    }
    wp_localize_script('lgw-champ', 'lgwChampData', array(
        'ajaxUrl'           => admin_url('admin-ajax.php'),
        'isAdmin'           => current_user_can('manage_options') ? 1 : 0,
        'scoreNonce'        => wp_create_nonce('lgw_champ_score'),
        'drawPassphraseSet' => get_option('lgw_draw_passphrase', '') !== '' ? 1 : 0,
        'champNonce'        => wp_create_nonce('lgw_champ_nonce'),
        'searchNonce'       => wp_create_nonce('lgw_champ_search'),
        'drawSpeed'         => (float) get_option('lgw_draw_speed', 1.0),
        'badges'            => get_option('lgw_badges',      array()),
        'clubBadges'        => get_option('lgw_club_badges', array()),
    ));
}

// ── AJAX: draw auth (reuses cup passphrase) ────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_champ_draw_auth', 'lgw_ajax_champ_draw_auth');
add_action('wp_ajax_lgw_champ_draw_auth',        'lgw_ajax_champ_draw_auth');
function lgw_ajax_champ_draw_auth() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'lgw_champ_nonce')) {
        wp_send_json_error('Session expired — please refresh and try again.');
    }
    $raw    = strtolower(trim(sanitize_text_field(wp_unslash($_POST['passphrase'] ?? ''))));
    $stored = get_option('lgw_draw_passphrase', '');
    if ($stored === '') wp_send_json_error('No draw passphrase configured.');
    if (!hash_equals($stored, hash('sha256', $raw))) wp_send_json_error('Incorrect passphrase.');
    $token = wp_generate_password(32, false);
    set_transient('lgw_draw_auth_' . $token, 1, HOUR_IN_SECONDS);
    wp_send_json_success(array('token' => $token));
}

function lgw_champ_check_draw_auth() {
    $token = sanitize_text_field($_POST['draw_token'] ?? '');
    if ($token && get_transient('lgw_draw_auth_' . $token)) return true;
    if (current_user_can('manage_options') && empty($_POST['draw_token'])) return true;
    return false;
}

// ── AJAX: save score ───────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_champ_save_score', 'lgw_ajax_champ_save_score');
function lgw_ajax_champ_save_score() {
    check_ajax_referer('lgw_champ_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id   = sanitize_key($_POST['champ_id']  ?? '');
    $section    = sanitize_key($_POST['section']   ?? 'main');
    $round_idx  = intval($_POST['round_idx']       ?? -1);
    $match_idx  = intval($_POST['match_idx']       ?? -1);
    $home_score = $_POST['home_score'] !== '' ? intval($_POST['home_score']) : null;
    $away_score = $_POST['away_score'] !== '' ? intval($_POST['away_score']) : null;

    if (!$champ_id || $round_idx < 0 || $match_idx < 0) wp_send_json_error('Invalid parameters');

    $champ = get_option('lgw_champ_' . $champ_id, array());
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
            lgw_champ_try_seed_final($champ_id, $champ);
        }
    }

    // Reset: if both scores cleared, cascade-clear all downstream rounds
    if ($home_score === null && $away_score === null) {
        lgw_champ_cascade_reset($bracket, $round_idx, $match_idx);
        // If this is a section bracket, also unseed the final stage so it
        // can be re-drawn once corrected results are entered.
        if ($section !== 'final' && isset($champ['final_bracket'])) {
            unset($champ['final_bracket']);
        }
    }

    update_option('lgw_champ_' . $champ_id, $champ);
    wp_send_json_success(array(
        'bracket'       => $bracket,
        'final_bracket' => $champ['final_bracket'] ?? null,
    ));
}

/**
 * Recursively clear a match's winner from all subsequent rounds.
 * Starts at $round_idx/$match_idx and follows the winner slot forward
 * through every round until there is nothing left to clear.
 * Uses prev_game_home/prev_game_away annotations where available,
 * falls back to floor(match_idx/2) for un-annotated brackets.
 */
function lgw_champ_cascade_reset(&$bracket, $round_idx, $match_idx) {
    $all_matches = &$bracket['matches'];
    $next_round  = $round_idx + 1;
    if (!isset($all_matches[$next_round])) return;

    $this_game = $all_matches[$round_idx][$match_idx]['game_num'] ?? null;
    $found_nm  = null;
    $found_slot = null;

    if ($this_game) {
        foreach ($all_matches[$next_round] as $nm => &$nm_ref) {
            if (($nm_ref['prev_game_home'] ?? null) == $this_game) {
                $nm_ref['home']       = null;
                $nm_ref['home_score'] = null;
                $nm_ref['away_score'] = null; // score no longer valid without both teams
                $found_nm   = $nm;
                $found_slot = 'home';
                break;
            }
            if (($nm_ref['prev_game_away'] ?? null) == $this_game) {
                $nm_ref['away']       = null;
                $nm_ref['away_score'] = null;
                $nm_ref['home_score'] = null;
                $found_nm   = $nm;
                $found_slot = 'away';
                break;
            }
        }
        unset($nm_ref);
    }

    // Fallback: floor(match_idx/2) mapping
    if ($found_nm === null) {
        $fb_nm   = intval(floor($match_idx / 2));
        $fb_slot = $match_idx % 2 === 0 ? 'home' : 'away';
        if (isset($all_matches[$next_round][$fb_nm])) {
            $all_matches[$next_round][$fb_nm][$fb_slot]            = null;
            $all_matches[$next_round][$fb_nm][$fb_slot . '_score'] = null;
            $found_nm   = $fb_nm;
            $found_slot = $fb_slot;
        }
    }

    // Continue cascading if we found the next match
    if ($found_nm !== null) {
        lgw_champ_cascade_reset($bracket, $next_round, $found_nm);
    }
}

// ── AJAX: admin manual draw edit ──────────────────────────────────────────────
add_action('wp_ajax_lgw_champ_edit_match', 'lgw_ajax_champ_edit_match');
/**
 * Admin-only: swap one or both participants in a drawn match.
 *
 * POST params:
 *   champ_id    – championship slug
 *   section     – section index (int) or 'final'
 *   round_idx   – 0-based round index within the bracket
 *   match_idx   – 0-based match index within the round
 *   new_home    – new home entry string (or empty to leave unchanged)
 *   new_away    – new away entry string (or empty to leave unchanged)
 *   nonce       – lgw_champ_score nonce (re-uses same nonce key)
 *
 * Behaviour:
 *   1. Replace the specified participant(s) in the target match.
 *   2. Clear scores for the edited match.
 *   3. Cascade-reset all downstream rounds so winner placeholders
 *      that depended on this match's result are nulled out — those
 *      rounds must be scored again.
 *   4. If the changed match is in a section bracket, unseed the
 *      final_bracket so it can be re-drawn once section finals complete.
 *   5. Persist and return the updated bracket + final_bracket.
 */
function lgw_ajax_champ_edit_match() {
    check_ajax_referer('lgw_champ_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id  = sanitize_key($_POST['champ_id']  ?? '');
    $section   = sanitize_key($_POST['section']   ?? '0');
    $round_idx = intval($_POST['round_idx']        ?? -1);
    $match_idx = intval($_POST['match_idx']        ?? -1);
    $new_home  = sanitize_text_field(wp_unslash($_POST['new_home'] ?? ''));
    $new_away  = sanitize_text_field(wp_unslash($_POST['new_away'] ?? ''));

    if (!$champ_id || $round_idx < 0 || $match_idx < 0) {
        wp_send_json_error('Invalid parameters');
    }
    if ($new_home === '' && $new_away === '') {
        wp_send_json_error('No changes supplied');
    }

    $champ = get_option('lgw_champ_' . $champ_id, array());
    if (empty($champ)) wp_send_json_error('Championship not found');

    $bracket_key = ($section === 'final') ? 'final_bracket' : 'section_' . $section . '_bracket';
    $bracket = &$champ[$bracket_key];

    if (!isset($bracket['matches'][$round_idx][$match_idx])) {
        wp_send_json_error('Match not found');
    }

    $match = &$bracket['matches'][$round_idx][$match_idx];

    // Apply changes
    if ($new_home !== '') $match['home'] = $new_home;
    if ($new_away !== '') $match['away'] = $new_away;

    // Clear this match's scores — result is now unknown
    $match['home_score'] = null;
    $match['away_score'] = null;

    // Cascade-reset all downstream rounds that depended on this result
    lgw_champ_cascade_reset($bracket, $round_idx, $match_idx);

    // If a section bracket was changed, unseed the final stage
    if ($section !== 'final' && isset($champ['final_bracket'])) {
        unset($champ['final_bracket']);
        unset($champ['final_draw_pairs']);
        unset($champ['final_draw_version']);
        unset($champ['final_pairs_cursor']);
        unset($champ['final_draw_in_progress']);
    }

    update_option('lgw_champ_' . $champ_id, $champ);

    wp_send_json_success(array(
        'bracket'       => $bracket,
        'final_bracket' => $champ['final_bracket'] ?? null,
        'message'       => 'Match updated — downstream rounds have been cleared.',
    ));
}

// ── AJAX: rename an entry everywhere (entries list + all bracket slots) ────────
add_action('wp_ajax_lgw_champ_rename_entry', 'lgw_ajax_champ_rename_entry');
/**
 * Admin-only: rename an entry across the entries list and every bracket slot.
 *
 * POST params:
 *   champ_id  – championship slug
 *   old_name  – exact current entry string
 *   new_name  – corrected entry string
 *   nonce     – lgw_champ_score nonce
 *
 * Replaces all occurrences in:
 *   - champ['entries'] array
 *   - every section bracket (home/away in every match)
 *   - final_bracket (home/away in every match)
 * Does NOT reset any scores or cascade — this is a spelling-correction only.
 */
function lgw_ajax_champ_rename_entry() {
    check_ajax_referer('lgw_champ_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id = sanitize_key($_POST['champ_id']  ?? '');
    $old_name = sanitize_text_field(wp_unslash($_POST['old_name'] ?? ''));
    $new_name = sanitize_text_field(wp_unslash($_POST['new_name'] ?? ''));

    if (!$champ_id || $old_name === '' || $new_name === '') {
        wp_send_json_error('Missing parameters');
    }
    if ($old_name === $new_name) {
        wp_send_json_error('New name is identical to old name');
    }

    $champ = get_option('lgw_champ_' . $champ_id, array());
    if (empty($champ)) wp_send_json_error('Championship not found');

    $replaced = 0;

    // 1. Update the entries list
    foreach ($champ['entries'] as $i => $entry) {
        if ($entry === $old_name) {
            $champ['entries'][$i] = $new_name;
            $replaced++;
        }
    }

    // 2. Helper to rename in a bracket
    $rename_in_bracket = function(&$bracket) use ($old_name, $new_name, &$replaced) {
        if (empty($bracket['matches'])) return;
        foreach ($bracket['matches'] as $ri => &$round) {
            foreach ($round as $mi => &$match) {
                if (($match['home'] ?? '') === $old_name) { $match['home'] = $new_name; $replaced++; }
                if (($match['away'] ?? '') === $old_name) { $match['away'] = $new_name; $replaced++; }
            }
            unset($match);
        }
        unset($round);
    };

    // 3. Section brackets
    foreach (($champ['sections'] ?? array()) as $idx => $sec) {
        $key = 'section_' . $idx . '_bracket';
        if (!empty($champ[$key])) {
            $rename_in_bracket($champ[$key]);
        }
    }

    // 4. Final bracket
    if (!empty($champ['final_bracket'])) {
        $rename_in_bracket($champ['final_bracket']);
    }

    if ($replaced === 0) {
        wp_send_json_error('Entry "' . esc_html($old_name) . '" not found');
    }

    update_option('lgw_champ_' . $champ_id, $champ);

    wp_send_json_success(array(
        'message'  => 'Renamed "' . esc_html($old_name) . '" → "' . esc_html($new_name) . '" (' . $replaced . ' occurrences updated).',
        'replaced' => $replaced,
    ));
}

// ── AJAX: get draw entry list for edit dropdown ────────────────────────────────
add_action('wp_ajax_lgw_champ_get_entries', 'lgw_ajax_champ_get_entries');
/**
 * Returns the full entries list for a given championship section so the
 * admin edit UI can populate dropdowns without a page reload.
 */
function lgw_ajax_champ_get_entries() {
    check_ajax_referer('lgw_champ_score', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id    = sanitize_key($_POST['champ_id'] ?? '');
    $section_idx = intval($_POST['section'] ?? 0);

    $champ = get_option('lgw_champ_' . $champ_id, array());
    if (empty($champ)) wp_send_json_error('Championship not found');

    $sections = $champ['sections'] ?? array();
    if (!isset($sections[$section_idx])) wp_send_json_error('Section not found');

    wp_send_json_success(array(
        'entries' => $sections[$section_idx]['entries'] ?? array(),
    ));
}

// ── AJAX: championship search ──────────────────────────────────────────────────
add_action('wp_ajax_lgw_champ_search',        'lgw_ajax_champ_search');
add_action('wp_ajax_nopriv_lgw_champ_search', 'lgw_ajax_champ_search');
/**
 * Search all brackets of a championship for fixtures/results matching a player or club query.
 *
 * POST params:
 *   champ_id  – championship slug
 *   query     – search string (player name or club name)
 *   mode      – 'fixtures' (upcoming/undated matches) | 'results' (scored matches) | 'both'
 *   nonce     – lgw_champ_search nonce
 *
 * Returns array of match objects with extra keys: section_label, round_name, date, is_result.
 * For fixtures: includes future-dated OR undated matches where the entry appears.
 * For results:  includes matches that have both scores recorded.
 * A future-dated match that already has a result appears in BOTH modes.
 */
function lgw_ajax_champ_search() {
    $nonce    = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'lgw_champ_search')) {
        wp_send_json_error('Session expired — please refresh and try again.');
    }

    $champ_id = sanitize_key($_POST['champ_id'] ?? '');
    $query    = strtolower(trim(sanitize_text_field(wp_unslash($_POST['query'] ?? ''))));
    $mode     = sanitize_key($_POST['mode'] ?? 'fixtures');

    if (!$champ_id) wp_send_json_error('Missing championship ID.');
    if (strlen($query) < 2) wp_send_json_error('Search query too short — please enter at least 2 characters.');

    $champ = get_option('lgw_champ_' . $champ_id, array());
    if (empty($champ)) wp_send_json_error('Championship not found.');

    $sections       = $champ['sections'] ?? array();
    $today          = mktime(0, 0, 0);
    $results        = array();

    // Parse a date string in d/m/yy or d/m/yyyy format → timestamp, or false.
    $parse_date = function($s) {
        if (!$s) return false;
        if (preg_match('|^(\d{1,2})/(\d{1,2})/(\d{2,4})$|', trim($s), $m)) {
            $y = intval($m[3]); if ($y < 100) $y += 2000;
            return mktime(0, 0, 0, intval($m[2]), intval($m[1]), $y);
        }
        return false;
    };

    // Does the entry match the query (player name or club)?
    $entry_matches = function($entry) use ($query) {
        return $entry && (strpos(strtolower($entry), $query) !== false);
    };

    // Scan a bracket for matching matches.
    $scan_bracket = function($bracket, $section_label, $bracket_label) use (
        $query, $mode, $today, $parse_date, $entry_matches, &$results
    ) {
        $rounds       = $bracket['rounds']  ?? array();
        $matches_all  = $bracket['matches'] ?? array();
        $dates        = $bracket['dates']   ?? array();

        foreach ($matches_all as $ri => $round_matches) {
            $round_name = $rounds[$ri] ?? ('Round ' . ($ri + 1));
            $date_str   = $dates[$ri]  ?? '';
            $date_ts    = $parse_date($date_str);
            $is_future  = ($date_ts !== false) ? ($date_ts >= $today) : true; // undated = treat as upcoming

            foreach ($round_matches as $mi => $m) {
                if ($m['bye'] ?? false) continue;
                $home = $m['home'] ?? '';
                $away = $m['away'] ?? '';
                if (!$home && !$away) continue;

                $matched = $entry_matches($home) || $entry_matches($away);
                if (!$matched) continue;

                $hs = $m['home_score'] ?? null;
                $as = $m['away_score'] ?? null;
                $has_result = ($hs !== null && $hs !== '' && $as !== null && $as !== '');

                $include_as_fixture = false;
                $include_as_result  = false;

                if ($mode === 'fixtures' || $mode === 'both') {
                    // Include if future/undated — regardless of whether a result exists
                    if ($is_future) $include_as_fixture = true;
                }
                if ($mode === 'results' || $mode === 'both') {
                    if ($has_result) $include_as_result = true;
                }

                if (!$include_as_fixture && !$include_as_result) continue;

                $results[] = array(
                    'section'      => $section_label,
                    'bracket'      => $bracket_label,
                    'round'        => $round_name,
                    'date'         => $date_str,
                    'date_ts'      => $date_ts !== false ? $date_ts : PHP_INT_MAX,
                    'home'         => $home,
                    'away'         => $away,
                    'home_score'   => $has_result ? $hs : null,
                    'away_score'   => $has_result ? $as : null,
                    'has_result'   => $has_result,
                    'is_fixture'   => $include_as_fixture,
                    'is_result'    => $include_as_result,
                    'game_num'     => $m['game_num'] ?? null,
                );
            }
        }
    };

    // Scan section brackets
    foreach ($sections as $idx => $sec) {
        $bracket_key = 'section_' . $idx . '_bracket';
        if (!empty($champ[$bracket_key])) {
            $label = 'Section ' . ($sec['label'] ?? ($idx + 1));
            $scan_bracket($champ[$bracket_key], $label, $label);
        }
    }

    // Scan final bracket
    if (!empty($champ['final_bracket'])) {
        $scan_bracket($champ['final_bracket'], 'Final Stage', 'Final Stage');
    }

    // Sort by date ascending
    usort($results, function($a, $b) { return $a['date_ts'] <=> $b['date_ts']; });

    wp_send_json_success(array(
        'query'   => $query,
        'matches' => $results,
        'count'   => count($results),
        'title'   => $champ['title'] ?? $champ_id,
    ));
}

/**
 * Extract qualifiers from a section bracket.
 * $q_per_section controls how many players qualify from this section:
 *   1 → section winner (final match must have a scored result)
 *   2 → both finalists (final match slots must be populated — SF results in)
 *   4 → all four semi-finalists (SF slots must be populated — QF results in)
 * Returns array of entry strings, or null if not yet ready.
 */
function lgw_champ_get_section_qualifiers($bracket, $q_per_section) {
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
function lgw_champ_try_seed_final($champ_id, &$champ) {
    if (isset($champ['final_bracket'])) return; // already seeded

    $sections   = $champ['sections'] ?? array();
    $n_sections = count($sections);
    if ($n_sections < 1) return;

    $q_per_section = intval(4 / $n_sections); // 1, 2, or 4

    $all_qualifiers = array();
    foreach ($sections as $idx => $sec) {
        $bracket = $champ['section_' . $idx . '_bracket'] ?? null;
        if (!$bracket) return; // section not yet drawn
        $q = lgw_champ_get_section_qualifiers($bracket, $q_per_section);
        if ($q === null) return; // this section not ready yet
        $all_qualifiers = array_merge($all_qualifiers, $q);
    }

    // All 4 qualifiers known — perform the final stage draw
    lgw_champ_perform_final_draw($champ_id, $champ, $all_qualifiers);
}

// ── AJAX: poll ─────────────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_champ_poll',        'lgw_ajax_champ_poll');
add_action('wp_ajax_nopriv_lgw_champ_poll', 'lgw_ajax_champ_poll');
function lgw_ajax_champ_poll() {
    $champ_id   = sanitize_key($_POST['champ_id'] ?? '');
    $section    = sanitize_key($_POST['section']  ?? 'main');
    $client_ver = intval($_POST['version']        ?? 0);
    $client_cur = intval($_POST['cursor']         ?? -1);
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ = get_option('lgw_champ_' . $champ_id, array());

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
        update_option('lgw_champ_' . $champ_id, $champ);
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
add_action('wp_ajax_lgw_champ_advance_cursor',        'lgw_ajax_champ_advance_cursor');
add_action('wp_ajax_nopriv_lgw_champ_advance_cursor', 'lgw_ajax_champ_advance_cursor');
function lgw_ajax_champ_advance_cursor() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'lgw_champ_nonce')) wp_send_json_error('Session expired.');
    if (!lgw_champ_check_draw_auth()) wp_send_json_error('Unauthorised.');

    $champ_id = sanitize_key($_POST['champ_id'] ?? '');
    $section  = sanitize_key($_POST['section']  ?? 'main');
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ      = get_option('lgw_champ_' . $champ_id, array());
    $cursor_key = ($section === 'final') ? 'final_pairs_cursor'     : 'section_' . $section . '_pairs_cursor';
    $pairs_key  = ($section === 'final') ? 'final_draw_pairs'       : 'section_' . $section . '_draw_pairs';
    $prog_key   = ($section === 'final') ? 'final_draw_in_progress' : 'section_' . $section . '_draw_in_progress';

    $total  = count($champ[$pairs_key] ?? array());
    $cursor = intval($champ[$cursor_key] ?? 0);
    if ($cursor < $total) $champ[$cursor_key] = $cursor + 1;
    if ($champ[$cursor_key] >= $total) $champ[$prog_key] = false;

    update_option('lgw_champ_' . $champ_id, $champ);
    wp_send_json_success(array('cursor' => $champ[$cursor_key], 'total' => $total));
}

// ── AJAX: perform draw ─────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_champ_perform_draw',        'lgw_ajax_champ_perform_draw');
add_action('wp_ajax_nopriv_lgw_champ_perform_draw', 'lgw_ajax_champ_perform_draw');
function lgw_ajax_champ_perform_draw() {
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    if (!wp_verify_nonce($nonce, 'lgw_champ_nonce')) wp_send_json_error('Session expired — please refresh.');
    if (!lgw_champ_check_draw_auth()) wp_send_json_error('Unauthorised — please authenticate first.');

    $champ_id = sanitize_key($_POST['champ_id'] ?? '');
    $section  = sanitize_key($_POST['section']  ?? '0');
    if (!$champ_id) wp_send_json_error('Missing champ_id');

    $champ = get_option('lgw_champ_' . $champ_id, array());
    if (empty($champ['entries'])) wp_send_json_error('No entries configured.');

    $ver_key = ($section === 'final') ? 'final_draw_version' : 'section_' . $section . '_draw_version';
    if (!empty($champ[$ver_key]) && (int) $champ[$ver_key] > 0) wp_send_json_error('Draw already performed for this section.');

    $result = lgw_champ_perform_draw($champ_id, $champ, $section);
    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    $updated = get_option('lgw_champ_' . $champ_id, array());
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
function lgw_champ_entry_club($entry) {
    $parts = array_map('trim', explode(',', $entry, 2));
    return strtolower($parts[1] ?? '');
}

/**
 * Return the home-game limit for a given club (lowercased).
 * Default is 6. Multi-green clubs (stored as "Club: 2" lines in champ['multi_green'])
 * get limit = 6 * greens.
 */
function lgw_champ_home_limit($club, $multi_green) {
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
 * Separate same-club pairs in a list of numbered entries using a multi-pass
 * approach. Repeats until no same-club pairing remains or no further progress
 * can be made (impossible case, e.g. all entries from one club).
 *
 * Each pass scans all consecutive pairs; if a conflict is found it tries to
 * swap the second member with the best candidate from any later position —
 * preferring a swap that does not introduce a new conflict at the target pair.
 *
 * @param array $entries  Array of ['name'=>..., 'draw_num'=>...] — modified in place.
 */
function lgw_champ_separate_clubs(&$entries) {
    $n          = count($entries);
    $max_passes = $n * 2 + 2;

    for ($pass = 0; $pass < $max_passes; $pass++) {
        $conflict_found = false;

        for ($i = 0; $i < $n - 1; $i += 2) {
            $ca = lgw_champ_entry_club($entries[$i]['name']);
            $cb = lgw_champ_entry_club($entries[$i + 1]['name']);
            if ($ca !== $cb) continue;

            $conflict_found = true;

            // Search ALL positions (forward first, then backward) for the best swap.
            // Prefer a "clean" swap — one that won't create a conflict at the target's
            // current pair. Fall back to any different-club entry if no clean swap exists.
            $best_clean    = -1;
            $best_fallback = -1;

            $candidates = array();
            for ($j = $i + 2; $j < $n; $j++) $candidates[] = $j;
            for ($j = $i - 1; $j >= 0;  $j--) $candidates[] = $j;

            foreach ($candidates as $j) {
                $cj = lgw_champ_entry_club($entries[$j]['name']);
                if ($cj === $ca) continue;
                $pp = ($j % 2 === 0) ? $j + 1 : $j - 1;
                $creates_conflict = ($pp >= 0 && $pp < $n)
                    && lgw_champ_entry_club($entries[$pp]['name']) === $cb;
                if (!$creates_conflict && $best_clean === -1) $best_clean = $j;
                if ($best_fallback === -1)                    $best_fallback = $j;
                if ($best_clean !== -1) break;
            }

            $best = $best_clean !== -1 ? $best_clean : $best_fallback;
            if ($best !== -1) {
                list($entries[$i + 1], $entries[$best]) = array($entries[$best], $entries[$i + 1]);
            }
        }

        if (!$conflict_found) break;
    }
}

/**
 * Separate same-club adjacent pairs in $r2_slots (bye entries only) using
 * multi-pass logic matching lgw_champ_separate_clubs.
 * Null slots (prelim winner placeholders) are skipped — those pairings are
 * determined at play time and cannot be pre-separated.
 */
function lgw_champ_separate_r2_slots(&$slots, &$from_game) {
    $n          = count($slots);
    $max_passes = $n * 2 + 2;

    for ($pass = 0; $pass < $max_passes; $pass++) {
        $conflict_found = false;

        for ($i = 0; $i < $n - 1; $i += 2) {
            $a = $slots[$i];
            $b = $slots[$i + 1];
            if (!$a || !$b) continue; // null = prelim winner placeholder — skip

            $ca = lgw_champ_entry_club($a['name']);
            $cb = lgw_champ_entry_club($b['name']);
            if ($ca !== $cb) continue;

            $conflict_found = true;
            $best_clean    = -1;
            $best_fallback = -1;

            // Search forward then backward for the best swap candidate among filled slots
            $candidates = array();
            for ($j = $i + 2; $j < $n; $j++) $candidates[] = $j;
            for ($j = $i - 1; $j >= 0;  $j--) $candidates[] = $j;

            foreach ($candidates as $j) {
                if (!$slots[$j]) continue; // skip null placeholders
                $cj = lgw_champ_entry_club($slots[$j]['name']);
                if ($cj === $ca) continue;
                $pp = ($j % 2 === 0) ? $j + 1 : $j - 1;
                $creates_conflict = ($pp >= 0 && $pp < $n && $slots[$pp])
                    && lgw_champ_entry_club($slots[$pp]['name']) === $cb;
                if (!$creates_conflict && $best_clean === -1) $best_clean = $j;
                if ($best_fallback === -1)                    $best_fallback = $j;
                if ($best_clean !== -1) break;
            }

            $best = $best_clean !== -1 ? $best_clean : $best_fallback;
            if ($best !== -1) {
                list($slots[$i + 1],     $slots[$best])     = array($slots[$best],     $slots[$i + 1]);
                list($from_game[$i + 1], $from_game[$best]) = array($from_game[$best], $from_game[$i + 1]);
            }
        }

        if (!$conflict_found) break;
    }
}

/**
 * Perform the draw for one section (or the final stage).
 *
 * Key difference from cup: instead of max 1 home match per club per round,
 * championships allow up to 6 home matches per club per round.
 * We count home assignments and swap home/away only when a club would exceed 6.
 */
// ── Green bookings backfill ───────────────────────────────────────────────────

/**
 * Rebuild lgw_green_bookings from all existing drawn brackets.
 * Safe to call multiple times — always rebuilds from scratch.
 * Called automatically on init if bookings option doesn't exist yet,
 * and manually via admin button.
 */
function lgw_rebuild_green_bookings() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'lgw_champ_%' ORDER BY option_name",
        ARRAY_A
    );

    $bookings = array();

    foreach ($rows as $row) {
        $champ_id = substr($row['option_name'], strlen('lgw_champ_'));
        $champ    = maybe_unserialize($row['option_value']);
        if (!is_array($champ) || !isset($champ['title'])) continue;

        $sections = $champ['sections'] ?? array();

        // Section brackets
        foreach ($sections as $idx => $sec) {
            $bracket_key = 'section_' . $idx . '_bracket';
            $bracket     = $champ[$bracket_key] ?? null;
            if (!$bracket) continue;

            $sec_dates_key = 'section_' . $idx . '_dates';
            $dates = !empty($champ[$sec_dates_key])
                ? array_values(array_filter(array_map('trim', explode("
", $champ[$sec_dates_key]))))
                : ($champ['dates'] ?? array());

            foreach ($bracket['matches'] as $ri => $round_matches) {
                $date = $dates[$ri] ?? null;
                if (!$date) continue;
                foreach ($round_matches as $m) {
                    if (empty($m['home'])) continue;
                    $club = lgw_champ_entry_club($m['home']);
                    if (!$club) continue;
                    if (!isset($bookings[$date][$club])) {
                        $bookings[$date][$club] = array('count' => 0, 'champ_id' => $champ_id);
                    } elseif ($bookings[$date][$club]['champ_id'] !== $champ_id) {
                        // Slot contested — award to higher priority championship
                        $existing_rank = lgw_champ_priority_rank(
                            $bookings[$date][$club]['champ_id'],
                            get_option('lgw_champ_' . $bookings[$date][$club]['champ_id'], array())
                        );
                        $my_rank = lgw_champ_priority_rank($champ_id, $champ);
                        if ($my_rank >= $existing_rank) continue; // existing champ keeps it
                        $bookings[$date][$club] = array('count' => 0, 'champ_id' => $champ_id);
                    }
                    $bookings[$date][$club]['count']++;
                }
            }
        }

        // Final bracket (dates usually empty but handle if set)
        $final = $champ['final_bracket'] ?? null;
        if ($final) {
            $dates = $final['dates'] ?? array();
            foreach ($final['matches'] as $ri => $round_matches) {
                $date = $dates[$ri] ?? null;
                if (!$date) continue;
                foreach ($round_matches as $m) {
                    if (empty($m['home'])) continue;
                    $club = lgw_champ_entry_club($m['home']);
                    if (!$club) continue;
                    if (!isset($bookings[$date][$club])) {
                        $bookings[$date][$club] = array('count' => 0, 'champ_id' => $champ_id);
                    }
                    $bookings[$date][$club]['count']++;
                }
            }
        }
    }

    update_option('lgw_green_bookings', $bookings);
    update_option('lgw_green_bookings_built', LGW_VERSION);
    return $bookings;
}

/**
 * Auto-backfill on init if bookings have never been built,
 * or if the plugin has been updated since they were last built.
 */
function lgw_maybe_backfill_green_bookings() {
    $built = get_option('lgw_green_bookings_built', '');
    if (!$built) {
        lgw_rebuild_green_bookings();
    }
}
add_action('init', 'lgw_maybe_backfill_green_bookings');

// ── Cross-championship green capacity helpers ─────────────────────────────────

/**
 * Get the draw priority rank for a championship.
 * Manual priority list (lgw_champ_priority) takes precedence.
 * Uncheduled championships fall after all listed ones, ordered by draw timestamp.
 */
function lgw_champ_priority_rank($champ_id, $champ) {
    $order = get_option('lgw_champ_priority', array());
    $pos   = array_search($champ_id, $order);
    if ($pos !== false) return intval($pos);
    // Not in manual list — rank after all listed ones by draw timestamp (earlier = higher priority)
    $ts = intval($champ['draw_timestamp'] ?? 0);
    return 10000 + ($ts > 0 ? $ts : PHP_INT_MAX);
}

/**
 * Return available home slots for a club on a given date for a given championship.
 * Takes into account bookings from higher-priority championships.
 */
function lgw_champ_available_slots($champ_id, $champ, $club, $date) {
    $multi_green = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));
    $total_limit = lgw_champ_home_limit($club, $multi_green);
    $my_rank     = lgw_champ_priority_rank($champ_id, $champ);
    $bookings    = get_option('lgw_green_bookings', array());
    $date_book   = $bookings[$date] ?? array();
    $used = 0;
    foreach ($date_book as $club_key => $entry) {
        if (strtolower($club_key) !== strtolower($club)) continue;
        // Only count bookings from higher-priority championships
        $other_rank = lgw_champ_priority_rank($entry['champ_id'], get_option('lgw_champ_' . $entry['champ_id'], array()));
        if ($other_rank < $my_rank) {
            $used += intval($entry['count']);
        }
    }
    return max(0, $total_limit - $used);
}

/**
 * Write this championship's home assignments into lgw_green_bookings.
 * Called after a successful draw.
 */
function lgw_champ_write_bookings($champ_id, $bracket, $dates) {
    $bookings = get_option('lgw_green_bookings', array());
    // Remove any existing bookings for this champ_id + section key
    foreach ($bookings as $date => $clubs) {
        foreach ($clubs as $club => $entry) {
            if (($entry['champ_id'] ?? '') === $champ_id) {
                $bookings[$date][$club]['count'] -= $entry['count'];
                if ($bookings[$date][$club]['count'] <= 0) {
                    unset($bookings[$date][$club]);
                }
                if (empty($bookings[$date])) unset($bookings[$date]);
            }
        }
    }
    // Count home matches per date per club from this bracket
    foreach ($bracket['matches'] as $ri => $round_matches) {
        $date = $dates[$ri] ?? null;
        if (!$date) continue;
        foreach ($round_matches as $m) {
            if (empty($m['home'])) continue;
            $club = lgw_champ_entry_club($m['home']);
            if (!$club) continue;
            if (!isset($bookings[$date][$club])) {
                $bookings[$date][$club] = array('count' => 0, 'champ_id' => $champ_id);
            }
            $bookings[$date][$club]['count']++;
        }
    }
    update_option('lgw_green_bookings', $bookings);
}

/**
 * Release all green bookings for a given championship (called on draw reset).
 */
function lgw_champ_release_bookings($champ_id) {
    $bookings = get_option('lgw_green_bookings', array());
    foreach ($bookings as $date => $clubs) {
        foreach ($clubs as $club => $entry) {
            if (($entry['champ_id'] ?? '') === $champ_id) {
                unset($bookings[$date][$club]);
            }
        }
        if (empty($bookings[$date])) unset($bookings[$date]);
    }
    update_option('lgw_green_bookings', $bookings);
}

/**
 * Get a summary of green usage across all championships for a given date.
 * Returns array keyed by club => array('used' => n, 'limit' => n, 'champs' => [...])
 */
function lgw_green_usage_by_date($date) {
    $bookings  = get_option('lgw_green_bookings', array());
    $date_book = $bookings[$date] ?? array();
    $usage     = array();
    foreach ($date_book as $club => $entry) {
        $champ    = get_option('lgw_champ_' . $entry['champ_id'], array());
        $multi_green = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));
        $limit    = lgw_champ_home_limit($club, $multi_green);
        $usage[$club] = array(
            'used'     => intval($entry['count']),
            'limit'    => $limit,
            'champ_id' => $entry['champ_id'],
            'title'    => $champ['title'] ?? $entry['champ_id'],
        );
    }
    return $usage;
}

function lgw_champ_perform_draw($champ_id, $champ, $section = '0') {
    if ($section === 'final') {
        return lgw_champ_perform_final_draw($champ_id, $champ);
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

    // Build per-date available slots for cross-championship capacity check
    $date_slots = array();
    foreach ($dates as $ri => $date) {
        $date_slots[$ri] = array();
        foreach ($raw_entries as $entry) {
            $club = lgw_champ_entry_club($entry);
            if ($club && !isset($date_slots[$ri][$club])) {
                $date_slots[$ri][$club] = lgw_champ_available_slots($champ_id, $champ, $club, $date);
            }
        }
    }

    $result = lgw_draw_build_bracket($numbered, array(
        'get_club'      => 'lgw_champ_entry_club',
        // Champ rule: max available slots (cross-championship capacity aware)
        'home_at_limit' => function($club, $counts, $round_idx) use ($multi_green, $champ_id, $champ, $dates, $date_slots) {
            $date = $dates[$round_idx] ?? null;
            if ($date && isset($date_slots[$round_idx][$club])) {
                return ($counts[$club] ?? 0) >= $date_slots[$round_idx][$club];
            }
            return ($counts[$club] ?? 0) >= lgw_champ_home_limit($club, $multi_green);
        },
        'separate_prelim' => 'lgw_champ_separate_clubs',
        'separate_r2'     => 'lgw_champ_separate_r2_slots',
        'stored_rounds'   => $stored_rounds,
        'dates'           => $dates,
        'r2_label'        => $r2_label,
        'game_nums'       => true,
    ));

    if (!$result) return new WP_Error('too_few', 'At least 2 entries required.');

    $bracket_key = 'section_' . $section_idx . '_bracket';
    $bracket_data = array(
        'title'   => ($champ['title'] ?? 'Championship') . ' — Section ' . ($sections[$section_idx]['label'] ?? ($section_idx + 1)),
        'rounds'  => $result['rounds'],
        'dates'   => $dates,
        'matches' => $result['matches'],
    );
    $champ[$bracket_key] = $bracket_data;
    $champ['section_' . $section_idx . '_draw_pairs']       = $result['pairs'];
    $champ['section_' . $section_idx . '_draw_version']     = intval($champ['section_' . $section_idx . '_draw_version'] ?? 0) + 1;
    $champ['section_' . $section_idx . '_pairs_cursor']     = 0;
    $champ['section_' . $section_idx . '_draw_in_progress'] = true;

    // Stamp draw timestamp for priority fallback ordering
    if (empty($champ['draw_timestamp'])) {
        $champ['draw_timestamp'] = time();
    }

    // Integrity check — bail if the option would exceed safe WP option size (~800KB)
    if (strlen(serialize($champ)) > 800000) {
        return new WP_Error('too_large', 'Bracket data exceeds safe storage limit. Reduce the number of entries.');
    }

    update_option('lgw_champ_' . $champ_id, $champ);

    // Write green bookings for this section
    lgw_champ_write_bookings($champ_id, $bracket_data, $dates);

    return true;
}

/**
 * Draw the Final Stage.
 * $qualifiers may be passed directly (from try_seed_final) or derived from
 * completed section brackets (admin "Draw Final Stage Now" button path).
 */
function lgw_champ_perform_final_draw($champ_id, &$champ, $qualifiers = null) {
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
            $q = lgw_champ_get_section_qualifiers($bracket, $q_per_section);
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

    $result = lgw_draw_build_bracket($numbered, array(
        'get_club'      => 'lgw_champ_entry_club',
        'home_at_limit' => function($club, $counts) use ($multi_green) {
            return ($counts[$club] ?? 0) >= lgw_champ_home_limit($club, $multi_green);
        },
        'separate_prelim' => 'lgw_champ_separate_clubs',
        'separate_r2'     => 'lgw_champ_separate_r2_slots',
        // Force round names based on full bracket size so we get e.g. Semi-Final/Final
        // rather than Preliminary Round/Final when prelim_count > 0
        'stored_rounds'   => lgw_draw_default_rounds(count($numbered)),
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

    update_option('lgw_champ_' . $champ_id, $champ);
    return true;
}

/**
 * Compute sections: <= 32 entries = 1 section, <= 64 = 2, > 64 = 4.
 * Entries shuffled randomly before splitting.
 */
function lgw_champ_build_sections($entries) {
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
add_action('admin_init', 'lgw_champ_handle_admin_actions');
function lgw_champ_handle_admin_actions() {
    if (!current_user_can('manage_options')) return;

    // Save championship
    if (isset($_POST['lgw_champ_save_nonce']) && wp_verify_nonce($_POST['lgw_champ_save_nonce'], 'lgw_champ_save')) {
        $champ_id = sanitize_key($_POST['champ_id'] ?? '');
        if (!$champ_id) {
            wp_redirect(admin_url('admin.php?page=lgw-champs&action=edit&champ_error=missing_id'));
            exit;
        }

        $existing = get_option('lgw_champ_' . $champ_id, array());

        $entries_raw = sanitize_textarea_field(wp_unslash($_POST['lgw_champ_entries'] ?? ''));
        $entries     = array_values(array_filter(array_map('trim', explode("\n", $entries_raw))));

        $dates_raw   = sanitize_textarea_field($_POST['lgw_champ_dates'] ?? '');
        $dates       = array_values(array_filter(array_map('trim', explode("\n", $dates_raw))));
        $multi_green = sanitize_textarea_field(wp_unslash($_POST['lgw_champ_multi_green'] ?? ''));

        // Rebuild sections only if entries changed and no draw in progress
        $draw_started = false;
        $existing_sections = $existing['sections'] ?? array();
        foreach ($existing_sections as $idx => $sec) {
            if (!empty($existing['section_' . $idx . '_draw_version'])) { $draw_started = true; break; }
        }

        $sections = $draw_started ? $existing_sections : lgw_champ_build_sections($entries);

        $champ_data = array_merge($existing, array(
            'title'       => sanitize_text_field(wp_unslash($_POST['lgw_champ_title'] ?? '')),
            'discipline'  => sanitize_text_field($_POST['lgw_champ_discipline'] ?? 'singles'),
            'season'      => sanitize_text_field(wp_unslash($_POST['lgw_champ_season'] ?? '')),
            'entries'     => $entries,
            'sections'    => $sections,
            'dates'       => $dates,
            'multi_green' => $multi_green,
        ));
        // Save per-section dates
        $sec_dates_post = $_POST['lgw_champ_section_dates'] ?? array();
        foreach ($sections as $idx => $sec) {
            $key = 'section_' . $idx . '_dates';
            $champ_data[$key] = sanitize_textarea_field(wp_unslash($sec_dates_post[$idx] ?? ''));
        }

        // Keep section bracket dates in sync with saved dates after draw
        foreach ($sections as $idx => $sec) {
            $sec_dates_key = 'section_' . $idx . '_dates';
            $sec_dates_raw = $champ_data[$sec_dates_key] ?? '';
            $sec_dates = $sec_dates_raw
                ? array_values(array_filter(array_map('trim', explode("\n", $sec_dates_raw))))
                : $dates;
            // Sync into section bracket if drawn
            $bracket_key = 'section_' . $idx . '_bracket';
            if (!empty($champ_data[$bracket_key])) {
                $champ_data[$bracket_key]['dates'] = $sec_dates;
            }
            // Sync into final stage bracket if drawn
            if (!empty($champ_data['final_bracket'])) {
                $champ_data['final_bracket']['dates'] = $dates;
            }
        }

        update_option('lgw_champ_' . $champ_id, $champ_data);
        wp_redirect(admin_url('admin.php?page=lgw-champs&edit=' . $champ_id . '&saved=1'));
        exit;
    }

    // Reset draw
    if (isset($_GET['reset_draw']) && isset($_GET['edit'])) {
        $champ_id = sanitize_key($_GET['edit']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lgw_champ_reset_' . $champ_id)) {
            $champ    = get_option('lgw_champ_' . $champ_id, array());
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
                $champ['sections'] = lgw_champ_build_sections($champ['entries']);
            }
            // Clear draw timestamp so priority falls back to draw order on redraw
            unset($champ['draw_timestamp']);
            update_option('lgw_champ_' . $champ_id, $champ);

            // Release all green bookings for this championship
            lgw_champ_release_bookings($champ_id);

            wp_redirect(admin_url('admin.php?page=lgw-champs&edit=' . $champ_id . '&saved=1'));
            exit;
        }
    }

    // Delete
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $del_id = sanitize_key($_GET['id']);
        if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lgw_champ_delete_' . $del_id)) {
            delete_option('lgw_champ_' . $del_id);
            wp_redirect(admin_url('admin.php?page=lgw-champs&deleted=1'));
            exit;
        }
    }
}

// ── Manual green bookings rebuild ────────────────────────────────────────────
add_action('admin_post_lgw_rebuild_green_bookings', 'lgw_admin_rebuild_green_bookings');
function lgw_admin_rebuild_green_bookings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_rebuild_bookings_nonce');
    lgw_rebuild_green_bookings();
    wp_redirect(admin_url('admin.php?page=lgw-champs&bookings_rebuilt=1'));
    exit;
}

// ── Save championship priority order ─────────────────────────────────────────
add_action('admin_post_lgw_save_champ_priority', 'lgw_save_champ_priority');
function lgw_save_champ_priority() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_champ_priority_nonce');
    $order = isset($_POST['lgw_champ_priority']) ? array_map('sanitize_key', $_POST['lgw_champ_priority']) : array();
    update_option('lgw_champ_priority', array_values(array_filter($order)));
    wp_redirect(admin_url('admin.php?page=lgw-champs&priority_saved=1'));
    exit;
}

// ── Admin menu ─────────────────────────────────────────────────────────────────
function lgw_champs_register_submenu() {
    add_submenu_page('lgw-scorecards', 'Championships', '🏅 Championships', 'manage_options', 'lgw-champs', 'lgw_champs_admin_page');
}

function lgw_champs_admin_page() {
    $action   = $_GET['action'] ?? '';
    $champ_id = sanitize_key($_GET['edit'] ?? '');
    if ($champ_id && ($action === 'edit' || isset($_GET['edit']))) { lgw_champ_edit_page($champ_id); return; }
    if ($action === 'new') { lgw_champ_edit_page(''); return; }
    lgw_champs_list_page();
}

function lgw_champs_list_page() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'lgw_champ_%' ORDER BY option_name");
    $champs = array();
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (is_array($val) && isset($val['title'])) $champs[$id] = $val;
    }
    ?>
    <div class="wrap">
    <?php lgw_page_header('Championship Management'); ?>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Championship saved.</p></div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success is-dismissible"><p>Championship deleted.</p></div><?php endif; ?>

    <h2 style="display:flex;align-items:center;gap:16px">Championships
      <a href="<?php echo admin_url('admin.php?page=lgw-champs&action=new'); ?>" class="button button-primary">+ New Championship</a>
    </h2>
    <?php if (isset($_GET['priority_saved'])): ?>
      <div class="notice notice-success is-dismissible"><p>Priority order saved.</p></div>
    <?php endif; ?>

    <?php if (empty($champs)): ?>
      <p>No championships yet. <a href="<?php echo admin_url('admin.php?page=lgw-champs&action=new'); ?>">Create one →</a></p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:900px">
      <thead><tr><th>Title</th><th>Season</th><th>Discipline</th><th>Entries</th><th>Sections</th><th>Draw</th><th>Shortcode</th><th></th></tr></thead>
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
        <td><?php echo esc_html($champ['season'] ?? '—'); ?></td>
        <td><?php echo esc_html(ucfirst($champ['discipline'] ?? 'singles')); ?></td>
        <td><?php echo count($champ['entries'] ?? array()); ?></td>
        <td><?php echo $n_sections; ?></td>
        <td><?php echo $drawn ? '✅ Drawn' : '⏳ Not drawn'; ?></td>
        <td><code>[lgw_champ id="<?php echo esc_html($id); ?>"]</code></td>
        <td style="white-space:nowrap">
          <a href="<?php echo admin_url('admin.php?page=lgw-champs&edit=' . urlencode($id)); ?>" class="button button-small">Edit</a>
          <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lgw-champs&action=delete&id=' . urlencode($id)), 'lgw_champ_delete_' . $id); ?>"
             class="button button-small button-link-delete" onclick="return confirm('Delete this championship?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <hr>
    <h2>Draw Priority Order</h2>
    <p>Championships drawn first get priority for home green slots. Drag to set the order — championships higher in the list take precedence when multiple competitions share the same date. Any championship not listed here falls below all listed ones, ordered by draw time.</p>

    <?php $priority = get_option('lgw_champ_priority', array()); ?>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
      <?php wp_nonce_field('lgw_champ_priority_nonce'); ?>
      <input type="hidden" name="action" value="lgw_save_champ_priority">
      <ul id="lgw-priority-list" style="list-style:none;margin:0 0 12px;padding:0;max-width:500px">
        <?php
        // Show listed champs first in priority order, then unlisted ones
        $listed   = array_filter($priority, fn($id) => isset($champs[$id]));
        $unlisted = array_diff(array_keys($champs), $listed);
        $ordered  = array_merge($listed, array_values($unlisted));
        foreach ($ordered as $id):
            if (!isset($champs[$id])) continue;
            $rank = array_search($id, $priority);
        ?>
        <li data-id="<?php echo esc_attr($id); ?>"
            style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:6px;background:var(--color-background-secondary,#f6f7f7);border:1px solid #ddd;border-radius:4px;cursor:grab">
          <span style="color:#999;font-size:16px;user-select:none">⠿</span>
          <span style="flex:1;font-weight:500"><?php echo esc_html($champs[$id]['title'] ?? $id); ?></span>
          <?php if ($rank !== false): ?>
            <span style="font-size:11px;color:#138211;font-weight:600">Priority <?php echo $rank + 1; ?></span>
          <?php else: ?>
            <span style="font-size:11px;color:#888">Draw order</span>
          <?php endif; ?>
          <input type="hidden" name="lgw_champ_priority[]" value="<?php echo esc_attr($id); ?>">
        </li>
        <?php endforeach; ?>
      </ul>
      <?php submit_button('Save Priority Order', 'secondary', 'lgw_save_priority', false); ?>
    </form>

    <hr>
    <h2>Green Usage by Date</h2>
    <p>Home green slots used across all championships, grouped by round date. Amber = at capacity.</p>

    <?php if (isset($_GET['bookings_rebuilt'])): ?>
      <div class="notice notice-success is-dismissible"><p>Green bookings recalculated from all drawn brackets.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-bottom:12px">
      <?php wp_nonce_field('lgw_rebuild_bookings_nonce'); ?>
      <input type="hidden" name="action" value="lgw_rebuild_green_bookings">
      <button type="submit" class="button button-secondary"
              onclick="return confirm('Recalculate green bookings from all drawn brackets? This will overwrite any manually adjusted data.')">
        🔄 Recalculate from All Drawn Brackets
      </button>
      <span style="margin-left:8px;font-size:12px;color:#666">
        Use this after upgrading from a version before v7.1.5, or if bookings appear out of sync.
      </span>
    </form>


    <?php
    $bookings = get_option('lgw_green_bookings', array());
    if (empty($bookings)):
    ?>
      <p style="color:#888">No draws performed yet — green usage will appear here after the first draw.</p>
    <?php else:
      $usage_rows = array();
      foreach ($bookings as $date => $clubs) {
          foreach ($clubs as $club => $entry) {
              $champ_data  = get_option('lgw_champ_' . $entry['champ_id'], array());
              $multi_green = array_filter(array_map('trim', explode("\n", $champ_data['multi_green'] ?? '')));
              $limit       = lgw_champ_home_limit($club, $multi_green);
              $usage_rows[] = array(
                  'date'     => $date,
                  'club'     => $club,
                  'count'    => intval($entry['count']),
                  'limit'    => $limit,
                  'title'    => $champ_data['title'] ?? $entry['champ_id'],
                  'full'     => intval($entry['count']) >= $limit,
              );
          }
      }
      $sort    = in_array($_GET['gu_sort'] ?? '', array('date','club')) ? sanitize_key($_GET['gu_sort']) : 'date';
      $sec_key = $sort === 'date' ? 'club' : 'date';

      // Parse DD/MM/YY or DD/MM/YYYY into a comparable timestamp
      $parse_date = function($str) {
          $parts = explode('/', trim($str));
          if (count($parts) === 3) {
              $year = strlen($parts[2]) === 2 ? '20' . $parts[2] : $parts[2];
              return mktime(0, 0, 0, intval($parts[1]), intval($parts[0]), intval($year));
          }
          return strtotime($str) ?: 0;
      };

      usort($usage_rows, function($a, $b) use ($sort, $sec_key, $parse_date) {
          if ($sort === 'date') {
              $p = $parse_date($a['date']) - $parse_date($b['date']);
          } else {
              $p = strcmp($a[$sort], $b[$sort]);
          }
          if ($p !== 0) return $p;
          // Secondary sort: always parse dates when sec_key is date
          if ($sec_key === 'date') {
              return $parse_date($a['date']) - $parse_date($b['date']);
          }
          return strcmp($a[$sec_key], $b[$sec_key]);
      });
      $base_url = admin_url('admin.php?page=lgw-champs');
    ?>
    <div style="margin-bottom:10px;font-size:13px">
      Sort by:
      <a href="<?php echo esc_url($base_url . '&gu_sort=date'); ?>" style="font-weight:<?php echo $sort==='date'?'700':'400';?>">Date</a>
      &nbsp;|&nbsp;
      <a href="<?php echo esc_url($base_url . '&gu_sort=club'); ?>" style="font-weight:<?php echo $sort==='club'?'700':'400';?>">Club</a>
    </div>
    <table class="widefat" style="max-width:820px;border-collapse:collapse">
      <thead>
        <tr style="background:var(--color-background-secondary,#f6f7f7)">
          <th style="padding:8px 12px;border-bottom:2px solid #ddd;width:110px"><?php echo $sort==='date'?'&#128197; Date':'Date';?></th>
          <th style="padding:8px 12px;border-bottom:2px solid #ddd"><?php echo $sort==='club'?'&#127968; Club':'Club';?></th>
          <th style="padding:8px 12px;text-align:center;border-bottom:2px solid #ddd;width:90px">Used / Limit</th>
          <th style="padding:8px 12px;border-bottom:2px solid #ddd">Championships on this date</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $n = count($usage_rows);
        $pspans = array_fill(0, $n, 0);
        $sspans = array_fill(0, $n, 0);
        $prim_seen = array();
        $pair_seen = array();
        for ($i = 0; $i < $n; $i++) {
            $pv = $usage_rows[$i][$sort];
            $sv = $usage_rows[$i][$sec_key];
            $pk = $pv . '||' . $sv;
            if (!isset($prim_seen[$pv])) {
                $prim_seen[$pv] = true;
                $c = 0;
                for ($j = $i; $j < $n && $usage_rows[$j][$sort] === $pv; $j++) $c++;
                $pspans[$i] = $c;
            }
            if (!isset($pair_seen[$pk])) {
                $pair_seen[$pk] = true;
                $c = 0;
                for ($j = $i; $j < $n && $usage_rows[$j][$sort] === $pv && $usage_rows[$j][$sec_key] === $sv; $j++) $c++;
                $sspans[$i] = $c;
            }
        }
        $pair_titles = array();
        foreach ($usage_rows as $row) {
            $pk = $row[$sort] . '||' . $row[$sec_key];
            if (!in_array($row['title'], $pair_titles[$pk] ?? array()))
                $pair_titles[$pk][] = $row['title'];
        }
        $prev_prim = null;
        foreach ($usage_rows as $i => $row):
            $pv = $row[$sort];
            $sv = $row[$sec_key];
            $pk = $pv . '||' . $sv;
            $border_top = ($pspans[$i] > 0 && $prev_prim !== null) ? 'border-top:2px solid #ccc;' : '';
            $bg = $row['full'] ? 'background:#fff3cd;' : '';
      ?>
        <tr style="border-bottom:1px solid #eee;<?php echo $bg;?>">
          <?php if ($pspans[$i] > 0): ?>
          <td rowspan="<?php echo $pspans[$i];?>" style="padding:8px 12px;vertical-align:top;font-weight:600;border-right:2px solid #ddd;<?php echo $border_top;?>">
            <?php echo esc_html($pv);?>
          </td>
          <?php endif; ?>
          <?php if ($sspans[$i] > 0):
            $titles = $pair_titles[$pk] ?? array();
          ?>
          <td rowspan="<?php echo $sspans[$i];?>" style="padding:8px 12px;vertical-align:middle"><?php echo esc_html($sv);?></td>
          <td rowspan="<?php echo $sspans[$i];?>" style="padding:8px 12px;text-align:center;vertical-align:middle;font-weight:600;color:<?php echo $row['full']?'#c0202a':'#138211';?>">
            <?php echo $row['count'];?>/<?php echo $row['limit'];?>
            <?php if ($row['full']): ?><br><span style="font-size:10px;font-weight:400;color:#c0202a">FULL</span><?php endif;?>
          </td>
          <td rowspan="<?php echo $sspans[$i];?>" style="padding:8px 12px;vertical-align:middle;font-size:13px;color:#555">
            <?php echo implode('<br>', array_map('esc_html', $titles));?>
          </td>
          <?php endif;?>
        </tr>
      <?php
          if ($pspans[$i] > 0) $prev_prim = $pv;
        endforeach;
      ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>
    </div>
    <script>
    // Simple drag-to-reorder for priority list
    (function() {
        var list = document.getElementById('lgw-priority-list');
        if (!list) return;
        var dragging = null;
        list.querySelectorAll('li').forEach(function(li) {
            li.addEventListener('dragstart', function(e) {
                dragging = li;
                setTimeout(function() { li.style.opacity = '0.4'; }, 0);
            });
            li.addEventListener('dragend', function() {
                li.style.opacity = '';
                dragging = null;
                updateRankLabels();
            });
            li.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragging || dragging === li) return;
                var rect = li.getBoundingClientRect();
                var mid  = rect.top + rect.height / 2;
                if (e.clientY < mid) {
                    list.insertBefore(dragging, li);
                } else {
                    list.insertBefore(dragging, li.nextSibling);
                }
            });
            li.setAttribute('draggable', 'true');
        });
        function updateRankLabels() {
            list.querySelectorAll('li').forEach(function(li, idx) {
                var label = li.querySelector('span:last-of-type');
                if (label) {
                    label.textContent = 'Priority ' + (idx + 1);
                    label.style.color = '#138211';
                    label.style.fontWeight = '600';
                }
                var hidden = li.querySelector('input[type=hidden]');
                if (hidden) {
                    // order is implicit from DOM position — inputs stay in order
                }
            });
        }
    })();
    </script>
    <?php
}

function lgw_champ_edit_page($champ_id) {
    $champ   = $champ_id ? get_option('lgw_champ_' . $champ_id, array()) : array();
    $is_new  = !$champ_id;
    $nonce   = wp_create_nonce('lgw_champ_nonce');
    $drawn   = false;
    foreach (($champ['sections'] ?? array()) as $idx => $sec) {
        if (!empty($champ['section_' . $idx . '_draw_version'])) { $drawn = true; break; }
    }
    $final_bracket = $champ['final_bracket'] ?? null;

    $entries_str = implode("\n", $champ['entries'] ?? array());
    $dates_str   = implode("\n", $champ['dates']   ?? array());
    $sections    = $champ['sections'] ?? array();
    $disciplines = array('singles' => 'Singles', 'pairs' => 'Pairs', 'triples' => 'Triples', 'fours' => 'Fours');
    ?>
    <div class="wrap">
    <?php lgw_page_header($is_new ? 'New Championship' : 'Edit: ' . ($champ['title'] ?? $champ_id)); ?>
    <p><a href="<?php echo admin_url('admin.php?page=lgw-champs'); ?>">← Back to championships</a></p>
    <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>

    <form method="post">
      <?php wp_nonce_field('lgw_champ_save', 'lgw_champ_save_nonce'); ?>
      <input type="hidden" name="champ_id" value="<?php echo esc_attr($champ_id ?: sanitize_key(($_POST['lgw_champ_title'] ?? 'champ') . '-' . date('Y'))); ?>">

      <table class="form-table" style="max-width:760px">
        <?php if ($is_new): ?>
        <tr>
          <th><label for="lgw_champ_id_field">Championship ID</label></th>
          <td>
            <input type="text" id="lgw_champ_id_field" name="champ_id"
                   value="<?php echo esc_attr($champ_id); ?>"
                   placeholder="e.g. singles-2025" class="regular-text">
            <p class="description">Used in the shortcode: <code>[lgw_champ id="…"]</code>. Lowercase, hyphens only.</p>
          </td>
        </tr>
        <?php else: ?>
        <tr><th>Championship ID</th><td><code><?php echo esc_html($champ_id); ?></code>
          <p class="description">Shortcode: <code>[lgw_champ id="<?php echo esc_attr($champ_id); ?>"]</code></p></td></tr>
        <?php endif; ?>
        <tr>
          <th><label for="lgw_champ_title">Title</label></th>
          <td><input type="text" id="lgw_champ_title" name="lgw_champ_title"
                     value="<?php echo esc_attr($champ['title'] ?? ''); ?>"
                     placeholder="e.g. LGW Singles Championship 2025" class="regular-text" style="width:360px"></td>
        </tr>
        <tr>
          <th><label for="lgw_champ_discipline">Discipline</label></th>
          <td>
            <select id="lgw_champ_discipline" name="lgw_champ_discipline">
              <?php foreach ($disciplines as $val => $label): ?>
              <option value="<?php echo $val; ?>" <?php selected($champ['discipline'] ?? 'singles', $val); ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="lgw_champ_season">Season</label></th>
          <td>
            <input type="text" id="lgw_champ_season" name="lgw_champ_season"
                   value="<?php echo esc_attr($champ['season'] ?? ''); ?>"
                   placeholder="e.g. 2025 or 2025/26" class="regular-text" style="width:160px">
            <p class="description">Used by <code>[lgw_finalists season="2025"]</code> to group championships.</p>
          </td>
        </tr>
        <tr>
          <th><label for="lgw_champ_entries">Entries</label></th>
          <td>
            <textarea id="lgw_champ_entries" name="lgw_champ_entries" rows="20"
                      style="width:400px;font-family:monospace;font-size:13px"
                      placeholder="One entry per line. Format: Player Name(s), Club&#10;J. Smith, Salisbury&#10;A. Jones / B. Brown, Ballymena"><?php echo esc_textarea($entries_str); ?></textarea>
            <p class="description">
              Format: <code>Player Name(s), Club</code> — the club is used for the 6-per-green draw constraint.<br>
              For pairs/triples/fours separate players with <code>/</code>: <code>Smith / Jones, Salisbury</code><br>
              Currently: <strong><?php echo count($champ['entries'] ?? array()); ?></strong> entries.
              <?php if ($drawn): ?><br><strong>Draw in progress — to correct spelling mistakes use the Rename Entry tool below.</strong><?php endif; ?>
            </p>
          </td>
        </tr>
        <?php if ($drawn && !$is_new): ?>
        <tr>
          <th><label for="lgw_rename_old">Rename Entry</label></th>
          <td>
            <div id="lgw-rename-entry-wrap">
              <select id="lgw_rename_old" style="width:400px;max-width:100%;font-size:13px">
                <option value="">— select entry to rename —</option>
                <?php foreach (($champ['entries'] ?? array()) as $entry): ?>
                <option value="<?php echo esc_attr($entry); ?>"><?php echo esc_html($entry); ?></option>
                <?php endforeach; ?>
              </select>
              <br style="margin-bottom:6px">
              <input type="text" id="lgw_rename_new" placeholder="Corrected name" style="width:400px;max-width:100%;font-size:13px;margin-top:6px">
              <br>
              <button type="button" id="lgw_rename_btn" class="button button-secondary" style="margin-top:8px">Rename Entry</button>
              <span id="lgw_rename_msg" style="margin-left:10px;font-size:13px"></span>
            </div>
            <p class="description">Corrects spelling in the entries list and throughout all drawn brackets. Does not affect scores or reset the draw.</p>
            <script>
            (function(){
              document.getElementById('lgw_rename_btn').addEventListener('click', function(){
                var oldVal = document.getElementById('lgw_rename_old').value.trim();
                var newVal = document.getElementById('lgw_rename_new').value.trim();
                var msg    = document.getElementById('lgw_rename_msg');
                if (!oldVal) { msg.style.color='#c00'; msg.textContent='Please select an entry.'; return; }
                if (!newVal) { msg.style.color='#c00'; msg.textContent='Please enter the corrected name.'; return; }
                if (oldVal === newVal) { msg.style.color='#c00'; msg.textContent='Names are identical.'; return; }
                this.disabled = true;
                msg.style.color='#555'; msg.textContent='Saving...';
                var fd = new FormData();
                fd.append('action',   'lgw_champ_rename_entry');
                fd.append('nonce',    '<?php echo esc_js(wp_create_nonce("lgw_champ_score")); ?>');
                fd.append('champ_id', '<?php echo esc_js($champ_id); ?>');
                fd.append('old_name', oldVal);
                fd.append('new_name', newVal);
                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {method:'POST', body:fd})
                  .then(function(r){ return r.json(); })
                  .then(function(data){
                    if (data.success) {
                      msg.style.color='#197319';
                      msg.textContent = data.data.message;
                      var sel = document.getElementById('lgw_rename_old');
                      for (var i=0; i<sel.options.length; i++) {
                        if (sel.options[i].value === oldVal) {
                          sel.options[i].value = newVal;
                          sel.options[i].text  = newVal;
                          sel.options[i].selected = true;
                          break;
                        }
                      }
                      document.getElementById('lgw_rename_new').value = '';
                      var ta = document.getElementById('lgw_champ_entries');
                      if (ta) {
                        ta.value = ta.value.split('\n').map(function(line){
                          return line.trim() === oldVal ? newVal : line;
                        }).join('\n');
                      }
                    } else {
                      msg.style.color='#c00';
                      msg.textContent = data.data || 'Error.';
                    }
                  })
                  .catch(function(){ msg.style.color='#c00'; msg.textContent='Network error.'; })
                  .finally(function(){ document.getElementById('lgw_rename_btn').disabled = false; });
              });
            })();
            </script>
          </td>
        </tr>
        <?php endif; ?>
        <tr>
          <th><label for="lgw_champ_dates">Default Round Dates</label></th>
          <td>
            <textarea id="lgw_champ_dates" name="lgw_champ_dates" rows="5"
                      style="width:360px;font-family:monospace;font-size:13px"
                      placeholder="One date per line (optional)&#10;01/05/2025&#10;05/06/2025"><?php echo esc_textarea($dates_str); ?></textarea>
            <p class="description">Used when no per-section dates are set. One date per line aligned with round order.</p>
          </td>
        </tr>
        <tr>
          <th><label for="lgw_champ_multi_green">Multi-Green Clubs</label></th>
          <td>
            <textarea id="lgw_champ_multi_green" name="lgw_champ_multi_green" rows="4"
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
            <textarea name="lgw_champ_section_dates[<?php echo $idx; ?>]" rows="4"
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
    .lgw-hr-table { font-size:11px; border-collapse:collapse; width:100%; }
    .lgw-hr-table th, .lgw-hr-table td { padding:3px 5px; border:1px solid #ddd; }
    .lgw-hr-head1 th { background:#1a2e5a; color:#fff; text-align:center; }
    .lgw-hr-head2 th { background:#243d78; color:#fff; text-align:center; font-size:10px; font-weight:600; }
    .lgw-hr-table td { text-align:center; }
    .lgw-hr-table td.club { text-align:left; font-weight:700; min-width:110px; }
    .lgw-hr-over   { background:#f8d7da; color:#842029; font-weight:700; }
    .lgw-hr-amber  { background:#fff3cd; color:#856404; font-weight:700; }
    .lgw-hr-ok     { background:#d1e7dd; color:#0a3622; }
    .lgw-hr-muted  { color:#ccc; }
    </style>
    <div style="overflow-x:auto;margin-bottom:24px">
    <table class="lgw-hr-table">
      <thead>
        <tr class="lgw-hr-head1">
          <th rowspan="2" style="text-align:left">Club</th>
          <th rowspan="2">Entries</th>
          <?php foreach ($round_groups as $grp): ?>
          <th colspan="<?php echo count($grp['cols']); ?>" style="border-left:2px solid rgba(255,255,255,.3)">
            <?php echo esc_html($grp['name']); ?>
          </th>
          <?php endforeach; ?>
        </tr>
        <tr class="lgw-hr-head2">
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
          $limit = lgw_champ_home_limit($club, $multi_green_lines);
        ?>
        <tr>
          <td class="club"><?php echo esc_html(ucwords($club)); ?></td>
          <td class="lgw-hr-muted"><?php echo $entry_counts[$club] ?? 0; ?></td>
          <?php foreach ($all_cols as $col):
            $actual = $by_date[$col][$club]   ?? 0;
            $max    = $max_by_date[$col][$club] ?? $actual;
            $has_max_extra = $max > $actual; // prelims could add more homes
            $over_actual = $actual > $limit;
            $over_max    = $max > $limit && !$over_actual;
            if ($actual === 0 && $max === 0):
          ?>
          <td class="lgw-hr-muted">—</td>
          <?php elseif ($over_actual): ?>
          <td class="lgw-hr-over"><?php echo $actual; ?>/<?php echo $limit;
            if ($has_max_extra) echo ' (' . $max . ')'; ?></td>
          <?php elseif ($over_max): ?>
          <td class="lgw-hr-amber"><?php echo $actual; ?>/<?php echo $limit;
            echo ' (' . $max . ')'; ?></td>
          <?php elseif ($actual > 0): ?>
          <td class="lgw-hr-ok"><?php echo $actual; ?>/<?php echo $limit;
            if ($has_max_extra) echo ' (' . $max . ')'; ?></td>
          <?php else: // max > 0 but actual = 0 — green if within limit, amber if over ?>
          <td class="<?php echo $max > $limit ? 'lgw-hr-amber' : 'lgw-hr-ok'; ?>" style="font-style:italic">(<?php echo $max; ?>)/<?php echo $limit; ?></td>
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
        $sec_drawn    = !empty($champ['section_' . $idx . '_draw_version']);
        $sec_bracket  = $champ['section_' . $idx . '_bracket'] ?? null;
        $sec_entries  = $sec['entries'] ?? array();
    ?>
    <h3>Section <?php echo esc_html($sec['label']); ?></h3>
    <?php if ($sec_drawn && $sec_bracket): ?>
      <p>✅ Draw performed.
        <button type="button"
                class="button button-small lgw-champ-edit-draw-toggle"
                data-section="<?php echo $idx; ?>"
                style="margin-left:10px">
          ✏️ Edit Draw
        </button>
      </p>

      <?php /* ── Editable bracket table ── */ ?>
      <div id="lgw-edit-draw-<?php echo $idx; ?>" style="display:none;margin:0 0 18px">
        <p style="font-size:13px;color:#666;margin-bottom:8px">
          Adjust the participants in any first-round match below. Changing a match will clear its score
          and all downstream results so they can be re-entered. Only the drawn round (Round 1 / Preliminary Round)
          can be directly edited — later rounds propagate automatically from scores.
        </p>
        <?php
        $all_rounds  = $sec_bracket['matches'];
        $round_names_arr = $sec_bracket['rounds'] ?? array();

        // Show only the first two rounds (prelim + R1, or just R1 if no prelims)
        $editable_rounds = array_slice($all_rounds, 0, 2, true);
        $entries_json    = wp_json_encode($sec_entries);
        ?>
        <div class="lgw-edit-draw-section"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="<?php echo $idx; ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('lgw_champ_score')); ?>"
             data-entries="<?php echo esc_attr($entries_json); ?>">
          <?php foreach ($editable_rounds as $ri => $round_matches): ?>
          <h4 style="margin:12px 0 6px;font-size:13px;font-weight:600;color:#444">
            <?php echo esc_html($round_names_arr[$ri] ?? ('Round ' . ($ri + 1))); ?>
            <?php if ($ri === 0 && count($all_rounds) > 1): ?><em style="font-weight:400;color:#888">(Preliminary)</em><?php endif; ?>
          </h4>
          <table class="widefat" style="max-width:720px;font-size:13px;margin-bottom:6px">
            <thead>
              <tr>
                <th style="width:30px">#</th>
                <th>Home</th>
                <th style="width:30px;text-align:center">vs</th>
                <th>Away</th>
                <th style="width:80px"></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($round_matches as $mi => $m): ?>
            <tr data-round="<?php echo $ri; ?>" data-match="<?php echo $mi; ?>">
              <td><?php echo esc_html($m['game_num'] ?? ($mi + 1)); ?></td>
              <td class="lgw-em-home"><?php echo esc_html($m['home'] ?? '—'); ?></td>
              <td style="text-align:center;color:#888">vs</td>
              <td class="lgw-em-away"><?php echo esc_html($m['away'] ?? '—'); ?></td>
              <td>
                <button type="button" class="button button-small lgw-em-edit-btn"
                        data-round="<?php echo $ri; ?>"
                        data-match="<?php echo $mi; ?>"
                        data-home="<?php echo esc_attr($m['home'] ?? ''); ?>"
                        data-away="<?php echo esc_attr($m['away'] ?? ''); ?>">
                  Edit
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endforeach; ?>
          <p class="lgw-em-status" style="font-size:13px;margin:6px 0;min-height:20px"></p>
        </div><!-- .lgw-edit-draw-section -->
      </div><!-- #lgw-edit-draw-N -->
    <?php elseif ($sec_drawn): ?>
      <p>✅ Draw performed.</p>
    <?php else: ?>
      <p>No draw yet.</p>
      <?php
      // Show green capacity warning if other championships have taken slots on the same dates
      $sec_dates_key = 'section_' . $idx . '_dates';
      $sec_dates = !empty($champ[$sec_dates_key])
          ? array_values(array_filter(array_map('trim', explode("\n", $champ[$sec_dates_key]))))
          : ($champ['dates'] ?? array());
      $bookings = get_option('lgw_green_bookings', array());
      $capacity_warnings = array();
      foreach ($sec_dates as $ri => $date) {
          if (!isset($bookings[$date])) continue;
          foreach ($bookings[$date] as $club => $entry) {
              if ($entry['champ_id'] === $champ_id) continue;
              $other_champ  = get_option('lgw_champ_' . $entry['champ_id'], array());
              $other_rank   = lgw_champ_priority_rank($entry['champ_id'], $other_champ);
              $my_rank      = lgw_champ_priority_rank($champ_id, $champ);
              if ($other_rank < $my_rank) {
                  $multi_green = array_filter(array_map('trim', explode("\n", $champ['multi_green'] ?? '')));
                  $limit = lgw_champ_home_limit($club, $multi_green);
                  $available = max(0, $limit - intval($entry['count']));
                  $capacity_warnings[] = array(
                      'date'    => $date,
                      'club'    => $club,
                      'used'    => $entry['count'],
                      'limit'   => $limit,
                      'avail'   => $available,
                      'by'      => $other_champ['title'] ?? $entry['champ_id'],
                  );
              }
          }
      }
      if (!empty($capacity_warnings)):
      ?>
      <div style="margin:8px 0;padding:10px 14px;background:#fff3cd;border-left:4px solid #e8b400;border-radius:0 4px 4px 0;font-size:13px">
        <strong>⚠ Reduced green capacity for this section:</strong>
        <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach ($capacity_warnings as $w): ?>
          <li>
            <strong><?php echo esc_html($w['club']); ?></strong> on <?php echo esc_html($w['date']); ?>
            — <?php echo intval($w['used']); ?>/<?php echo $w['limit']; ?> slots taken by
            <em><?php echo esc_html($w['by']); ?></em>
            <?php if ($w['avail'] > 0): ?>
              (<?php echo $w['avail']; ?> remaining)
            <?php else: ?>
              <strong style="color:#c0202a"> — no home slots available</strong>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <button class="button button-primary lgw-champ-admin-draw-btn"
              data-champ-id="<?php echo esc_attr($champ_id); ?>"
              data-section="<?php echo $idx; ?>"
              data-nonce="<?php echo esc_attr($nonce); ?>">
        🎲 Draw Section <?php echo esc_html($sec['label']); ?> Now
      </button>
      <span class="lgw-champ-draw-msg" style="margin-left:12px;font-size:13px;display:none"></span>
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
      <?php $final_bracket_data = $champ['final_bracket'] ?? null;
            $final_entries_flat = array();
            foreach ($sections as $sidx => $sec) {
                foreach (($sec['entries'] ?? array()) as $e) { $final_entries_flat[] = $e; }
            }
      ?>
      <p>✅ Final Stage draw performed.
        <button type="button"
                class="button button-small lgw-champ-edit-draw-toggle"
                data-section="final"
                style="margin-left:10px">
          ✏️ Edit Draw
        </button>
      </p>
      <?php if ($final_bracket_data): ?>
      <div id="lgw-edit-draw-final" style="display:none;margin:0 0 18px">
        <p style="font-size:13px;color:#666;margin-bottom:8px">
          Adjust participants in any first-round Final Stage match. Changing a match clears its score and all downstream results.
        </p>
        <?php $final_editable = array_slice($final_bracket_data['matches'], 0, 2, true);
              $final_rounds   = $final_bracket_data['rounds'] ?? array();
              $final_entries_json = wp_json_encode($final_entries_flat);
        ?>
        <div class="lgw-edit-draw-section"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="final"
             data-nonce="<?php echo esc_attr(wp_create_nonce('lgw_champ_score')); ?>"
             data-entries="<?php echo esc_attr($final_entries_json); ?>">
          <?php foreach ($final_editable as $ri => $round_matches): ?>
          <h4 style="margin:12px 0 6px;font-size:13px;font-weight:600;color:#444">
            <?php echo esc_html($final_rounds[$ri] ?? ('Round ' . ($ri + 1))); ?>
          </h4>
          <table class="widefat" style="max-width:720px;font-size:13px;margin-bottom:6px">
            <thead><tr><th style="width:30px">#</th><th>Home</th><th style="width:30px;text-align:center">vs</th><th>Away</th><th style="width:80px"></th></tr></thead>
            <tbody>
            <?php foreach ($round_matches as $mi => $m): ?>
            <tr data-round="<?php echo $ri; ?>" data-match="<?php echo $mi; ?>">
              <td><?php echo esc_html($m['game_num'] ?? ($mi + 1)); ?></td>
              <td class="lgw-em-home"><?php echo esc_html($m['home'] ?? '—'); ?></td>
              <td style="text-align:center;color:#888">vs</td>
              <td class="lgw-em-away"><?php echo esc_html($m['away'] ?? '—'); ?></td>
              <td>
                <button type="button" class="button button-small lgw-em-edit-btn"
                        data-round="<?php echo $ri; ?>"
                        data-match="<?php echo $mi; ?>"
                        data-home="<?php echo esc_attr($m['home'] ?? ''); ?>"
                        data-away="<?php echo esc_attr($m['away'] ?? ''); ?>">
                  Edit
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endforeach; ?>
          <p class="lgw-em-status" style="font-size:13px;margin:6px 0;min-height:20px"></p>
        </div>
      </div>
      <?php endif; ?>
    <?php elseif ($all_drawn): ?>
      <p>All sections complete — ready for Final Stage draw.</p>
      <button class="button button-primary lgw-champ-admin-draw-btn"
              data-champ-id="<?php echo esc_attr($champ_id); ?>"
              data-section="final"
              data-nonce="<?php echo esc_attr($nonce); ?>">
        🎲 Draw Final Stage Now
      </button>
      <span class="lgw-champ-draw-msg" style="margin-left:12px;font-size:13px;display:none"></span>
    <?php else: ?>
      <p style="color:#666">Available once all section finals are complete.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($drawn): ?>
    <hr>
    <p>
      <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lgw-champs&edit=' . $champ_id . '&reset_draw=1'), 'lgw_champ_reset_' . $champ_id); ?>"
         class="button button-secondary"
         onclick="return confirm('Reset ALL draws? Brackets cleared, sections re-randomised.')">
        🔄 Reset Draw &amp; Redo
      </a>
    </p>
    <?php endif; ?>

    <hr>
    <h2>Shortcode</h2>
    <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block">[lgw_champ id="<?php echo esc_html($champ_id); ?>"]</pre>

    <?php if ($drawn): ?>
    <hr>
    <h2>Export Draw</h2>
    <p>Download the current draw as an Excel spreadsheet — one sheet per section<?php echo $final_bracket ? ', plus a Final Stage sheet' : ''; ?>.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
      <input type="hidden" name="action" value="lgw_export_champ">
      <input type="hidden" name="champ_id" value="<?php echo esc_attr($champ_id); ?>">
      <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('lgw_export_nonce')); ?>">
      <?php submit_button('📥 Download Draw (.xlsx)', 'secondary'); ?>
    </form>
    <?php endif; ?>

    <?php endif; ?>
    </div>
    <?php
}

// ── Shortcode ──────────────────────────────────────────────────────────────────
add_shortcode('lgw_champ', 'lgw_champ_shortcode');
function lgw_champ_shortcode($atts) {
    $atts     = shortcode_atts(array(
        'id'             => '',
        'title'          => '',
        'sponsor_img'    => '',
        'sponsor_url'    => '',
        'sponsor_name'   => '',
        'color_primary'  => '',
        'color_secondary'=> '',
        'color_bg'       => '',
    ), $atts);
    $champ_id = sanitize_key($atts['id']);
    if (!$champ_id) return '<p>No championship ID provided.</p>';

    // Colour theming — same pattern as [lgw_division]
    $global_theme = get_option('lgw_theme', array());
    $primary   = sanitize_hex_color($atts['color_primary'])    ?: ($global_theme['color_primary']   ?? '');
    $secondary = sanitize_hex_color($atts['color_secondary'])  ?: ($global_theme['color_secondary'] ?? '');
    $bg        = sanitize_hex_color($atts['color_bg'])         ?: ($global_theme['color_bg']        ?? '');
    $theme_style = '';
    if ($primary || $secondary || $bg) {
        $vars = '';
        if ($primary) {
            $vars .= '--lgw-navy:'     . $primary . ';';
            $vars .= '--lgw-navy-mid:' . lgw_theme_lighten($primary, 20) . ';';
            $vars .= '--lgw-tab-bg:'   . lgw_theme_mix($primary, '#ffffff', 85) . ';';
        }
        if ($secondary) {
            $vars .= '--lgw-gold:'   . $secondary . ';';
            $vars .= '--lgw-accent:' . $secondary . ';';
        }
        if ($bg) {
            $vars .= '--lgw-bg:'       . $bg . ';';
            $vars .= '--lgw-bg-alt:'   . lgw_theme_darken($bg, 5) . ';';
            $vars .= '--lgw-bg-hover:' . lgw_theme_darken($bg, 10) . ';';
        }
        $theme_style = '<style>'
            . '.lgw-champ-wrap[data-champ-id="' . esc_attr($champ_id) . '"],'
            . '.lgw-champ-tabs-outer[data-champ-id="' . esc_attr($champ_id) . '"]'
            . '{' . $vars . '}</style>';
    }

    $champ    = get_option('lgw_champ_' . $champ_id, array());
    $title    = !empty($atts['title']) ? $atts['title'] : ($champ['title'] ?? 'Championship');
    $sections = $champ['sections'] ?? array();
    $nonce           = wp_create_nonce('lgw_champ_nonce');
    $global_sponsors = get_option('lgw_sponsors', array());
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
        $img          = '<img src="' . esc_url($primary_sponsor['image']) . '" alt="' . esc_attr($primary_sponsor['name'] ?: 'Sponsor') . '" class="lgw-sponsor-img">';
        $primary_html = '<div class="lgw-sponsor-bar lgw-sponsor-primary">'
            . (!empty($primary_sponsor['url']) ? '<a href="' . esc_url($primary_sponsor['url']) . '" target="_blank" rel="noopener">' . $img . '</a>' : $img)
            . '</div>';
    }

    ob_start();
    echo $theme_style;
    ?>
    <div class="lgw-champ-tabs-outer" data-champ-id="<?php echo esc_attr($champ_id); ?>">
      <?php echo $primary_html; ?>
      <div class="lgw-champ-section-tabs">
        <?php foreach ($sections as $idx => $sec): ?>
        <button class="lgw-champ-section-tab<?php echo $idx === 0 ? ' active' : ''; ?>" data-section="<?php echo $idx; ?>">
          Section <?php echo esc_html($sec['label']); ?>
        </button>
        <?php endforeach; ?>
        <?php if (!empty($champ['final_bracket'])): ?>
        <button class="lgw-champ-section-tab" data-section="final">🏆 Final Stage</button>
        <?php endif; ?>
        <button class="lgw-champ-section-tab lgw-champ-search-tab"
                data-champ-id="<?php echo esc_attr($champ_id); ?>"
                title="Search fixtures and results">
          🔍 Search
        </button>
      </div>

      <?php foreach ($sections as $idx => $sec):
        $bracket_key = 'section_' . $idx . '_bracket';
        $bracket     = $champ[$bracket_key] ?? null;
        $version     = intval($champ['section_' . $idx . '_draw_version'] ?? 0);
        $in_progress = !empty($champ['section_' . $idx . '_draw_in_progress']) && !$bracket;
        $bracket_json = $bracket ? wp_json_encode($bracket) : '';
        $is_admin = current_user_can('manage_options');
        $draw_passphrase_set = get_option('lgw_draw_passphrase', '') !== '';
        $show_draw_btn = !$bracket && $draw_passphrase_set;
      ?>
      <div class="lgw-champ-section-pane<?php echo $idx === 0 ? ' active' : ''; ?>" data-section="<?php echo $idx; ?>">
        <div class="lgw-champ-wrap"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="<?php echo $idx; ?>"
             data-draw-version="<?php echo esc_attr($version); ?>"
             data-draw-in-progress="<?php echo ($in_progress) ? '1' : '0'; ?>"
             data-bracket="<?php echo esc_attr($bracket_json); ?>"
             data-sponsors="<?php echo $extra_json; ?>">

          <div class="lgw-champ-header">
            <span class="lgw-champ-title">🏅 <?php echo esc_html($title); ?> — Section <?php echo esc_html($sec['label']); ?></span>
            <?php if ($show_draw_btn): ?>
            <div class="lgw-champ-header-actions">
              <button class="lgw-champ-btn lgw-champ-btn-ghost lgw-champ-draw-login-btn">🔑 Login to Draw</button>
            </div>
            <?php elseif ($bracket): ?>
            <div class="lgw-champ-header-actions lgw-champ-post-draw-actions">
              <button class="lgw-champ-btn lgw-champ-btn-ghost lgw-champ-print-btn">🖨 Print Draw</button>
            </div>
            <?php endif; ?>
          </div>

          <div class="lgw-champ-tabs"><div class="lgw-champ-tabs-inner"></div></div>

          <div class="lgw-champ-bracket-outer">
            <?php if (!$bracket): ?>
            <div class="lgw-champ-empty">
              <div class="lgw-champ-empty-icon">🎲</div>
              <p><?php echo $is_admin ? 'No draw performed yet.' : 'The draw has not taken place yet. Check back soon!'; ?></p>
              <?php if (!empty($sec['entries'])): ?>
              <p class="lgw-entry-count"><?php echo count($sec['entries']); ?> entries</p>
              <?php echo lgw_render_entry_list($sec['entries'], true); ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="lgw-champ-bracket"></div>
          </div>

          <div class="lgw-champ-status">
            <span class="lgw-champ-status-dot<?php echo (!$bracket && $version == 0) ? ' live' : ''; ?>"></span>
            <span class="lgw-champ-status-text">
              <?php if (!$bracket): ?>Waiting for draw…
              <?php else: ?><?php echo count($sec['entries']); ?> entries · Round 1: <?php echo esc_html($champ['dates'][0] ?? 'TBC'); ?>
              <?php endif; ?>
            </span>
          </div>

          <?php if ($primary_sponsor && !empty($primary_sponsor['image'])): ?>
          <div class="lgw-print-footer" style="display:none">
            <?php if (!empty($primary_sponsor['name'])): ?>
              <div class="lgw-print-footer-label">Sponsored by</div>
            <?php endif; ?>
            <img src="<?php echo esc_url($primary_sponsor['image']); ?>" alt="<?php echo esc_attr($primary_sponsor['name'] ?: 'Sponsor'); ?>">
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>

      <?php if (!empty($champ['final_bracket'])):
        $fb           = $champ['final_bracket'];
        $fb_json      = wp_json_encode($fb);
        $fb_version   = intval($champ['final_draw_version'] ?? 0);
        $fb_in_prog   = !empty($champ['final_draw_in_progress']);
        $draw_passphrase_set = get_option('lgw_draw_passphrase', '') !== '';
      ?>
      <div class="lgw-champ-section-pane" data-section="final">
        <div class="lgw-champ-wrap"
             data-champ-id="<?php echo esc_attr($champ_id); ?>"
             data-section="final"
             data-draw-version="<?php echo esc_attr($fb_version); ?>"
             data-draw-in-progress="<?php echo $fb_in_prog ? '1' : '0'; ?>"
             data-bracket="<?php echo esc_attr($fb_json); ?>"
             data-sponsors="<?php echo $extra_json; ?>">

          <div class="lgw-champ-header">
            <span class="lgw-champ-title">🏆 <?php echo esc_html($title); ?> — Final Stage</span>
            <?php if ($draw_passphrase_set && !$fb): ?>
            <div class="lgw-champ-header-actions">
              <button class="lgw-champ-btn lgw-champ-btn-ghost lgw-champ-draw-login-btn">🔑 Login to Draw</button>
            </div>
            <?php elseif ($fb): ?>
            <div class="lgw-champ-header-actions lgw-champ-post-draw-actions">
              <button class="lgw-champ-btn lgw-champ-btn-ghost lgw-champ-print-btn">🖨 Print Draw</button>
            </div>
            <?php endif; ?>
          </div>

          <div class="lgw-champ-tabs"><div class="lgw-champ-tabs-inner"></div></div>
          <div class="lgw-champ-bracket-outer"><div class="lgw-champ-bracket"></div></div>
          <div class="lgw-champ-status">
            <span class="lgw-champ-status-dot"></span>
            <span class="lgw-champ-status-text">Final Stage</span>
          </div>

          <?php if ($primary_sponsor && !empty($primary_sponsor['image'])): ?>
          <div class="lgw-print-footer" style="display:none">
            <?php if (!empty($primary_sponsor['name'])): ?>
              <div class="lgw-print-footer-label">Sponsored by</div>
            <?php endif; ?>
            <img src="<?php echo esc_url($primary_sponsor['image']); ?>" alt="<?php echo esc_attr($primary_sponsor['name'] ?: 'Sponsor'); ?>">
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endif; ?>
    </div>

    <style>
    .lgw-champ-section-tabs { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:0; background:#e8edf4; padding:8px 12px 0; }
    .lgw-champ-section-tab  { padding:8px 18px; background:#fff; border:1px solid #d0d5e8; border-bottom:none; border-radius:4px 4px 0 0; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; color:#1a2e5a; }
    .lgw-champ-section-tab.active { background:#1a4e6e; color:#fff; border-color:#1a4e6e; }
    .lgw-champ-section-pane { display:none; }
    .lgw-champ-section-pane.active { display:block; }
    .lgw-champ-search-tab { margin-left:auto; background:#1a4e6e; color:#fff; border-color:#1a4e6e; }
    .lgw-champ-search-tab:hover { background:#1a2e5a; border-color:#1a2e5a; }
    </style>
    <?php
    return ob_get_clean();
}

// ── [lgw_finalists] shortcode ─────────────────────────────────────────────────
// Displays all finalists/semi-finalists for a given season across all championships.
// Usage: [lgw_finalists season="2025"]
// For each championship in the season:
//   1 section  → 4 semi-finalists (all reaching Finals Week)
//   2 sections → 2 finalists per section (4 total)
//   4 sections → 1 winner per section (4 total)
add_shortcode('lgw_finalists', 'lgw_finalists_shortcode');
function lgw_finalists_shortcode($atts) {
    $atts = shortcode_atts(array(
        'season' => '',
        'title'  => '',
    ), $atts);

    $season = trim($atts['season']);
    if (!$season) return '<p>No season specified for <code>[lgw_finalists]</code>.</p>';

    // Load all championships
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_champ_%' ORDER BY option_name"
    );
    $champs = array();
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (is_array($val) && isset($val['title']) && ($val['season'] ?? '') === $season) {
            $champs[$id] = $val;
        }
    }

    if (empty($champs)) {
        return '<p>No championships found for season <strong>' . esc_html($season) . '</strong>.</p>';
    }

    // Badges
    $club_badges = get_option('lgw_club_badges', array()); // keyed by lowercase club name
    $team_badges = get_option('lgw_badges',      array()); // keyed by team name

    // Helper: get badge URL for an entry string ("Player, Club")
    $badge_url = function($entry) use ($club_badges, $team_badges) {
        // Try team badge first
        foreach ($team_badges as $team => $url) {
            if (stripos($entry, $team) !== false) return $url;
        }
        // Fall back to club badge
        $club = strtolower(trim(explode(',', $entry, 2)[1] ?? ''));
        return $club_badges[$club] ?? '';
    };

    // Helper: render a single finalist entry
    $render_entry = function($entry, $label = '') use ($badge_url) {
        $url  = $badge_url($entry);
        $img  = $url ? '<img src="' . esc_url($url) . '" alt="" class="lgw-finalists-badge">' : '<span class="lgw-finalists-badge-placeholder"></span>';
        $name = esc_html($entry);
        return '<div class="lgw-finalists-entry">'
             . $img
             . '<span class="lgw-finalists-name">' . $name . '</span>'
             . ($label ? '<span class="lgw-finalists-label">' . esc_html($label) . '</span>' : '')
             . '</div>';
    };

    ob_start();
    $heading = $atts['title'] ?: esc_html($season) . ' Finals';
    ?>
    <div class="lgw-finalists-wrap">
      <div class="lgw-finalists-heading"><?php echo esc_html($heading); ?></div>
      <?php foreach ($champs as $id => $champ):
        $n_sections  = count($champ['sections'] ?? array());
        $title       = $champ['title'] ?? $id;
        $discipline  = ucfirst($champ['discipline'] ?? 'singles');

        // Determine qualifier mode and label
        if ($n_sections === 1) {
            // Show 4 semi-finalists
            $mode        = 'semis';     // q_per_section=4 on single section
            $q_per_sec   = 4;
            $stage_label = 'Semi-finalists';
        } elseif ($n_sections === 2) {
            // Show both finalists per section (2 × 2 = 4)
            $mode        = 'finalists';
            $q_per_sec   = 2;
            $stage_label = 'Finalists';
        } else {
            // 4 sections: show section winner (4 × 1 = 4)
            $mode        = 'winners';
            $q_per_sec   = 1;
            $stage_label = 'Section Winners';
        }

        // Collect finalists across sections
        $finalists = array();
        $pending   = false;
        foreach (($champ['sections'] ?? array()) as $idx => $sec) {
            $bracket_key = 'section_' . $idx . '_bracket';
            $bracket     = $champ[$bracket_key] ?? null;
            if (!$bracket) { $pending = true; continue; }
            $q = lgw_champ_get_section_qualifiers($bracket, $q_per_sec);
            if ($q === null) { $pending = true; continue; }
            foreach ($q as $entry) {
                $finalists[] = array('entry' => $entry, 'section' => $sec['label'] ?? ($idx + 1));
            }
        }
        ?>
        <div class="lgw-finalists-champ">
          <div class="lgw-finalists-champ-header">
            <span class="lgw-finalists-champ-title"><?php echo esc_html($title); ?></span>
            <span class="lgw-finalists-champ-stage"><?php echo esc_html($stage_label); ?></span>
          </div>
          <?php if (empty($finalists) && $pending): ?>
            <div class="lgw-finalists-pending">⏳ Draw not yet complete</div>
          <?php else: ?>
          <div class="lgw-finalists-entries">
            <?php
            $shown_sections = array();
            foreach ($finalists as $f):
                // Show section divider for multi-section comps
                if ($n_sections > 1 && !in_array($f['section'], $shown_sections)):
                    $shown_sections[] = $f['section'];
                    echo '<div class="lgw-finalists-section-label">Section ' . esc_html($f['section']) . '</div>';
                endif;
                echo $render_entry($f['entry']);
            endforeach;
            if ($pending): ?>
              <div class="lgw-finalists-pending-partial">⏳ Some results still pending</div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
