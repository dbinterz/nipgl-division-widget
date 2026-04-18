<?php
/**
 * LGW Scorecard Feature - v7.1.27
 * Per-club passphrase auth, two-party submission, confirm/amend/dispute flow.
 */

// ── Custom Post Type ──────────────────────────────────────────────────────────
add_action('init', 'lgw_register_scorecard_cpt');
function lgw_register_scorecard_cpt() {
    register_post_type('lgw_scorecard', array(
        'labels'       => array('name' => 'Scorecards', 'singular_name' => 'Scorecard'),
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => array('title'),
        'capabilities' => array('create_posts' => 'manage_options'),
        'map_meta_cap' => true,
    ));
}

// ── Session helpers ───────────────────────────────────────────────────────────
function lgw_session_start() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
}
function lgw_get_auth_club() {
    lgw_session_start();
    return $_SESSION['lgw_club'] ?? '';
}
function lgw_passphrase_verified() {
    return (bool) lgw_get_auth_club();
}

// ── Club helpers ──────────────────────────────────────────────────────────────
function lgw_get_clubs() {
    return get_option('lgw_clubs', array());
    // array of ['name'=>'Ards','pin'=>'<sha256 of passphrase>']
}

function lgw_club_matches_team($club, $team) {
    // Club "Ards" matches team "Ards A", "Ards B", "Ards" etc.
    $club = strtoupper(trim($club));
    $team = strtoupper(trim($team));
    if ($club === $team) return true;
    if (strpos($team, $club) === 0) {
        $rest = substr($team, strlen($club));
        return $rest === '' || $rest[0] === ' ';
    }
    return false;
}

function lgw_club_involved($club, $home_team, $away_team) {
    return lgw_club_matches_team($club, $home_team) ||
           lgw_club_matches_team($club, $away_team);
}

// ── Scorecard lookup ──────────────────────────────────────────────────────────
function lgw_get_scorecard($home, $away, $date = '', $context = '') {
    // Build meta query — always scope by context when provided, to prevent
    // cup scorecards matching league lookups and vice versa.
    $meta_query = array();
    if ($context !== '') {
        $meta_query = array(
            array('key' => 'lgw_sc_context', 'value' => $context, 'compare' => '='),
        );
    }

    // Primary: match by sanitized key (home+away slug)
    $key   = sanitize_title($home . '-' . $away);
    $args  = array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_key'       => 'lgw_match_key',
        'meta_value'     => $key,
    );
    if (!empty($meta_query)) $args['meta_query'] = $meta_query;
    $posts = get_posts($args);
    if (!empty($posts)) return $posts[0];

    // Fallback: scan recent scorecards and match on normalised team names
    $all_args = array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if (!empty($meta_query)) $all_args['meta_query'] = $meta_query;
    $all = get_posts($all_args);
    $home_norm = lgw_normalise_team($home);
    $away_norm = lgw_normalise_team($away);
    foreach ($all as $p) {
        $sc = get_post_meta($p->ID, 'lgw_scorecard_data', true);
        if (!$sc) continue;
        if (lgw_normalise_team($sc['home_team'] ?? '') === $home_norm &&
            lgw_normalise_team($sc['away_team'] ?? '') === $away_norm) {
            return $p;
        }
    }
    return null;
}

/**
 * Normalise a team name for fuzzy matching:
 * lowercased, punctuation removed, whitespace collapsed.
 * "U. Transport A" → "u transport a"
 * "Ulster Transport A" → "ulster transport a"
 * These won't match each other, but "U. Transport A" stored == "U. Transport A" from CSV will.
 */
function lgw_normalise_team($name) {
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s]/', '', $name); // strip punctuation inc dots
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

function lgw_get_scorecard_data($post_id) {
    return get_post_meta($post_id, 'lgw_scorecard_data', true);
}

// ── AJAX: Passphrase / club login ─────────────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_check_pin', 'lgw_ajax_check_pin');
add_action('wp_ajax_lgw_check_pin',        'lgw_ajax_check_pin');
function lgw_ajax_check_pin() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    $club_name = sanitize_text_field($_POST['club'] ?? '');
    // Accept both 'pin' (legacy) and 'passphrase' field names
    $entered   = sanitize_text_field($_POST['passphrase'] ?? $_POST['pin'] ?? '');
    // Normalise: lowercase, collapse whitespace, trim
    $entered   = strtolower(trim(preg_replace('/\s+/', ' ', $entered)));
    $clubs     = lgw_get_clubs();

    foreach ($clubs as $club) {
        if (strtolower($club['name']) === strtolower($club_name)) {
            if (!empty($club['pin']) && hash_equals($club['pin'], hash('sha256', $entered))) {
                lgw_session_start();
                $_SESSION['lgw_club'] = $club['name'];
                $pending = lgw_get_pending_for_club($club['name']);
                wp_send_json_success(array(
                    'club'    => $club['name'],
                    'pending' => $pending,
                ));
            }
        }
    }
    wp_send_json_error('Incorrect club or passphrase');
}

// ── AJAX: Logout ──────────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_logout', 'lgw_ajax_logout');
add_action('wp_ajax_lgw_logout',        'lgw_ajax_logout');
function lgw_ajax_logout() {
    lgw_session_start();
    unset($_SESSION['lgw_club']);
    wp_send_json_success();
}

// ── Pending scorecard lookup for a club ───────────────────────────────────────
function lgw_get_pending_for_club($club) {
    $posts = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 20,
        'post_status'    => 'publish',
        'meta_key'       => 'lgw_sc_status',
        'meta_value'     => 'pending',
    ));
    $pending = array();
    foreach ($posts as $p) {
        $sc = lgw_get_scorecard_data($p->ID);
        if (!$sc) continue;
        // Only show if this club is the OTHER team (not the one who submitted)
        $submitted_by = get_post_meta($p->ID, 'lgw_submitted_by', true);
        if (lgw_club_involved($club, $sc['home_team'], $sc['away_team']) &&
            !lgw_club_matches_team($club, $submitted_by)) {
            $pending[] = array(
                'id'        => $p->ID,
                'home_team' => $sc['home_team'],
                'away_team' => $sc['away_team'],
                'date'      => $sc['date'],
                'submitted_by' => $submitted_by,
            );
        }
    }
    return $pending;
}

// ── AJAX: Get full scorecard for review ───────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_get_scorecard_by_id', 'lgw_ajax_get_scorecard_by_id');
add_action('wp_ajax_lgw_get_scorecard_by_id',        'lgw_ajax_get_scorecard_by_id');
function lgw_ajax_get_scorecard_by_id() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing ID');
    $sc = lgw_get_scorecard_data($id);
    if (!$sc) wp_send_json_error('Not found');
    $sc['_status']       = get_post_meta($id, 'lgw_sc_status',     true);
    $sc['_submitted_by'] = get_post_meta($id, 'lgw_submitted_by',  true);
    $sc['_confirmed_by'] = get_post_meta($id, 'lgw_confirmed_by',  true);
    $sc['_away_version'] = get_post_meta($id, 'lgw_away_scorecard', true);
    wp_send_json_success($sc);
}

// ── AJAX: Confirm scorecard ───────────────────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_confirm_scorecard', 'lgw_ajax_confirm_scorecard');
add_action('wp_ajax_lgw_confirm_scorecard',        'lgw_ajax_confirm_scorecard');
function lgw_ajax_confirm_scorecard() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!lgw_passphrase_verified()) wp_send_json_error('Not authorised');

    $id   = intval($_POST['id'] ?? 0);
    $club = lgw_get_auth_club();
    if (!$id) wp_send_json_error('Missing ID');

    $sc = lgw_get_scorecard_data($id);
    if (!$sc) wp_send_json_error('Scorecard not found');
    if (!lgw_club_involved($club, $sc['home_team'], $sc['away_team']))
        wp_send_json_error('Your club is not involved in this match');

    update_post_meta($id, 'lgw_sc_status',    'confirmed');
    update_post_meta($id, 'lgw_confirmed_by', $club);
    // Clear division-unresolved flag if the division maps to a known sheet tab
    $drive_opts_conf  = get_option('lgw_drive', array());
    $resolved_tab_conf = lgw_sheets_tab_for_division($sc['division'] ?? '', $drive_opts_conf);
    if (!empty($sc['division']) && $resolved_tab_conf) {
        delete_post_meta($id, 'lgw_division_unresolved');
    }
    lgw_log_appearances($id);
    lgw_audit_log($id, 'confirmed', 'Confirmed by ' . $club);
    do_action('lgw_scorecard_confirmed', $id);
    wp_send_json_success(array('message' => 'Scorecard confirmed. Thank you!'));
}

// ── AJAX: Parse photo via Anthropic vision ────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_parse_photo', 'lgw_ajax_parse_photo');
add_action('wp_ajax_lgw_parse_photo',        'lgw_ajax_parse_photo');
function lgw_ajax_parse_photo() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!lgw_passphrase_verified() && !current_user_can('manage_options'))
        wp_send_json_error('Not authorised — please log in with your club passphrase first.');

    $api_key = get_option('lgw_anthropic_key', '');
    if (!$api_key) wp_send_json_error('No API key configured. Add your Anthropic API key in LGW Widget Settings.');

    if (empty($_FILES['photo']['tmp_name'])) wp_send_json_error('No file received');
    $file    = $_FILES['photo'];
    $allowed = array('image/jpeg','image/png','image/gif','image/webp');
    if (!in_array($file['type'], $allowed)) wp_send_json_error('Please upload a JPG, PNG or WebP image');
    if ($file['size'] > 8 * 1024 * 1024)   wp_send_json_error('Image too large (max 8MB)');

    $image_data = base64_encode(file_get_contents($file['tmp_name']));
    $media_type = $file['type'];

    // Save original photo to uploads dir so it can be sent to Drive on confirmation
    $upload_dir  = wp_upload_dir();
    $photo_dir   = trailingslashit($upload_dir['basedir']) . 'lgw-scorecards/';
    if (!file_exists($photo_dir)) wp_mkdir_p($photo_dir);
    $photo_ext   = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $photo_fname = 'sc-photo-' . lgw_get_auth_club() . '-' . time() . '.' . $photo_ext;
    $photo_path  = $photo_dir . $photo_fname;
    move_uploaded_file($file['tmp_name'], $photo_path);
    // Store temporarily — will be attached to post in save_scorecard
    set_transient('lgw_photo_' . lgw_get_auth_club(), $photo_path, HOUR_IN_SECONDS);

    $prompt = 'This is a bowls match scorecard. Extract all data and return ONLY valid JSON (no markdown, no explanation) in exactly this structure:
{
  "division": "string",
  "venue": "string",
  "date": "string in dd/mm/yyyy format",
  "home_team": "string",
  "away_team": "string",
  "rinks": [
    {
      "rink": 1,
      "home_players": ["name1","name2","name3","name4"],
      "away_players": ["name1","name2","name3","name4"],
      "home_score": number_or_null,
      "away_score": number_or_null
    }
  ],
  "home_total": number_or_null,
  "away_total": number_or_null,
  "home_points": number_or_null,
  "away_points": number_or_null
}
Return exactly 4 rink objects. Use null for any value you cannot read clearly.';

    $body = json_encode(array(
        'model'      => 'claude-sonnet-4-5',
        'max_tokens' => 2000,
        'messages'   => array(array(
            'role'    => 'user',
            'content' => array(
                array('type' => 'image', 'source' => array(
                    'type'       => 'base64',
                    'media_type' => $media_type,
                    'data'       => $image_data,
                )),
                array('type' => 'text', 'text' => $prompt),
            ),
        )),
    ));

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 40,
        'headers' => array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => $body,
    ));

    if (is_wp_error($response)) wp_send_json_error('API request failed: ' . $response->get_error_message());

    $http_code = wp_remote_retrieve_response_code($response);
    $result    = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200) {
        $api_error = $result['error']['message'] ?? 'Unknown API error';
        wp_send_json_error('API error (' . $http_code . '): ' . $api_error);
    }

    $text = $result['content'][0]['text'] ?? '';
    if (empty($text)) {
        wp_send_json_error('Empty response from API (stop_reason: ' . ($result['stop_reason'] ?? 'unknown') . '). Please try again.');
    }

    $text   = preg_replace('/^```json\s*/i', '', trim($text));
    $text   = preg_replace('/^```\s*/i',     '', $text);
    $text   = preg_replace('/```\s*$/',      '', $text);
    $parsed = json_decode(trim($text), true);

    if (!$parsed || !isset($parsed['rinks'])) {
        wp_send_json_error('Could not parse response. Raw: ' . substr($text, 0, 300));
    }
    wp_send_json_success($parsed);
}

// ── AJAX: Parse Excel ─────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_parse_excel', 'lgw_ajax_parse_excel');
add_action('wp_ajax_lgw_parse_excel',        'lgw_ajax_parse_excel');
function lgw_ajax_parse_excel() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!lgw_passphrase_verified() && !current_user_can('manage_options'))
        wp_send_json_error('Not authorised — please log in with your club passphrase first.');

    if (empty($_FILES['excel']['tmp_name'])) wp_send_json_error('No file received');
    $file = $_FILES['excel'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('xlsx','xls'))) wp_send_json_error('Please upload an .xlsx or .xls file');

    $data = lgw_parse_xlsx_basic($file['tmp_name']);
    if (!$data) wp_send_json_error('Could not read spreadsheet. Please check the file is a valid .xlsx and try again.');

    $parsed = lgw_map_xlsx_to_scorecard($data);
    if (!$parsed) wp_send_json_error('Could not map spreadsheet to scorecard format. Please check the file uses the standard LGW scorecard template.');
    wp_send_json_success($parsed);
}

function lgw_parse_xlsx_basic($filepath) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) return false;

    $strings = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m);
        $strings = array_map('html_entity_decode', $m[1]);
    }

    // Find the first sheet — try sheet1.xml, then scan for any sheet
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/sheet') !== false && substr($name, -4) === '.xml') {
                $sheet_xml = $zip->getFromName($name);
                if ($sheet_xml !== false) break;
            }
        }
    }
    $zip->close();
    if (!$sheet_xml) return false;

    // Parse cells by splitting on </c> — avoids PCRE inconsistencies across PHP versions.
    // Each chunk contains at most one real cell. Self-closing empty cells have no <v> tag and are skipped.
    $grid  = array();
    $parts = explode('</c>', $sheet_xml);
    foreach ($parts as $part) {
        $c_pos = strrpos($part, '<c ');
        if ($c_pos === false) continue;
        $cell = substr($part, $c_pos);
        if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cell, $ref)) continue;
        $col     = lgw_col_to_idx($ref[1]);
        $row_num = intval($ref[2]);
        $type    = '';
        if (preg_match('/\bt="([^"]*)"/', $cell, $tm)) $type = $tm[1];
        if (!preg_match('/<v>(.*?)<\/v>/s', $cell, $vm)) continue;
        $val      = $vm[1];
        $resolved = ($type === 's') ? ($strings[intval($val)] ?? '') : $val;
        $grid[$row_num][$col] = $resolved;
    }

    if (empty($grid)) return false;
    ksort($grid);
    $data = array();
    foreach ($grid as $row_num => $row) {
        $max = max(array_keys($row));
        $out = array();
        for ($i = 0; $i <= $max; $i++) $out[] = $row[$i] ?? '';
        $data[] = $out;
    }
    return $data;
}

function lgw_col_to_idx($col) {
    $idx = 0;
    foreach (str_split($col) as $c) $idx = $idx * 26 + (ord($c) - 64);
    return $idx - 1;
}

function lgw_map_xlsx_to_scorecard($data) {
    if (empty($data)) return null;
    $sc = array('division'=>'','venue'=>'','date'=>'','home_team'=>'','away_team'=>'',
                'rinks'=>array(),'home_total'=>null,'away_total'=>null,'home_points'=>null,'away_points'=>null);

    foreach ($data as $i => $row) {
        $r0 = trim($row[0] ?? '');
        $r1 = trim($row[1] ?? '');

        // Division
        if ($r0 === 'Division/Cup' || $r0 === 'Division') $sc['division'] = $r1;

        // Venue and date — date may be an Excel serial number
        if ($r0 === 'Played at') {
            $sc['venue'] = $r1;
            $raw_date = trim($row[5] ?? $row[4] ?? '');
            // Convert Excel date serial to readable string if numeric
            if (is_numeric($raw_date) && floatval($raw_date) > 40000) {
                $unix = ($raw_date - 25569) * 86400;
                $sc['date'] = date('d/m/Y', intval($unix));
            } else {
                $sc['date'] = $raw_date;
            }
        }

        // Skip header rows
        if ($r0 === 'Home Team') continue;

        // Team names — support both layouts:
        // Layout A: col A = home, col D(3) = 'v', col E(4) = away
        // Layout B: col A = home, col D(3) = 'v', col E(4) = away  (same)
        if (!empty($r0) && isset($row[3]) && trim($row[3]) === 'v' && !empty($row[4])) {
            $sc['home_team'] = $r0;
            $sc['away_team'] = trim($row[4]);
        }

        // Rink header — may be in col A(0) or col D(3) depending on template version
        // Check both positions
        $rink_col = -1;
        $rink_num = 0;
        for ($col = 0; $col <= min(3, count($row)-1); $col++) {
            if (preg_match('/^Rink\s*(\d)/i', trim($row[$col] ?? ''), $rm)) {
                $rink_col = $col;
                $rink_num = intval($rm[1]);
                break;
            }
        }

        if ($rink_num > 0) {
            $rink = array('rink'=>$rink_num,'home_players'=>array(),'away_players'=>array(),'home_score'=>null,'away_score'=>null);
            // Read next 4 player rows
            for ($p = $i+1; $p <= $i+4 && $p < count($data); $p++) {
                $pr = $data[$p];
                // Stop if we hit another Rink header
                $is_rink_row = false;
                for ($c2 = 0; $c2 <= min(3, count($pr)-1); $c2++) {
                    if (preg_match('/^Rink\s*\d/i', trim($pr[$c2] ?? ''))) { $is_rink_row = true; break; }
                }
                if ($is_rink_row) break;

                // Home player always in col A(0)
                $hp = trim($pr[0] ?? '');
                if ($hp && !preg_match('/^(Total|Points|Signed)/i', $hp)) $rink['home_players'][] = $hp;

                // Away player in col D(3) — the standard position in both template versions
                $ap = trim($pr[3] ?? '');
                if ($ap && trim($ap) !== 'v' && !preg_match('/^(Total|Points|Signed|Rink)/i', $ap)) $rink['away_players'][] = $ap;

                // Home score in col C(2)
                if (isset($pr[2]) && is_numeric($pr[2])) $rink['home_score'] = floatval($pr[2]);
                // Away score in col G(6) — fall back to col E(4) for compact templates  
                if (isset($pr[6]) && is_numeric($pr[6]))      $rink['away_score'] = floatval($pr[6]);
                elseif (isset($pr[4]) && is_numeric($pr[4]) && !isset($pr[6])) $rink['away_score'] = floatval($pr[4]);
            }
            $sc['rinks'][] = $rink;
        }

        // Totals — 'Total Shots' can be in col A or col B
        if (stripos($r0, 'Total Shots') !== false || stripos($r1, 'Total Shots') !== false) {
            $sc['home_total'] = is_numeric($row[2]??'') ? floatval($row[2]) : null;
            // Away total in col F(5) or G(6)
            if (is_numeric($row[6]??''))      $sc['away_total'] = floatval($row[6]);
            elseif (is_numeric($row[5]??''))  $sc['away_total'] = floatval($row[5]);
        }

        // Points — same layout as totals
        if ($r0 === 'Points' || $r1 === 'Points') {
            $sc['home_points'] = is_numeric($row[2]??'') ? floatval($row[2]) : null;
            if (is_numeric($row[6]??''))      $sc['away_points'] = floatval($row[6]);
            elseif (is_numeric($row[5]??''))  $sc['away_points'] = floatval($row[5]);
        }
    }

    if (empty($sc['rinks'])) return null;

    // Fallback: if totals are null (e.g. unresolved formula), sum from rink scores
    if ($sc['home_total'] === null) {
        $sum = 0; $ok = true;
        foreach ($sc['rinks'] as $rk) {
            if ($rk['home_score'] === null) { $ok = false; break; }
            $sum += $rk['home_score'];
        }
        if ($ok) $sc['home_total'] = $sum;
    }
    if ($sc['away_total'] === null) {
        $sum = 0; $ok = true;
        foreach ($sc['rinks'] as $rk) {
            if ($rk['away_score'] === null) { $ok = false; break; }
            $sum += $rk['away_score'];
        }
        if ($ok) $sc['away_total'] = $sum;
    }

    return $sc;
}

// ── AJAX: Save scorecard ──────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_lgw_save_scorecard', 'lgw_ajax_save_scorecard');
add_action('wp_ajax_lgw_save_scorecard',        'lgw_ajax_save_scorecard');
function lgw_ajax_save_scorecard() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');

    $submission_mode = get_option('lgw_submission_mode', 'open');
    $is_admin        = current_user_can('manage_options');

    // Gate by submission mode
    if ($submission_mode === 'disabled') {
        wp_send_json_error('Scorecard submission is currently disabled.');
    }
    if ($submission_mode === 'admin_only' && !$is_admin) {
        wp_send_json_error('Scorecard submission is currently restricted to league administrators.');
    }

    // Admins bypass passphrase auth; clubs still require it
    if (!$is_admin && !lgw_passphrase_verified()) {
        wp_send_json_error('Not authorised');
    }

    $club        = $is_admin ? '__admin__' : lgw_get_auth_club();
    // submitted_for: 'home' | 'away' | 'both' — only meaningful for admin submissions
    $submitted_for = $is_admin ? sanitize_text_field($_POST['submitted_for'] ?? 'home') : 'home';
    if (!in_array($submitted_for, array('home', 'away', 'both'))) $submitted_for = 'home';

    $raw_string  = stripslashes($_POST['scorecard'] ?? '');
    $raw         = json_decode($raw_string, true);
    if (!$raw) {
        $json_err = json_last_error_msg();
        $preview  = $raw_string === '' ? '(empty)' : '"' . mb_substr($raw_string, 0, 120) . (mb_strlen($raw_string) > 120 ? '…' : '') . '"';
        wp_send_json_error('Invalid scorecard data — could not parse submission (' . $json_err . '). Received: ' . $preview);
    }

    $sc = array(
        'division'     => sanitize_text_field($raw['division']     ?? ''),
        'venue'        => sanitize_text_field($raw['venue']        ?? ''),
        'date'         => sanitize_text_field($raw['date']         ?? ''),
        'fixture_date' => sanitize_text_field($raw['fixture_date'] ?? ''),
        'home_team'    => sanitize_text_field($raw['home_team']    ?? ''),
        'away_team'    => sanitize_text_field($raw['away_team']    ?? ''),
        'submitter'    => sanitize_text_field($raw['submitter']    ?? ''),
        'home_total'  => is_numeric($raw['home_total']  ?? '') ? floatval($raw['home_total'])  : null,
        'away_total'  => is_numeric($raw['away_total']  ?? '') ? floatval($raw['away_total'])  : null,
        'home_points' => is_numeric($raw['home_points'] ?? '') ? floatval($raw['home_points']) : null,
        'away_points' => is_numeric($raw['away_points'] ?? '') ? floatval($raw['away_points']) : null,
        'rinks'       => array(),
    );
    foreach (($raw['rinks'] ?? array()) as $rk) {
        $sc['rinks'][] = array(
            'rink'         => intval($rk['rink'] ?? 0),
            'home_players' => array_map('sanitize_text_field', (array)($rk['home_players'] ?? array())),
            'away_players' => array_map('sanitize_text_field', (array)($rk['away_players'] ?? array())),
            'home_score'   => is_numeric($rk['home_score'] ?? '') ? intval($rk['home_score']) : null,
            'away_score'   => is_numeric($rk['away_score'] ?? '') ? intval($rk['away_score']) : null,
        );
    }

    if (empty($sc['home_team']) || empty($sc['away_team'])) wp_send_json_error('Home and away team are required');

    // Club auth check — clubs must be involved; admins are always allowed
    if (!$is_admin && !lgw_club_involved($club, $sc['home_team'], $sc['away_team']))
        wp_send_json_error('You can only submit scorecards for matches involving ' . $club);

    // For admin "both teams" submission: immediately auto-confirm
    if ($is_admin && $submitted_for === 'both') {
        return lgw_save_scorecard_admin_both($sc);
    }

    // For admin "home" or "away" submissions, use the team name as the submitter identity
    if ($is_admin) {
        $club = ($submitted_for === 'away') ? $sc['away_team'] : $sc['home_team'];
    }

    $match_key = sanitize_title($sc['home_team'] . '-' . $sc['away_team']);
    $sc_context = sanitize_key($raw['context'] ?? 'league');
    $existing  = lgw_get_scorecard($sc['home_team'], $sc['away_team'], $sc['date'], $sc_context);

    if ($existing) {
        $existing_status = get_post_meta($existing->ID, 'lgw_sc_status', true);
        $submitted_by    = get_post_meta($existing->ID, 'lgw_submitted_by', true);

        if (lgw_club_matches_team($club, $submitted_by)) {
            // Same club resubmitting — update their version
            if ($existing_status === 'confirmed') {
                update_post_meta($existing->ID, 'lgw_sc_status', 'pending');
                update_post_meta($existing->ID, 'lgw_confirmed_by', '');
            }
            update_post_meta($existing->ID, 'lgw_scorecard_data', $sc);
            if (!empty($sc['fixture_date'])) update_post_meta($existing->ID, 'lgw_fixture_date', $sc['fixture_date']);
            wp_send_json_success(array('message' => 'Scorecard updated. Awaiting confirmation from the other club.', 'status' => 'pending'));
        } else {
            // Second club submitting — compare scores
            $original = lgw_get_scorecard_data($existing->ID);
            if (lgw_scores_match($sc, $original)) {
                // Scores agree — confirm
                update_post_meta($existing->ID, 'lgw_sc_status',    'confirmed');
                update_post_meta($existing->ID, 'lgw_confirmed_by', $club);
                lgw_log_appearances($existing->ID);
                lgw_audit_log($existing->ID, 'confirmed', 'Confirmed by ' . $club . ' — scores matched');
                do_action('lgw_scorecard_confirmed', $existing->ID);
                wp_send_json_success(array('message' => 'Scores match — scorecard confirmed! ✅', 'status' => 'confirmed'));
            } else {
                // Scores differ — store away version, mark disputed
                update_post_meta($existing->ID, 'lgw_away_scorecard', $sc);
                update_post_meta($existing->ID, 'lgw_sc_status',      'disputed');
                update_post_meta($existing->ID, 'lgw_confirmed_by',   $club);
                wp_send_json_success(array('message' => 'Scores differ from the submitted version — result marked as disputed. The league admin will review.', 'status' => 'disputed'));
            }
        }
    } else {
        // First submission
        $title   = $sc['home_team'] . ' v ' . $sc['away_team'] . ' (' . $sc['date'] . ')';
        $post_id = wp_insert_post(array(
            'post_type'   => 'lgw_scorecard',
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
        if (is_wp_error($post_id)) wp_send_json_error('The scorecard could not be saved to the database: ' . $post_id->get_error_message() . '. Please try again or contact the league administrator.');
        update_post_meta($post_id, 'lgw_match_key',     $match_key);
        update_post_meta($post_id, 'lgw_scorecard_data',$sc);
        if (!empty($sc['fixture_date'])) update_post_meta($post_id, 'lgw_fixture_date', $sc['fixture_date']);
        // Tag context (league/cup) so lookups don't cross-match
        $ctx = sanitize_key($raw['context'] ?? 'league');
        update_post_meta($post_id, 'lgw_sc_context', $ctx ?: 'league');
        update_post_meta($post_id, 'lgw_sc_status',     'pending');
        update_post_meta($post_id, 'lgw_submitted_by',  $club);
        // Tag with the active season so scorecards are attributable per season
        if (function_exists('lgw_get_active_season_id')) {
            $season_id = lgw_get_active_season_id();
            if ($season_id) update_post_meta($post_id, 'lgw_sc_season', $season_id);
        }
        lgw_audit_log($post_id, 'submitted', 'Submitted by ' . $club . ($is_admin ? ' (admin, on behalf of ' . $submitted_for . ' team)' : ''));
        // Flag if division is missing or doesn't map to a known sheet tab
        $drive_opts = get_option('lgw_drive', array());
        $resolved_tab = lgw_sheets_tab_for_division($sc['division'], $drive_opts);
        if (empty($sc['division']) || !$resolved_tab) {
            update_post_meta($post_id, 'lgw_division_unresolved', 1);
        }
        // Attach photo if one was parsed this session
        $photo_path = get_transient('lgw_photo_' . $club);
        if ($photo_path) {
            update_post_meta($post_id, 'lgw_photo_path', $photo_path);
            delete_transient('lgw_photo_' . $club);
        }
        wp_send_json_success(array(
            'message'             => 'Scorecard submitted. The other club will be notified to confirm.',
            'status'              => 'pending',
            'id'                  => $post_id,
            'division_unresolved' => (empty($sc['division']) || !$resolved_tab) ? 1 : 0,
        ));
    }
}

// ── Admin "both teams" submission — immediately confirmed ─────────────────────
function lgw_save_scorecard_admin_both($sc) {
    $match_key  = sanitize_title($sc['home_team'] . '-' . $sc['away_team']);
    $sc_context = sanitize_key($sc['context'] ?? 'league');
    $existing   = lgw_get_scorecard($sc['home_team'], $sc['away_team'], $sc['date'], $sc_context);
    $admin_login = wp_get_current_user()->user_login;

    if ($existing) {
        // Overwrite existing with confirmed status
        update_post_meta($existing->ID, 'lgw_scorecard_data', $sc);
        update_post_meta($existing->ID, 'lgw_sc_status',      'confirmed');
        update_post_meta($existing->ID, 'lgw_submitted_by',   $sc['home_team']);
        update_post_meta($existing->ID, 'lgw_confirmed_by',   $sc['away_team']);
        update_post_meta($existing->ID, 'lgw_submitted_for',  'both');
        if (!empty($sc['fixture_date'])) update_post_meta($existing->ID, 'lgw_fixture_date', $sc['fixture_date']);
        lgw_log_appearances($existing->ID);
        lgw_audit_log($existing->ID, 'confirmed', 'Admin submitted for both teams (' . $admin_login . ') — auto-confirmed');
        do_action('lgw_scorecard_confirmed', $existing->ID);
        wp_send_json_success(array(
            'message' => 'Scorecard submitted and confirmed for both teams. ✅',
            'status'  => 'confirmed',
            'id'      => $existing->ID,
        ));
    }

    $title   = $sc['home_team'] . ' v ' . $sc['away_team'] . ' (' . $sc['date'] . ')';
    $post_id = wp_insert_post(array(
        'post_type'   => 'lgw_scorecard',
        'post_title'  => $title,
        'post_status' => 'publish',
    ));
    if (is_wp_error($post_id)) wp_send_json_error('Could not save scorecard: ' . $post_id->get_error_message());

    update_post_meta($post_id, 'lgw_match_key',     $match_key);
    update_post_meta($post_id, 'lgw_scorecard_data', $sc);
    update_post_meta($post_id, 'lgw_sc_status',      'confirmed');
    update_post_meta($post_id, 'lgw_submitted_by',   $sc['home_team']);
    update_post_meta($post_id, 'lgw_confirmed_by',   $sc['away_team']);
    update_post_meta($post_id, 'lgw_submitted_for',  'both');
    update_post_meta($post_id, 'lgw_sc_context',     $sc_context);
    if (!empty($sc['fixture_date'])) update_post_meta($post_id, 'lgw_fixture_date', $sc['fixture_date']);

    if (function_exists('lgw_get_active_season_id')) {
        $season_id = lgw_get_active_season_id();
        if ($season_id) update_post_meta($post_id, 'lgw_sc_season', $season_id);
    }

    $drive_opts   = get_option('lgw_drive', array());
    $resolved_tab = lgw_sheets_tab_for_division($sc['division'], $drive_opts);
    if (empty($sc['division']) || !$resolved_tab) {
        update_post_meta($post_id, 'lgw_division_unresolved', 1);
    }

    lgw_log_appearances($post_id);
    lgw_audit_log($post_id, 'confirmed', 'Admin submitted for both teams (' . $admin_login . ') — auto-confirmed');
    do_action('lgw_scorecard_confirmed', $post_id);

    wp_send_json_success(array(
        'message'             => 'Scorecard submitted and confirmed for both teams. ✅',
        'status'              => 'confirmed',
        'id'                  => $post_id,
        'division_unresolved' => (empty($sc['division']) || !$resolved_tab) ? 1 : 0,
    ));
}

function lgw_scores_match($a, $b) {
    if (!$a || !$b) return false;
    if ($a['home_total'] != $b['home_total']) return false;
    if ($a['away_total'] != $b['away_total']) return false;
    if ($a['home_points'] != $b['home_points']) return false;
    if ($a['away_points'] != $b['away_points']) return false;
    foreach (($a['rinks'] ?? array()) as $i => $rk) {
        $brk = $b['rinks'][$i] ?? null;
        if (!$brk) return false;
        if ($rk['home_score'] != $brk['home_score']) return false;
        if ($rk['away_score'] != $brk['away_score']) return false;
    }
    return true;
}

// ── AJAX: Fetch scorecard for public display ──────────────────────────────────
add_action('wp_ajax_nopriv_lgw_get_scorecard', 'lgw_ajax_get_scorecard');
add_action('wp_ajax_lgw_get_scorecard',        'lgw_ajax_get_scorecard');
function lgw_ajax_get_scorecard() {
    $home    = sanitize_text_field($_GET['home']    ?? '');
    $away    = sanitize_text_field($_GET['away']    ?? '');
    $date    = sanitize_text_field($_GET['date']    ?? '');
    $context = sanitize_key(       $_GET['context'] ?? '');
    $post = lgw_get_scorecard($home, $away, $date, $context);
    if (!$post) { wp_send_json_error('No scorecard found'); }
    $sc                  = lgw_get_scorecard_data($post->ID);
    $sc['_id']           = $post->ID;
    $sc['_status']       = get_post_meta($post->ID, 'lgw_sc_status',    true) ?: 'pending';
    $sc['_submitted_by'] = get_post_meta($post->ID, 'lgw_submitted_by', true);
    $sc['_confirmed_by'] = get_post_meta($post->ID, 'lgw_confirmed_by', true);
    wp_send_json_success($sc);
}

// ── Shortcode: submission form ────────────────────────────────────────────────
add_shortcode('lgw_submit', 'lgw_submit_shortcode');
function lgw_submit_shortcode($atts) {
    $atts = shortcode_atts(array('csv' => '', 'cup' => ''), $atts);
    ob_start();
    lgw_render_submit_form($atts['csv'], $atts['cup']);
    return ob_get_clean();
}

function lgw_render_submit_form($csv_url = '', $cup_id = '') {
    $clubs     = lgw_get_clubs();
    $auth_club = lgw_get_auth_club();
    $has_clubs = !empty($clubs);
    ?>
    <div class="lgw-submit-wrap" id="lgw-submit-wrap"
         data-auth-club="<?php echo esc_attr($auth_club); ?>"
         data-cup-id="<?php echo esc_attr($cup_id); ?>">

      <!-- Passphrase / club login gate -->
      <div id="lgw-pin-gate" <?php echo $auth_club ? 'style="display:none"' : ''; ?>>
        <div class="lgw-submit-card">
          <h2>Score Entry Login</h2>
          <?php if (!$has_clubs): ?>
            <p class="lgw-notice lgw-notice-warn">No clubs have been configured yet. Please add clubs and passphrases in <strong>Settings &rarr; LGW Widget</strong>.</p>
          <?php else: ?>
            <p>Select your club and enter your passphrase to submit or confirm a scorecard.</p>
            <div class="lgw-form-row">
              <label>Club</label>
              <select id="lgw-club-select" style="width:100%;padding:9px 12px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px;font-family:inherit">
                <option value="">&#8212; Select your club &#8212;</option>
                <?php foreach ($clubs as $c): ?>
                <option value="<?php echo esc_attr($c['name']); ?>"><?php echo esc_html($c['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="lgw-pin-row">
              <input type="text" id="lgw-pin-input" placeholder="Enter passphrase" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false">
              <button class="lgw-btn lgw-btn-primary" id="lgw-pin-submit">Login</button>
            </div>
            <p id="lgw-pin-error" class="lgw-notice lgw-notice-error" style="display:none"></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Main area (shown after login) -->
      <div id="lgw-submit-form" <?php echo $auth_club ? '' : 'style="display:none"'; ?>>

        <!-- Club header bar -->
        <div class="lgw-club-bar">
          <span>Logged in as <strong id="lgw-club-name"><?php echo esc_html($auth_club); ?></strong></span>
          <button class="lgw-btn lgw-btn-secondary lgw-btn-sm" id="lgw-logout">Log out</button>
        </div>

        <!-- Pending confirmations for this club -->
        <div id="lgw-pending-wrap" style="display:none">
          <div class="lgw-submit-card lgw-pending-card">
            <h3>⏳ Awaiting Your Confirmation</h3>
            <p class="lgw-hint">The following matches have been submitted by the other club. Please review and confirm or amend.</p>
            <div id="lgw-pending-list"></div>
          </div>
        </div>

        <!-- Submit new scorecard -->
        <div class="lgw-submit-card">
          <h2>Submit Scorecard</h2>
          <div class="lgw-submit-tabs">
            <button class="lgw-stab active" data-tab="photo">📷 Photo</button>
            <button class="lgw-stab" data-tab="excel">📊 Excel</button>
            <button class="lgw-stab" data-tab="manual">✏️ Manual</button>
          </div>

          <div class="lgw-stab-panel active" data-panel="photo">
            <p class="lgw-hint">Upload a photo of the scorecard. AI will read it and pre-fill the form below.</p>
            <div class="lgw-upload-area" id="lgw-photo-drop">
              <input type="file" id="lgw-photo-input" accept="image/*" capture="environment" style="display:none">
              <div class="lgw-upload-inner" id="lgw-photo-trigger">
                <span class="lgw-upload-icon">📷</span>
                <span>Tap to take a photo or choose an image</span>
              </div>
              <img id="lgw-photo-preview" src="" alt="" style="display:none;max-width:100%;max-height:220px;border-radius:6px;margin-top:10px">
            </div>
            <button class="lgw-btn lgw-btn-primary" id="lgw-parse-photo" style="margin-top:10px;display:none">Read Scorecard with AI</button>
            <p id="lgw-parse-photo-status" class="lgw-notice" style="display:none"></p>
          </div>

          <div class="lgw-stab-panel" data-panel="excel">
            <p class="lgw-hint">Upload the LGW Excel scorecard template (.xlsx).</p>
            <div class="lgw-upload-area">
              <input type="file" id="lgw-excel-input" accept=".xlsx,.xls">
              <div class="lgw-upload-inner">
                <span class="lgw-upload-icon">📊</span>
                <span>Choose Excel file (.xlsx)</span>
              </div>
            </div>
            <button class="lgw-btn lgw-btn-primary" id="lgw-parse-excel" style="margin-top:10px;display:none">Read Spreadsheet</button>
            <p id="lgw-parse-excel-status" class="lgw-notice" style="display:none"></p>
          </div>

          <div class="lgw-stab-panel" data-panel="manual">
            <p class="lgw-hint">Fill in the scorecard details manually using the form below.</p>
          </div>
        </div>

        <!-- Scorecard form -->
        <div class="lgw-submit-card" id="lgw-scorecard-form">
          <h3>Scorecard Details</h3>
          <div class="lgw-form-row">
            <label>Division / Cup</label>
            <?php if ($cup_id): ?>
              <?php $cup_data = get_option('lgw_cup_' . $cup_id, array()); ?>
              <input type="text" id="sc-division"
                     value="<?php echo esc_attr($cup_data['title'] ?? $cup_id); ?>"
                     readonly style="background:#f5f5f5;cursor:default">
              <?php if (!empty($cup_data['bracket']['matches'])): ?>
              <div class="lgw-form-row" style="margin-top:8px">
                <label>Match</label>
                <select id="sc-cup-match" style="width:100%;padding:9px 12px;border:1px solid #d0d5e8;border-radius:6px;font-size:14px;font-family:inherit">
                  <option value="">— Select cup match —</option>
                  <?php
                  foreach ($cup_data['bracket']['matches'] as $ri => $round_matches) {
                      $round_name = $cup_data['bracket']['rounds'][$ri] ?? ('Round ' . ($ri + 1));
                      foreach ($round_matches as $mi => $m) {
                          if (empty($m['home']) || empty($m['away'])) continue;
                          $label = esc_attr($m['home'] . ' v ' . $m['away'] . ' (' . $round_name . ')');
                          $val   = esc_attr(json_encode(array('home' => $m['home'], 'away' => $m['away'], 'round' => $round_name)));
                          echo '<option value="' . $val . '">' . esc_html($m['home'] . ' v ' . $m['away'] . ' (' . $round_name . ')') . '</option>';
                      }
                  }
                  ?>
                </select>
              </div>
              <?php endif; ?>
            <?php else: ?>
              <?php
              // Build known-division list for autocomplete hint
              $drive_opts_sc   = get_option('lgw_drive', array());
              $known_divs_form = array_values(array_filter(array_map(
                  function($e){ return trim($e['division'] ?? ''); },
                  $drive_opts_sc['sheets_tabs'] ?? array()
              )));
              ?>
              <input type="text" id="sc-division" placeholder="e.g. Division 1"
                     list="lgw-division-list" autocomplete="off">
              <?php if (!empty($known_divs_form)): ?>
              <datalist id="lgw-division-list">
                <?php foreach ($known_divs_form as $kd): ?>
                <option value="<?php echo esc_attr($kd); ?>">
                <?php endforeach; ?>
              </datalist>
              <span class="lgw-hint" style="font-size:11px;color:#888;margin-top:3px;display:block">
                Known: <?php echo esc_html(implode(', ', $known_divs_form)); ?>
              </span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="lgw-form-row lgw-form-row-2">
            <div><label>Venue / Played at</label><input type="text" id="sc-venue" placeholder="e.g. Ards"></div>
            <div><label>Date</label><input type="text" id="sc-date" placeholder="e.g. 10th May"></div>
          </div>
          <div class="lgw-form-row lgw-form-row-2">
            <div><label>Home Team</label><input type="text" id="sc-home-team" placeholder="e.g. Ards A"></div>
            <div><label>Away Team</label><input type="text" id="sc-away-team" placeholder="e.g. Belmont A"></div>
          </div>

          <?php for ($r = 1; $r <= 4; $r++): ?>
          <div class="lgw-rink-block">
            <div class="lgw-rink-header">Rink <?php echo $r; ?></div>
            <div class="lgw-rink-grid">
              <div class="lgw-rink-col lgw-rink-home">
                <div class="lgw-rink-col-lbl">Home</div>
                <?php for ($p = 1; $p <= 4; $p++): ?>
                <input type="text" class="lgw-player" data-rink="<?php echo $r; ?>" data-side="home" data-player="<?php echo $p; ?>" placeholder="Player <?php echo $p; ?>">
                <?php endfor; ?>
                <div class="lgw-score-row">
                  <label>Score</label>
                  <input type="number" class="lgw-score lgw-score-home" data-rink="<?php echo $r; ?>" min="0" placeholder="0">
                </div>
              </div>
              <div class="lgw-rink-col lgw-rink-away">
                <div class="lgw-rink-col-lbl">Away</div>
                <?php for ($p = 1; $p <= 4; $p++): ?>
                <input type="text" class="lgw-player" data-rink="<?php echo $r; ?>" data-side="away" data-player="<?php echo $p; ?>" placeholder="Player <?php echo $p; ?>">
                <?php endfor; ?>
                <div class="lgw-score-row">
                  <label>Score</label>
                  <input type="number" class="lgw-score lgw-score-away" data-rink="<?php echo $r; ?>" min="0" placeholder="0">
                </div>
              </div>
            </div>
          </div>
          <?php endfor; ?>

          <div class="lgw-totals-block">
            <div class="lgw-totals-row">
              <span>Total Shots</span>
              <input type="number" id="sc-home-total" placeholder="0" min="0">
              <span class="lgw-totals-v">v</span>
              <input type="number" id="sc-away-total" placeholder="0" min="0">
            </div>
            <div class="lgw-totals-row">
              <span>Points</span>
              <input type="number" id="sc-home-points" placeholder="0" min="0" step="0.5">
              <span class="lgw-totals-v">v</span>
              <input type="number" id="sc-away-points" placeholder="0" min="0" step="0.5">
            </div>
          </div>

          <div class="lgw-submit-actions">
            <button class="lgw-btn lgw-btn-primary lgw-btn-lg" id="lgw-save-scorecard">Save Scorecard</button>
            <button class="lgw-btn lgw-btn-secondary" id="lgw-clear-form">Clear Form</button>
          </div>
          <p id="lgw-save-status" class="lgw-notice" style="display:none;margin-top:12px"></p>
        </div>
      </div>

    </div>
    <?php
    wp_nonce_field('lgw_submit_nonce', 'lgw_submit_nonce_field');
}

// ── Enqueue scorecard assets ──────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'lgw_enqueue_scorecard');
function lgw_enqueue_scorecard() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    if (!has_shortcode($post->post_content, 'lgw_submit') &&
        !has_shortcode($post->post_content, 'lgw_division')) return;

    // Register if not already done by lgw_enqueue() (widget on same page)
    if (!wp_script_is('lgw-scorecard', 'registered')) {
        wp_register_script('lgw-scorecard', plugin_dir_url(__FILE__) . 'lgw-scorecard.js', array(), LGW_VERSION, true);
        wp_register_style('lgw-scorecard',  plugin_dir_url(__FILE__) . 'lgw-scorecard.css', array(), LGW_VERSION);
    }
    if (!wp_script_is('lgw-scorecard', 'enqueued')) {
        wp_enqueue_script('lgw-scorecard');
        wp_enqueue_style('lgw-scorecard');
    }
    // Always localise — safe to call multiple times, ensures lgwSubmit is defined
    // even if the script was already enqueued by lgw_enqueue() on a combined page.
    // Build known-division list from Sheets tab config so the front-end can offer a datalist
    $drive_opts       = get_option('lgw_drive', array());
    $known_divisions  = array_values(array_filter(array_map(
        function($e) { return trim($e['division'] ?? ''); },
        $drive_opts['sheets_tabs'] ?? array()
    )));
    wp_localize_script('lgw-scorecard', 'lgwSubmit', array(
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('lgw_submit_nonce'),
        'authClub'        => lgw_get_auth_club(),
        'knownDivisions'  => $known_divisions,
    ));
}

// ── DEBUG: Excel parser diagnostic (admin only, remove after testing) ─────────
add_action('wp_ajax_lgw_debug_excel', 'lgw_ajax_debug_excel');
function lgw_ajax_debug_excel() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $out = array();
    $out[] = '=== LGW Excel Parser Debug ===';
    $out[] = 'PHP version: ' . PHP_VERSION;
    $out[] = 'ZipArchive available: ' . (class_exists('ZipArchive') ? 'YES' : 'NO');

    if (empty($_FILES['excel']['tmp_name'])) {
        $out[] = 'ERROR: No file uploaded';
        wp_send_json(array('log' => implode("\n", $out)));
    }

    $file = $_FILES['excel'];
    $out[] = 'File name: '    . $file['name'];
    $out[] = 'File size: '    . $file['size'] . ' bytes';
    $out[] = 'MIME type: '    . $file['type'];
    $out[] = 'Upload error: ' . $file['error'] . ' (0 = no error)';
    $out[] = 'Tmp path: '     . $file['tmp_name'];
    $out[] = 'Tmp exists: '   . (file_exists($file['tmp_name']) ? 'YES' : 'NO');

    if (!class_exists('ZipArchive')) {
        $out[] = 'BLOCKED: ZipArchive not available — install php-zip';
        wp_send_json(array('log' => implode("\n", $out)));
    }

    $zip = new ZipArchive();
    $zip_result = $zip->open($file['tmp_name']);
    $out[] = 'ZipArchive open result: ' . var_export($zip_result, true) . ' (true = success)';

    if ($zip_result !== true) {
        $out[] = 'BLOCKED: Cannot open as zip file';
        wp_send_json(array('log' => implode("\n", $out)));
    }

    $names = array();
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    $out[] = 'Zip contents: ' . implode(', ', $names);

    $has_sheet  = in_array('xl/worksheets/sheet1.xml', $names);
    $has_strings = in_array('xl/sharedStrings.xml', $names);
    $out[] = 'Has sheet1.xml: '       . ($has_sheet   ? 'YES' : 'NO');
    $out[] = 'Has sharedStrings.xml: '. ($has_strings ? 'YES' : 'NO');

    // Read shared strings
    $strings = array();
    if ($has_strings) {
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m);
        $strings = array_map('html_entity_decode', $m[1]);
        $out[] = 'Shared strings count: ' . count($strings);
        $out[] = 'First 10 strings: ' . implode(', ', array_slice($strings, 0, 10));
    }

    if (!$has_sheet) {
        $out[] = 'BLOCKED: sheet1.xml not found — file may use a different sheet name';
        // Try to find the actual sheet
        foreach ($names as $n) {
            if (strpos($n, 'xl/worksheets/') !== false) $out[] = 'Found sheet: ' . $n;
        }
        $zip->close();
        wp_send_json(array('log' => implode("\n", $out)));
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    $out[] = 'Sheet XML length: ' . strlen($sheet_xml) . ' bytes';

    // Test cell regex
    $cell_count = preg_match_all('/<c ((?:(?!\/>)[^>])*)>(.*?)<\/c>/s', $sheet_xml, $cell_matches, PREG_SET_ORDER);
    $out[] = 'Cell elements matched: ' . $cell_count;

    // Parse grid
    $grid = array();
    foreach ($cell_matches as $cm) {
        $tag = $cm[1]; $inner = $cm[2];
        if (!preg_match('/\br="([A-Z]+)(\d+)"/', $tag, $ref)) continue;
        $col     = lgw_col_to_idx($ref[1]);
        $row_num = intval($ref[2]);
        $type    = '';
        if (preg_match('/\bt="([^"]*)"/', $tag, $tm)) $type = $tm[1];
        if (!preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) continue;
        $val = $vm[1];
        $resolved = ($type === 's') ? ($strings[intval($val)] ?? '??') : $val;
        $grid[$row_num][$col] = $resolved;
    }

    $out[] = 'Rows parsed: ' . count($grid);
    $out[] = '';
    $out[] = '=== Decoded rows ===';
    ksort($grid);
    foreach ($grid as $rn => $row) {
        $max = max(array_keys($row));
        $cells = array();
        for ($i = 0; $i <= $max; $i++) $cells[] = $row[$i] ?? '';
        $out[] = 'Row ' . $rn . ': ' . json_encode($cells, JSON_UNESCAPED_UNICODE);
    }

    // Try mapper
    $out[] = '';
    $out[] = '=== Mapper result ===';
    $data = array();
    foreach ($grid as $rn => $row) {
        $max = max(array_keys($row));
        $r = array();
        for ($i = 0; $i <= $max; $i++) $r[] = $row[$i] ?? '';
        $data[] = $r;
    }
    $sc = lgw_map_xlsx_to_scorecard($data);
    if (!$sc) {
        $out[] = 'MAPPER RETURNED NULL — no Rink rows detected';
        // Show what row[0] values were seen
        foreach ($data as $row) {
            if (!empty($row[0])) $out[] = '  col A: ' . json_encode($row[0]);
        }
    } else {
        $out[] = 'Home: ' . $sc['home_team'];
        $out[] = 'Away: ' . $sc['away_team'];
        $out[] = 'Rinks found: ' . count($sc['rinks']);
        foreach ($sc['rinks'] as $rk) {
            $out[] = '  Rink ' . $rk['rink'] . ': home=' . implode(',', $rk['home_players'])
                   . ' score=' . $rk['home_score'] . '-' . $rk['away_score']
                   . ' away=' . implode(',', $rk['away_players']);
        }
        $out[] = 'Totals: ' . $sc['home_total'] . ' – ' . $sc['away_total'];
        $out[] = 'Points: ' . $sc['home_points'] . ' – ' . $sc['away_points'];
    }

    wp_send_json(array('log' => implode("\n", $out)));
}

// ── AJAX: Admin resolve disputed scorecard ────────────────────────────────────
add_action('wp_ajax_lgw_admin_resolve', 'lgw_ajax_admin_resolve');
function lgw_ajax_admin_resolve() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('lgw_admin_nonce', 'nonce');

    $post_id = intval($_POST['post_id'] ?? 0);
    $version = sanitize_text_field($_POST['version'] ?? '');
    if (!$post_id || !in_array($version, array('home','away'))) wp_send_json_error('Invalid request');

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'lgw_scorecard') wp_send_json_error('Scorecard not found');

    if ($version === 'away') {
        // Promote the away version to the canonical scorecard
        $away_sc = get_post_meta($post_id, 'lgw_away_scorecard', true);
        if (!$away_sc) wp_send_json_error('No away version found');
        update_post_meta($post_id, 'lgw_scorecard_data', $away_sc);
    }

    // Either way, clear the dispute and mark confirmed
    $sub_by = get_post_meta($post_id, 'lgw_submitted_by', true);
    $con_by = get_post_meta($post_id, 'lgw_confirmed_by', true);
    update_post_meta($post_id, 'lgw_sc_status',       'confirmed');
    update_post_meta($post_id, 'lgw_away_scorecard',  '');
    update_post_meta($post_id, 'lgw_confirmed_by',    'admin');
    // Clear division-unresolved flag if the division maps to a known sheet tab
    $sc_data_res      = get_post_meta($post_id, 'lgw_scorecard_data', true);
    $drive_opts_res   = get_option('lgw_drive', array());
    $resolved_tab_res = lgw_sheets_tab_for_division($sc_data_res['division'] ?? '', $drive_opts_res);
    if (!empty($sc_data_res['division']) && $resolved_tab_res) {
        delete_post_meta($post_id, 'lgw_division_unresolved');
    }
    lgw_log_appearances($post_id);
    lgw_audit_on_resolve($post_id, $version, $sub_by, $con_by);
    do_action('lgw_scorecard_confirmed', $post_id);

    $label = $version === 'away' ? 'Version B' : 'Version A';
    wp_send_json_success(array('message' => $label . ' accepted — scorecard confirmed. ✅'));
}
