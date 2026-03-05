<?php
/**
 * NIPGL Scorecard Feature
 * Handles scorecard submission, storage, retrieval, and display.
 */

// ── Custom Post Type ──────────────────────────────────────────────────────────
add_action('init', 'nipgl_register_scorecard_cpt');
function nipgl_register_scorecard_cpt() {
    register_post_type('nipgl_scorecard', array(
        'labels'       => array('name' => 'Scorecards', 'singular_name' => 'Scorecard'),
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'supports'     => array('title'),
        'capabilities' => array('create_posts' => 'manage_options'),
        'map_meta_cap' => true,
    ));
}

// ── Session helper (PIN gate) ─────────────────────────────────────────────────
function nipgl_session_start() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

function nipgl_pin_verified() {
    nipgl_session_start();
    return !empty($_SESSION['nipgl_pin_ok']);
}

// ── Scorecard lookup: find by home+away team+date ─────────────────────────────
function nipgl_get_scorecard($home, $away, $date) {
    $key = sanitize_title($home . '-' . $away . '-' . $date);
    $posts = get_posts(array(
        'post_type'      => 'nipgl_scorecard',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_key'       => 'nipgl_match_key',
        'meta_value'     => $key,
    ));
    return !empty($posts) ? $posts[0] : null;
}

function nipgl_get_scorecard_data($post_id) {
    return get_post_meta($post_id, 'nipgl_scorecard_data', true);
}

// ── AJAX: PIN check ───────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_check_pin', 'nipgl_ajax_check_pin');
add_action('wp_ajax_nipgl_check_pin',        'nipgl_ajax_check_pin');
function nipgl_ajax_check_pin() {
    check_ajax_referer('nipgl_submit_nonce', 'nonce');
    $entered  = sanitize_text_field($_POST['pin'] ?? '');
    $stored   = get_option('nipgl_submit_pin', '');
    if ($stored && hash_equals($stored, hash('sha256', $entered))) {
        nipgl_session_start();
        $_SESSION['nipgl_pin_ok'] = true;
        wp_send_json_success();
    } else {
        wp_send_json_error('Incorrect PIN');
    }
}

// ── AJAX: Parse photo via Anthropic vision ────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_parse_photo', 'nipgl_ajax_parse_photo');
add_action('wp_ajax_nipgl_parse_photo',        'nipgl_ajax_parse_photo');
function nipgl_ajax_parse_photo() {
    check_ajax_referer('nipgl_submit_nonce', 'nonce');
    if (!nipgl_pin_verified()) wp_send_json_error('Not authorised');

    $api_key = get_option('nipgl_anthropic_key', '');
    if (!$api_key) wp_send_json_error('No API key configured. Add your Anthropic API key in NIPGL Widget Settings.');

    if (empty($_FILES['photo']['tmp_name'])) wp_send_json_error('No file received');
    $file = $_FILES['photo'];
    $allowed = array('image/jpeg','image/png','image/gif','image/webp');
    if (!in_array($file['type'], $allowed)) wp_send_json_error('Please upload a JPG, PNG or WebP image');
    if ($file['size'] > 8 * 1024 * 1024) wp_send_json_error('Image too large (max 8MB)');

    $image_data = base64_encode(file_get_contents($file['tmp_name']));
    $media_type = $file['type'];

    $prompt = 'This is a bowls match scorecard. Extract all data and return ONLY valid JSON (no markdown, no explanation) in exactly this structure:
{
  "division": "string",
  "venue": "string",
  "date": "string (as written)",
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

    // Surface API-level errors clearly
    if ($http_code !== 200) {
        $api_error = $result['error']['message'] ?? 'Unknown API error';
        wp_send_json_error('API error (' . $http_code . '): ' . $api_error);
    }

    $text = $result['content'][0]['text'] ?? '';
    if (empty($text)) {
        $stop_reason = $result['stop_reason'] ?? 'unknown';
        wp_send_json_error('Empty response from API (stop_reason: ' . $stop_reason . '). Please try again.');
    }

    // Strip any accidental markdown fences
    $text = preg_replace('/^```json\s*/i', '', trim($text));
    $text = preg_replace('/^```\s*/i',     '', $text);
    $text = preg_replace('/```\s*$/',      '', $text);
    $parsed = json_decode(trim($text), true);

    if (!$parsed || !isset($parsed['rinks'])) {
        // Return the raw text so the admin can diagnose what came back
        wp_send_json_error('Could not parse response into scorecard format. Raw response: ' . substr($text, 0, 300));
    }

    wp_send_json_success($parsed);
}

// ── AJAX: Parse Excel ─────────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_parse_excel', 'nipgl_ajax_parse_excel');
add_action('wp_ajax_nipgl_parse_excel',        'nipgl_ajax_parse_excel');
function nipgl_ajax_parse_excel() {
    check_ajax_referer('nipgl_submit_nonce', 'nonce');
    if (!nipgl_pin_verified()) wp_send_json_error('Not authorised');

    if (empty($_FILES['excel']['tmp_name'])) wp_send_json_error('No file received');
    $file = $_FILES['excel'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('xlsx','xls'))) wp_send_json_error('Please upload an .xlsx or .xls file');

    // Use PhpSpreadsheet if available, otherwise fall back to simple XML parse
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
            $ws = $spreadsheet->getActiveSheet();
            $data = array();
            foreach ($ws->getRowIterator() as $row) {
                $cells = array();
                foreach ($row->getCellIterator() as $cell) {
                    $cells[] = $cell->getFormattedValue();
                }
                $data[] = $cells;
            }
        } catch (Exception $e) {
            wp_send_json_error('Could not read spreadsheet: ' . $e->getMessage());
        }
    } else {
        // Fallback: read xlsx as zip and extract shared strings + sheet data
        $data = nipgl_parse_xlsx_basic($file['tmp_name']);
        if (!$data) wp_send_json_error('Could not read spreadsheet. Please try the manual entry form.');
    }

    $parsed = nipgl_map_xlsx_to_scorecard($data);
    if (!$parsed) wp_send_json_error('Could not map spreadsheet to scorecard format. Please check the file matches the NIPGL template.');

    wp_send_json_success($parsed);
}

// Basic xlsx parser (no external library needed)
function nipgl_parse_xlsx_basic($filepath) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) return false;

    // Read shared strings
    $strings = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m);
        $strings = array_map('html_entity_decode', $m[1]);
    }

    // Read first sheet
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet_xml) return false;

    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet_xml, $rows);
    $data = array();
    foreach ($rows[1] as $row_xml) {
        preg_match_all('/<c r="([A-Z]+)(\d+)"[^>]*(?:t="([^"]*)")?[^>]*>.*?<v>(.*?)<\/v>.*?<\/c>/s', $row_xml, $cells, PREG_SET_ORDER);
        $row = array();
        foreach ($cells as $c) {
            $col   = nipgl_col_to_idx($c[1]);
            $type  = $c[3];
            $val   = $c[4];
            $row[$col] = ($type === 's') ? ($strings[intval($val)] ?? '') : $val;
        }
        if (!empty($row)) {
            $max = max(array_keys($row));
            $out = array();
            for ($i = 0; $i <= $max; $i++) $out[] = $row[$i] ?? '';
            $data[] = $out;
        }
    }
    return $data;
}

function nipgl_col_to_idx($col) {
    $idx = 0;
    foreach (str_split($col) as $c) $idx = $idx * 26 + (ord($c) - 64);
    return $idx - 1;
}

// Map xlsx rows to scorecard structure matching the NIPGL template
function nipgl_map_xlsx_to_scorecard($data) {
    if (empty($data)) return null;
    $sc = array('division'=>'','venue'=>'','date'=>'','home_team'=>'','away_team'=>'',
                'rinks'=>array(),'home_total'=>null,'away_total'=>null,'home_points'=>null,'away_points'=>null);

    foreach ($data as $i => $row) {
        $r0 = trim($row[0] ?? ''); $r1 = trim($row[1] ?? '');
        if ($r0 === 'Division/Cup' || $r0 === 'Division') $sc['division'] = $r1;
        if ($r0 === 'Played at')  { $sc['venue'] = $r1; $sc['date'] = trim($row[5] ?? $row[4] ?? ''); }
        if ($r0 === 'Home Team')  continue;
        if (!empty($r0) && isset($row[3]) && trim($row[3]) === 'v') {
            $sc['home_team'] = $r0; $sc['away_team'] = trim($row[4] ?? '');
        }
        if (preg_match('/^Rink\s*(\d)/i', $r0, $m)) {
            $rink_num = intval($m[1]);
            $rink = array('rink'=>$rink_num,'home_players'=>array(),'away_players'=>array(),'home_score'=>null,'away_score'=>null);
            // Next 4 rows are player rows
            for ($p = $i+1; $p <= $i+4 && $p < count($data); $p++) {
                $pr = $data[$p];
                if (preg_match('/^Rink/i', $pr[0] ?? '')) break;
                $hp = trim($pr[0] ?? ''); $ap = trim($pr[3] ?? $pr[4] ?? '');
                if ($hp) $rink['home_players'][] = $hp;
                if ($ap) $rink['away_players'][] = $ap;
                // Score is in col 2 (home) and col 6 (away) — appears on any player row
                if (!empty($pr[2]) && is_numeric($pr[2])) $rink['home_score'] = floatval($pr[2]);
                if (!empty($pr[6]) && is_numeric($pr[6])) $rink['away_score'] = floatval($pr[6]);
            }
            $sc['rinks'][] = $rink;
        }
        if (stripos($r1, 'Total Shots') !== false || stripos($r0, 'Total Shots') !== false) {
            $sc['home_total'] = is_numeric($row[2]??'') ? floatval($row[2]) : null;
            $sc['away_total'] = is_numeric($row[6]??$row[5]??'') ? floatval($row[6]??$row[5]) : null;
        }
        if ($r1 === 'Points' || $r0 === 'Points') {
            $sc['home_points'] = is_numeric($row[2]??'') ? floatval($row[2]) : null;
            $sc['away_points'] = is_numeric($row[6]??$row[5]??'') ? floatval($row[6]??$row[5]) : null;
        }
    }

    if (empty($sc['rinks'])) return null;
    return $sc;
}

// ── AJAX: Save scorecard ──────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_save_scorecard', 'nipgl_ajax_save_scorecard');
add_action('wp_ajax_nipgl_save_scorecard',        'nipgl_ajax_save_scorecard');
function nipgl_ajax_save_scorecard() {
    check_ajax_referer('nipgl_submit_nonce', 'nonce');
    if (!nipgl_pin_verified()) wp_send_json_error('Not authorised');

    $raw = json_decode(stripslashes($_POST['scorecard'] ?? ''), true);
    if (!$raw) wp_send_json_error('Invalid data');

    // Sanitise
    $sc = array(
        'division'     => sanitize_text_field($raw['division']     ?? ''),
        'venue'        => sanitize_text_field($raw['venue']        ?? ''),
        'date'         => sanitize_text_field($raw['date']         ?? ''),
        'home_team'    => sanitize_text_field($raw['home_team']    ?? ''),
        'away_team'    => sanitize_text_field($raw['away_team']    ?? ''),
        'home_total'   => is_numeric($raw['home_total']   ?? '') ? floatval($raw['home_total'])   : null,
        'away_total'   => is_numeric($raw['away_total']   ?? '') ? floatval($raw['away_total'])   : null,
        'home_points'  => is_numeric($raw['home_points']  ?? '') ? floatval($raw['home_points'])  : null,
        'away_points'  => is_numeric($raw['away_points']  ?? '') ? floatval($raw['away_points'])  : null,
        'rinks'        => array(),
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

    $match_key = sanitize_title($sc['home_team'] . '-' . $sc['away_team'] . '-' . $sc['date']);

    // Check for duplicate
    $existing = nipgl_get_scorecard($sc['home_team'], $sc['away_team'], $sc['date']);
    if ($existing) {
        // Update existing
        update_post_meta($existing->ID, 'nipgl_scorecard_data', $sc);
        wp_send_json_success(array('message' => 'Scorecard updated successfully.', 'id' => $existing->ID));
    } else {
        $title = $sc['home_team'] . ' v ' . $sc['away_team'] . ' (' . $sc['date'] . ')';
        $post_id = wp_insert_post(array(
            'post_type'   => 'nipgl_scorecard',
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
        if (is_wp_error($post_id)) wp_send_json_error('Failed to save: ' . $post_id->get_error_message());
        update_post_meta($post_id, 'nipgl_match_key',      $match_key);
        update_post_meta($post_id, 'nipgl_scorecard_data', $sc);
        wp_send_json_success(array('message' => 'Scorecard saved successfully.', 'id' => $post_id));
    }
}

// ── AJAX: Fetch scorecard for display (public) ────────────────────────────────
add_action('wp_ajax_nopriv_nipgl_get_scorecard', 'nipgl_ajax_get_scorecard');
add_action('wp_ajax_nipgl_get_scorecard',        'nipgl_ajax_get_scorecard');
function nipgl_ajax_get_scorecard() {
    $home = sanitize_text_field($_GET['home'] ?? '');
    $away = sanitize_text_field($_GET['away'] ?? '');
    $date = sanitize_text_field($_GET['date'] ?? '');
    $post = nipgl_get_scorecard($home, $away, $date);
    if (!$post) { wp_send_json_error('No scorecard found'); }
    wp_send_json_success(nipgl_get_scorecard_data($post->ID));
}

// ── Shortcode: submission form ────────────────────────────────────────────────
add_shortcode('nipgl_submit', 'nipgl_submit_shortcode');
function nipgl_submit_shortcode($atts) {
    $atts = shortcode_atts(array('csv' => ''), $atts);
    ob_start();
    nipgl_render_submit_form($atts['csv']);
    return ob_get_clean();
}

function nipgl_render_submit_form($csv_url = '') {
    $pin_set = (bool) get_option('nipgl_submit_pin', '');
    ?>
    <div class="nipgl-submit-wrap" id="nipgl-submit-wrap">

      <!-- PIN gate -->
      <div id="nipgl-pin-gate" style="<?php echo nipgl_pin_verified() ? 'display:none' : ''; ?>">
        <div class="nipgl-submit-card">
          <h2>Score Entry</h2>
          <?php if (!$pin_set): ?>
            <p class="nipgl-notice nipgl-notice-warn">No PIN has been set. Please configure a Score Entry PIN in <strong>Settings → NIPGL Widget</strong> before using this page.</p>
          <?php else: ?>
            <p>Enter the score entry PIN to submit a scorecard.</p>
            <div class="nipgl-pin-row">
              <input type="password" id="nipgl-pin-input" placeholder="Enter PIN" maxlength="20" autocomplete="off">
              <button class="nipgl-btn nipgl-btn-primary" id="nipgl-pin-submit">Enter</button>
            </div>
            <p id="nipgl-pin-error" class="nipgl-notice nipgl-notice-error" style="display:none"></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Main form (shown after PIN) -->
      <div id="nipgl-submit-form" style="<?php echo nipgl_pin_verified() ? '' : 'display:none'; ?>">
        <div class="nipgl-submit-card">
          <h2>Submit Scorecard</h2>

          <!-- Method tabs -->
          <div class="nipgl-submit-tabs">
            <button class="nipgl-stab active" data-tab="photo">📷 Photo</button>
            <button class="nipgl-stab" data-tab="excel">📊 Excel</button>
            <button class="nipgl-stab" data-tab="manual">✏️ Manual</button>
          </div>

          <!-- Photo upload -->
          <div class="nipgl-stab-panel active" data-panel="photo">
            <p class="nipgl-hint">Upload a photo of the scorecard. AI will read it and pre-fill the form below for you to check.</p>
            <div class="nipgl-upload-area" id="nipgl-photo-drop">
              <input type="file" id="nipgl-photo-input" accept="image/*" capture="environment" style="display:none">
              <div class="nipgl-upload-inner" id="nipgl-photo-trigger">
                <span class="nipgl-upload-icon">📷</span>
                <span>Tap to take a photo or choose an image</span>
              </div>
              <img id="nipgl-photo-preview" src="" alt="" style="display:none;max-width:100%;max-height:220px;border-radius:6px;margin-top:10px">
            </div>
            <button class="nipgl-btn nipgl-btn-primary" id="nipgl-parse-photo" style="margin-top:10px;display:none">
              <span id="nipgl-parse-photo-lbl">Read Scorecard with AI</span>
            </button>
            <p id="nipgl-parse-photo-status" class="nipgl-notice" style="display:none"></p>
          </div>

          <!-- Excel upload -->
          <div class="nipgl-stab-panel" data-panel="excel">
            <p class="nipgl-hint">Upload the NIPGL Excel scorecard template (.xlsx). It will be read automatically and fill the form below.</p>
            <div class="nipgl-upload-area">
              <input type="file" id="nipgl-excel-input" accept=".xlsx,.xls">
              <div class="nipgl-upload-inner">
                <span class="nipgl-upload-icon">📊</span>
                <span>Choose Excel file (.xlsx)</span>
              </div>
            </div>
            <button class="nipgl-btn nipgl-btn-primary" id="nipgl-parse-excel" style="margin-top:10px;display:none">Read Spreadsheet</button>
            <p id="nipgl-parse-excel-status" class="nipgl-notice" style="display:none"></p>
          </div>

          <!-- Manual entry hint -->
          <div class="nipgl-stab-panel" data-panel="manual">
            <p class="nipgl-hint">Fill in the scorecard details manually using the form below.</p>
          </div>
        </div>

        <!-- Scorecard form -->
        <div class="nipgl-submit-card" id="nipgl-scorecard-form">
          <h3>Scorecard Details</h3>
          <div class="nipgl-form-row">
            <label>Division</label>
            <input type="text" id="sc-division" placeholder="e.g. Division 1">
          </div>
          <div class="nipgl-form-row nipgl-form-row-2">
            <div>
              <label>Venue / Played at</label>
              <input type="text" id="sc-venue" placeholder="e.g. Ards">
            </div>
            <div>
              <label>Date</label>
              <input type="text" id="sc-date" placeholder="e.g. 10th May">
            </div>
          </div>
          <div class="nipgl-form-row nipgl-form-row-2">
            <div>
              <label>Home Team</label>
              <input type="text" id="sc-home-team" placeholder="e.g. Ards A">
            </div>
            <div>
              <label>Away Team</label>
              <input type="text" id="sc-away-team" placeholder="e.g. Belmont A">
            </div>
          </div>

          <!-- Rinks -->
          <?php for ($r = 1; $r <= 4; $r++): ?>
          <div class="nipgl-rink-block">
            <div class="nipgl-rink-header">Rink <?php echo $r; ?></div>
            <div class="nipgl-rink-grid">
              <div class="nipgl-rink-col nipgl-rink-home">
                <div class="nipgl-rink-col-lbl">Home</div>
                <?php for ($p = 1; $p <= 4; $p++): ?>
                <input type="text" class="nipgl-player" data-rink="<?php echo $r; ?>" data-side="home" data-player="<?php echo $p; ?>" placeholder="Player <?php echo $p; ?>">
                <?php endfor; ?>
                <div class="nipgl-score-row">
                  <label>Score</label>
                  <input type="number" class="nipgl-score nipgl-score-home" data-rink="<?php echo $r; ?>" min="0" placeholder="0">
                </div>
              </div>
              <div class="nipgl-rink-col nipgl-rink-away">
                <div class="nipgl-rink-col-lbl">Away</div>
                <?php for ($p = 1; $p <= 4; $p++): ?>
                <input type="text" class="nipgl-player" data-rink="<?php echo $r; ?>" data-side="away" data-player="<?php echo $p; ?>" placeholder="Player <?php echo $p; ?>">
                <?php endfor; ?>
                <div class="nipgl-score-row">
                  <label>Score</label>
                  <input type="number" class="nipgl-score nipgl-score-away" data-rink="<?php echo $r; ?>" min="0" placeholder="0">
                </div>
              </div>
            </div>
          </div>
          <?php endfor; ?>

          <!-- Totals -->
          <div class="nipgl-totals-block">
            <div class="nipgl-totals-row">
              <span>Total Shots</span>
              <input type="number" id="sc-home-total" placeholder="0" min="0">
              <span class="nipgl-totals-v">v</span>
              <input type="number" id="sc-away-total" placeholder="0" min="0">
            </div>
            <div class="nipgl-totals-row">
              <span>Points</span>
              <input type="number" id="sc-home-points" placeholder="0" min="0" step="0.5">
              <span class="nipgl-totals-v">v</span>
              <input type="number" id="sc-away-points" placeholder="0" min="0" step="0.5">
            </div>
          </div>

          <div class="nipgl-submit-actions">
            <button class="nipgl-btn nipgl-btn-primary nipgl-btn-lg" id="nipgl-save-scorecard">Save Scorecard</button>
            <button class="nipgl-btn nipgl-btn-secondary" id="nipgl-clear-form">Clear Form</button>
          </div>
          <p id="nipgl-save-status" class="nipgl-notice" style="display:none;margin-top:12px"></p>
        </div>
      </div>

    </div>

    <?php
    wp_nonce_field('nipgl_submit_nonce', 'nipgl_submit_nonce_field');
}

// ── Enqueue scorecard assets ──────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'nipgl_enqueue_scorecard');
function nipgl_enqueue_scorecard() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    if (!has_shortcode($post->post_content, 'nipgl_submit') &&
        !has_shortcode($post->post_content, 'nipgl_division')) return;

    wp_enqueue_style('nipgl-scorecard',  plugin_dir_url(__FILE__) . 'nipgl-scorecard.css', array(), NIPGL_VERSION);
    wp_enqueue_script('nipgl-scorecard', plugin_dir_url(__FILE__) . 'nipgl-scorecard.js',  array(), NIPGL_VERSION, true);
    wp_localize_script('nipgl-scorecard', 'nipglSubmit', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('nipgl_submit_nonce'),
        'pinOk'   => nipgl_pin_verified(),
    ));
}
