<?php
/**
 * LGW Google Sheets Writeback - v5.17.10
 *
 * On scorecard confirmation, finds the matching fixture row in the Google Sheet
 * and writes home score, away score, home points, away points into it.
 * Also marks the row with an "Online" flag in a dedicated column.
 *
 * Uses the same service account JWT auth as lgw-drive.php.
 * Requires the Sheets API to be enabled in Google Cloud Console and the
 * spreadsheet shared with the service account email.
 *
 * Sheet structure expected (auto-detected via header row):
 *   HPts | ... | HTeam | ... | HScore | ... | AScore | ATeam | ... | APts
 *   Defaults: col 0=HPts, col 2=HTeam, col 7=HScore, col 9=AScore, col 10=ATeam, col 15=APts
 *
 * Date format in sheet: "Sat 05-Apr-2025"  (day-of-week DD-Mon-YYYY)
 * Date format in scorecard: "dd/mm/yyyy"
 */

// ── Hook into confirmation actions ────────────────────────────────────────────
add_action('lgw_scorecard_confirmed',    'lgw_sheets_on_confirmed');
add_action('lgw_scorecard_admin_edited', 'lgw_sheets_on_confirmed');

function lgw_sheets_on_confirmed($post_id) {
    $opts = get_option('lgw_drive', []);
    if (empty($opts['sheets_enabled'])) return;

    // Skip writeback for scorecards from archived seasons
    if (function_exists('lgw_scorecard_is_active_season') && !lgw_scorecard_is_active_season($post_id)) {
        $sc_season = get_post_meta($post_id, 'lgw_sc_season', true);
        lgw_sheets_log($post_id, 'info', 'Skipped — scorecard belongs to archived season ' . ($sc_season ?: 'unknown') . '; Sheets writeback only runs for the active season.');
        return;
    }

    lgw_sheets_write_result($post_id);
}

// ── Main entry point ──────────────────────────────────────────────────────────
function lgw_sheets_write_result($post_id) {
    $sc = get_post_meta($post_id, 'lgw_scorecard_data', true);
    if (!$sc) {
        lgw_sheets_log($post_id, 'error', 'No scorecard data found');
        return false;
    }

    $home_team  = trim($sc['home_team']  ?? '');
    $away_team  = trim($sc['away_team']  ?? '');
    $date_raw   = trim($sc['date']       ?? ''); // dd/mm/yyyy
    $division   = trim($sc['division']   ?? '');
    $home_score = $sc['home_total'] ?? '';
    $away_score = $sc['away_total'] ?? '';
    $home_pts   = $sc['home_points'] ?? '';
    $away_pts   = $sc['away_points'] ?? '';

    if (!$home_team || !$away_team) {
        lgw_sheets_log($post_id, 'error', 'Missing team names in scorecard');
        return false;
    }

    // Get sheet config
    $opts  = get_option('lgw_drive', []);

    // Resolve full division entry — tab name + per-division spreadsheet ID
    $entry = lgw_sheets_entry_for_division($division, $opts);
    if (!$entry || empty($entry['tab'])) {
        lgw_sheets_log($post_id, 'warn', "No sheet tab mapped for division: $division — skipping writeback");
        return false;
    }
    $tab         = trim($entry['tab']);
    // Per-division spreadsheet_id takes priority; fall back to legacy global
    $spreadsheet = trim($entry['spreadsheet_id'] ?? $opts['sheets_id'] ?? '');
    if (!$spreadsheet) {
        lgw_sheets_log($post_id, 'error', "No spreadsheet ID configured for division: $division");
        return false;
    }

    // Get auth token
    $token = lgw_drive_get_access_token();
    if (!$token) {
        lgw_sheets_log($post_id, 'error', 'Could not obtain Sheets auth token');
        return false;
    }

    // Convert scorecard date dd/mm/yyyy → sheet format "Sat 05-Apr-2025"
    // Use fixture_date meta if available (set by front-end modal); fall back to played date.
    $fixture_date_raw = trim(get_post_meta($post_id, 'lgw_fixture_date', true) ?: $date_raw);
    $sheet_date = lgw_sheets_format_date($fixture_date_raw);

    // Fetch the sheet data to find the right row
    $sheet_data = lgw_sheets_fetch($token, $spreadsheet, $tab);
    if ($sheet_data === false) {
        lgw_sheets_log($post_id, 'error', "Failed to fetch sheet data for tab: $tab");
        return false;
    }

    // Detect column positions from header row
    $cols = lgw_sheets_detect_cols($sheet_data);

    // Find the matching row — try with date first, then without (handles rescheduled matches)
    $match = lgw_sheets_find_row($sheet_data, $cols, $home_team, $away_team, $sheet_date);
    if ($match === false && $sheet_date) {
        // Played date didn't match the fixture date in the sheet — find by team names only
        $match = lgw_sheets_find_row($sheet_data, $cols, $home_team, $away_team, '');
        if ($match !== false) {
            lgw_sheets_log($post_id, 'info', "Row found by team names only (played '$sheet_date' differs from fixture date in sheet)");
        }
    }
    if ($match === false) {
        lgw_sheets_log($post_id, 'warn', "Could not find fixture row: '$home_team' v '$away_team' on '$sheet_date' in tab '$tab'");
        return false;
    }

    [$row_index, $row_data] = $match;

    // Build batchUpdate requests — write scores, points, and online flag
    $requests = lgw_sheets_build_update($spreadsheet, $tab, $row_index, $cols, [
        'home_score' => $home_score,
        'away_score' => $away_score,
        'home_pts'   => $home_pts,
        'away_pts'   => $away_pts,
    ]);

    $result = lgw_sheets_batch_update($token, $spreadsheet, $requests);
    if ($result === false) {
        lgw_sheets_log($post_id, 'error', "batchUpdate failed for row $row_index in tab '$tab'");
        return false;
    }

    // Clear the CSV transient so the widget fetches fresh data on next load
    $csv_url = trim($entry['csv_url'] ?? '');
    if ($csv_url) {
        delete_transient('lgw_csv_' . md5($csv_url));
    }

    lgw_sheets_log($post_id, 'info',
        "Written to sheet: spreadsheet='$spreadsheet', tab='$tab', row=" . ($row_index + 1)
        . ", HScore=$home_score, AScore=$away_score, HPts=$home_pts, APts=$away_pts"
    );
    return true;
}

// ── Date conversion: dd/mm/yyyy → "Sat 05-Apr-2025" ──────────────────────────
function lgw_sheets_format_date($dmy) {
    if (!$dmy) return '';
    $parts = explode('/', $dmy);
    if (count($parts) !== 3) return $dmy;
    [$d, $m, $y] = $parts;
    $ts = mktime(0, 0, 0, (int)$m, (int)$d, (int)$y);
    if (!$ts) return $dmy;
    // Format: "Sat 5-Apr-2025" (no leading zero on day, matches typical sheet format)
    return date('D', $ts) . ' ' . ltrim(date('d', $ts), '0') . '-' . date('M-Y', $ts);
}

// ── Resolve division name → full sheets config entry ─────────────────────────
/**
 * Returns the full sheets_tabs entry for a division: {division, tab, csv_url, spreadsheet_id}
 * or false if not found.
 */
function lgw_sheets_entry_for_division($division, $opts) {
    $division = trim($division ?? '');
    $mapping  = $opts['sheets_tabs'] ?? [];
    foreach ($mapping as $entry) {
        if (strcasecmp(trim($entry['division'] ?? ''), $division) === 0) {
            return $entry;
        }
    }
    // Fallback: single-tab config
    if (count($mapping) === 1 && !empty($mapping[0]['tab'])) {
        return $mapping[0];
    }
    return false;
}

function lgw_sheets_tab_for_division($division, $opts) {
    $entry = lgw_sheets_entry_for_division($division, $opts);
    if (!$entry) return '';
    return trim($entry['tab'] ?? '');
}

// ── Fetch sheet values ────────────────────────────────────────────────────────
function lgw_sheets_fetch($token, $spreadsheet, $tab) {
    $encoded_tab = rawurlencode($tab);
    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet}/values/" . $encoded_tab;
    $response = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return false;
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['values'] ?? [];
}

// ── Detect column positions from header row ───────────────────────────────────
function lgw_sheets_detect_cols($rows) {
    // Defaults matching lgw-widget.js parseFixtureGroups
    $cols = [
        'hpts'   => 0,
        'hteam'  => 2,
        'hscore' => 7,
        'ascore' => 9,
        'ateam'  => 10,
        'apts'   => 15,
        'online' => -1, // optional flag column — set if found
    ];

    foreach ($rows as $row) {
        $joined = implode('', $row);
        if (strpos($joined, 'HTeam') !== false || strpos($joined, 'HPts') !== false) {
            foreach ($row as $c => $val) {
                $v = trim($val);
                if ($v === 'HPts')                          $cols['hpts']   = $c;
                if ($v === 'HTeam')                         $cols['hteam']  = $c;
                if ($v === 'HScore')                        $cols['hscore'] = $c;
                if ($v === 'AScore' || $v === 'Ascore')     $cols['ascore'] = $c;
                if ($v === 'ATeam')                         $cols['ateam']  = $c;
                if ($v === 'APts')                          $cols['apts']   = $c;
                if ($v === 'Online' || $v === 'Source')     $cols['online'] = $c;
            }
            break;
        }
    }
    return $cols;
}

// ── Find the matching fixture row ────────────────────────────────────────────
function lgw_sheets_find_row($rows, $cols, $home_team, $away_team, $sheet_date) {
    $date_re = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $current_date = '';

    // Normalise a date string for loose comparison:
    // strip leading zeros from day, lowercase — "Sat 05-Apr-2025" → "sat 5-apr-2025"
    $norm_date = function($d) {
        return strtolower(preg_replace('/\b0(\d)/', '$1', trim($d)));
    };

    $needle_date = $sheet_date ? $norm_date($sheet_date) : '';
    $needle_home = strtolower(trim($home_team));
    $needle_away = strtolower(trim($away_team));

    foreach ($rows as $i => $row) {
        $first = trim($row[0] ?? $row[1] ?? '');
        if (preg_match($date_re, $first)) {
            $current_date = $norm_date($first);
            continue;
        }

        $ht = strtolower(trim($row[$cols['hteam']] ?? ''));
        $at = strtolower(trim($row[$cols['ateam']] ?? ''));
        if (!$ht || !$at) continue;

        if ($ht === $needle_home && $at === $needle_away) {
            // If we have a date to match and it doesn't match, keep looking
            if ($needle_date && $current_date && $current_date !== $needle_date) continue;
            return [$i, $row];
        }
    }
    return false;
}

// ── Build batchUpdate requests ────────────────────────────────────────────────
function lgw_sheets_build_update($spreadsheet, $tab, $row_index, $cols, $values) {
    // Convert col index to A1 notation letter
    $col_letter = function($n) {
        $letter = '';
        $n++;
        while ($n > 0) {
            $n--;
            $letter = chr(65 + ($n % 26)) . $letter;
            $n = (int)($n / 26);
        }
        return $letter;
    };

    $sheet_row = $row_index + 1; // 1-indexed
    $data      = [];

    // Write each value as a separate range (avoids overwriting intermediate cols)
    $write = [
        $cols['hscore'] => $values['home_score'],
        $cols['ascore'] => $values['away_score'],
        $cols['hpts']   => $values['home_pts'],
        $cols['apts']   => $values['away_pts'],
    ];

    // Optional Online flag column
    if ($cols['online'] >= 0) {
        $write[$cols['online']] = 'Online';
    }

    foreach ($write as $col_idx => $val) {
        if ($val === '' || $val === null) continue;
        $a1 = "'" . addslashes($tab) . "'!" . $col_letter($col_idx) . $sheet_row;
        $cell_val = is_numeric($val) ? $val + 0 : $val;
        $data[] = [
            'range'          => $a1,
            'majorDimension' => 'ROWS',
            'values'         => [[$cell_val]],
        ];
    }

    return $data;
}

// ── Execute Sheets batchUpdate ────────────────────────────────────────────────
function lgw_sheets_batch_update($token, $spreadsheet, $data) {
    if (empty($data)) return true;

    $url  = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet}/values:batchUpdate";
    $body = json_encode([
        'valueInputOption' => 'USER_ENTERED',
        'data'             => $data,
    ]);

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) return false;
    $code = wp_remote_retrieve_response_code($response);
    return ($code === 200);
}

// ── Per-scorecard log (mirrors lgw_drive_log pattern) ──────────────────────
function lgw_sheets_log($post_id, $level, $message) {
    $log   = get_post_meta($post_id, 'lgw_sheets_log', true) ?: [];
    $log[] = [
        'time'    => current_time('mysql'),
        'level'   => $level,
        'message' => $message,
    ];
    // Keep last 20 entries
    if (count($log) > 20) $log = array_slice($log, -20);
    update_post_meta($post_id, 'lgw_sheets_log', $log);
}

// ── Manual retry AJAX ─────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_sheets_retry', 'lgw_ajax_sheets_retry');
function lgw_ajax_sheets_retry() {
    check_ajax_referer('lgw_sheets_retry', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post_id');
    $ok = lgw_sheets_write_result($post_id);
    if ($ok) {
        wp_send_json_success('Result written to sheet.');
    } else {
        $log = get_post_meta($post_id, 'lgw_sheets_log', true) ?: [];
        $last = end($log);
        wp_send_json_error('Failed: ' . ($last['message'] ?? 'unknown error'));
    }
}

// ── Force-sync override AJAX ──────────────────────────────────────────────────
add_action('wp_ajax_lgw_sync_override', 'lgw_ajax_sync_override');
function lgw_ajax_sync_override() {
    check_ajax_referer('lgw_sheets_retry', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post_id');
    if (function_exists('lgw_sync_override_from_scorecard')) {
        lgw_sync_override_from_scorecard($post_id);
        // Check the last log entry to determine if it succeeded
        $log  = get_post_meta($post_id, 'lgw_sheets_log', true) ?: [];
        $last = end($log);
        if (($last['level'] ?? '') === 'info' && strpos($last['message'] ?? '', 'Override synced') !== false) {
            wp_send_json_success('Override synced. ✅ ' . ($last['message'] ?? ''));
        } else {
            wp_send_json_error('Sync failed: ' . ($last['message'] ?? 'unknown — check division mapping'));
        }
    } else {
        wp_send_json_error('lgw_sync_override_from_scorecard not available');
    }
}

// ── Settings: save sheets config ──────────────────────────────────────────────
// Called from lgw_save_settings() in lgw-division-widget.php
function lgw_sheets_save_settings() {
    $opts = get_option('lgw_drive', []);

    $opts['sheets_enabled'] = !empty($_POST['lgw_sheets_enabled']) ? 1 : 0;
    $opts['sheets_id']      = sanitize_text_field($_POST['lgw_sheets_id'] ?? '1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc');

    // Tab mappings: array of {division, tab, csv_url}
    $divs     = $_POST['lgw_sheets_div']     ?? [];
    $tabs     = $_POST['lgw_sheets_tab']     ?? [];
    $csv_urls = $_POST['lgw_sheets_csv_url'] ?? [];
    $mapping  = [];
    foreach ($divs as $k => $div) {
        $div     = sanitize_text_field($div);
        $tab     = sanitize_text_field($tabs[$k] ?? '');
        $csv_url = esc_url_raw($csv_urls[$k] ?? '');
        if ($div && $tab) {
            $mapping[] = ['division' => $div, 'tab' => $tab, 'csv_url' => $csv_url];
        }
    }
    $opts['sheets_tabs'] = $mapping;

    update_option('lgw_drive', $opts);
}

// ── Settings UI section ───────────────────────────────────────────────────────
function lgw_sheets_settings_html() {
    $opts    = get_option('lgw_drive', []);
    $enabled = !empty($opts['sheets_enabled']);
    $sid     = esc_attr($opts['sheets_id'] ?? '1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc');
    $tabs    = $opts['sheets_tabs'] ?? [];
    if (empty($tabs)) $tabs = [['division' => '', 'tab' => '', 'csv_url' => '']];
    $nonce_test = wp_create_nonce('lgw_sheets_test');
    ?>
    <hr>
    <h2>Google Sheets Writeback</h2>
    <p>When a scorecard is confirmed, automatically write the result into the matching row in your Google Sheet.
    The same service account used for Drive must have <strong>Editor</strong> access to the spreadsheet.</p>

    <table class="form-table">
    <tr>
        <th>Enable Sheets writeback</th>
        <td><label>
            <input type="checkbox" name="lgw_sheets_enabled" value="1" <?php checked($enabled); ?>>
            Write confirmed results to Google Sheets automatically
        </label></td>
    </tr>
    <tr>
        <th>Spreadsheet ID</th>
        <td>
            <input type="text" name="lgw_sheets_id" value="<?php echo $sid; ?>" class="regular-text"
                placeholder="1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc">
            <p class="description">The ID from the spreadsheet URL — the long string between <code>/d/</code> and <code>/edit</code>.</p>
        </td>
    </tr>
    <tr>
        <th>Division → Sheet tab mapping</th>
        <td>
            <p class="description">Enter the division name exactly as it appears in the scorecard (e.g. "Division 1"),
            the corresponding sheet tab name, and the published Google Sheets CSV URL for that division (used to validate team names on scorecard submission).</p>
            <table id="lgw-sheets-tabs" style="border-collapse:collapse;margin-bottom:8px">
            <thead><tr>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Division name (in scorecard)</th>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Sheet tab name</th>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Published CSV URL (for team validation)</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($tabs as $i => $entry): ?>
            <tr class="lgw-sheets-row">
                <td style="padding:4px 8px">
                    <input type="text" name="lgw_sheets_div[]"
                        value="<?php echo esc_attr($entry['division'] ?? ''); ?>"
                        placeholder="e.g. Division 1" style="width:160px">
                </td>
                <td style="padding:4px 8px">
                    <input type="text" name="lgw_sheets_tab[]"
                        value="<?php echo esc_attr($entry['tab'] ?? ''); ?>"
                        placeholder="e.g. Div 1" style="width:120px">
                </td>
                <td style="padding:4px 8px">
                    <input type="text" name="lgw_sheets_csv_url[]"
                        value="<?php echo esc_attr($entry['csv_url'] ?? ''); ?>"
                        placeholder="https://docs.google.com/spreadsheets/d/…/pub?…&output=csv"
                        style="width:340px">
                </td>
                <td style="padding:4px 8px">
                    <button type="button" class="button button-small lgw-sheets-remove"
                        onclick="this.closest('tr').remove()"
                        <?php echo count($tabs) <= 1 ? 'style="display:none"' : ''; ?>>Remove</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
            <button type="button" class="button button-small" id="lgw-sheets-add-row">+ Add division</button>

            <script>
            document.getElementById('lgw-sheets-add-row').addEventListener('click', function() {
                var tbody = document.querySelector('#lgw-sheets-tabs tbody');
                var row = document.createElement('tr');
                row.className = 'lgw-sheets-row';
                row.innerHTML = '<td style="padding:4px 8px"><input type="text" name="lgw_sheets_div[]" placeholder="e.g. Division 2" style="width:160px"></td>'
                    + '<td style="padding:4px 8px"><input type="text" name="lgw_sheets_tab[]" placeholder="e.g. Div 2" style="width:120px"></td>'
                    + '<td style="padding:4px 8px"><input type="text" name="lgw_sheets_csv_url[]" placeholder="https://docs.google.com/spreadsheets/d/…" style="width:340px"></td>'
                    + '<td style="padding:4px 8px"><button type="button" class="button button-small lgw-sheets-remove" onclick="this.closest(\'tr\').remove()">Remove</button></td>';
                tbody.appendChild(row);
                document.querySelectorAll('.lgw-sheets-remove').forEach(function(b){ b.style.display=''; });
            });
            </script>
        </td>
    </tr>
    <tr>
        <th>Test connection</th>
        <td>
            <button type="button" class="button" id="lgw-sheets-test" data-nonce="<?php echo $nonce_test; ?>">
                Test Sheets Access
            </button>
            <span id="lgw-sheets-test-result" style="margin-left:10px;font-size:13px"></span>
        </td>
    </tr>
    </table>

    <script>
    (function() {
        var testBtn = document.getElementById('lgw-sheets-test');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var btn = this;
                var res = document.getElementById('lgw-sheets-test-result');
                var sid = document.querySelector('[name="lgw_sheets_id"]').value;
                btn.disabled = true;
                res.textContent = 'Testing…';
                res.style.color = '#333';
                var fd = new FormData();
                fd.append('action',    'lgw_sheets_test');
                fd.append('nonce',     btn.dataset.nonce);
                fd.append('sheets_id', sid);
                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'})
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        btn.disabled    = false;
                        res.textContent = r.success ? '✅ ' + r.data : '❌ ' + (r.data || 'Failed');
                        res.style.color = r.success ? 'green' : 'red';
                    })
                    .catch(function(e) {
                        btn.disabled    = false;
                        res.textContent = '❌ Request failed: ' + e;
                        res.style.color = 'red';
                    });
            });
        }
    })();
    </script>
    <?php
}

// ── Test connection AJAX ──────────────────────────────────────────────────────
add_action('wp_ajax_lgw_sheets_test', 'lgw_ajax_sheets_test');
function lgw_ajax_sheets_test() {
    check_ajax_referer('lgw_sheets_test', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $sid = sanitize_text_field($_POST['sheets_id'] ?? '');
    if (!$sid) wp_send_json_error('No spreadsheet ID provided');

    // Clear token cache so we pick up latest key
    delete_transient('lgw_drive_token');
    $token = lgw_drive_get_access_token();
    if (!$token) wp_send_json_error('Could not obtain auth token — check service account key is uploaded');

    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sid}?fields=properties.title,sheets.properties.title";
    $response = wp_remote_get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Request failed: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200) {
        $msg = $body['error']['message'] ?? "HTTP $code";
        wp_send_json_error("Sheets API error: $msg");
    }

    $title = $body['properties']['title'] ?? 'Unknown';
    $sheet_tabs = array_map(function($s){ return $s['properties']['title']; }, $body['sheets'] ?? []);
    $tab_list   = implode(', ', $sheet_tabs);

    wp_send_json_success("Connected to \"$title\". Tabs: $tab_list");
}

// ── Render Sheets log in admin scorecard History panel ────────────────────────
function lgw_render_sheets_log($post_id) {
    $log = get_post_meta($post_id, 'lgw_sheets_log', true) ?: [];
    if (empty($log)) return;

    $level_cls = ['info' => '#155724', 'warn' => '#856404', 'error' => '#721c24'];
    $level_bg  = ['info' => '#d4edda', 'warn' => '#fff3cd', 'error' => '#f8d7da'];

    // Determine if any actionable (non-info) entries exist — skip retry button if all are info
    $has_actionable = !empty(array_filter($log, function($e){ return ($e['level'] ?? 'info') !== 'info'; }));

    echo '<h4 style="margin:16px 0 8px;font-size:13px;color:#1a2e5a">Sheets Writeback Log</h4>';
    echo '<div style="font-size:12px;font-family:monospace;background:#f6f7f7;border:1px solid #ddd;padding:8px;border-radius:4px;max-height:150px;overflow-y:auto">';
    foreach (array_reverse($log) as $entry) {
        $lvl = $entry['level'] ?? 'info';
        $bg  = $level_bg[$lvl]  ?? '#f6f7f7';
        $col = $level_cls[$lvl] ?? '#333';
        echo '<div style="margin-bottom:4px;padding:2px 6px;border-radius:3px;background:' . $bg . ';color:' . $col . '">';
        echo '<span style="color:#999">' . esc_html($entry['time'] ?? '') . '</span> ';
        echo '<strong>[' . esc_html(strtoupper($lvl)) . ']</strong> ';
        echo esc_html($entry['message'] ?? '');
        echo '</div>';
    }
    echo '</div>';
    $retry_nonce = wp_create_nonce('lgw_sheets_retry');
    if ($has_actionable) {
        echo '<p style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<button type="button" class="button button-small"';
        echo ' onclick="lgwSheetsRetry(' . intval($post_id) . ', \'' . esc_js($retry_nonce) . '\')">';
        echo '&#8635; Retry Sheets writeback';
        echo '</button>';
        echo '<button type="button" class="button button-small"';
        echo ' onclick="lgwSyncOverride(' . intval($post_id) . ', \'' . esc_js($retry_nonce) . '\')">';
        echo '&#8645; Force sync widget override';
        echo '</button>';
        echo '<span id="lgw-sheets-retry-' . intval($post_id) . '" style="font-size:12px"></span>';
        echo '</p>';
    } else {
        // Always show the override sync button so admin can force-sync any confirmed scorecard
        echo '<p style="margin-top:8px">';
        echo '<button type="button" class="button button-small"';
        echo ' onclick="lgwSyncOverride(' . intval($post_id) . ', \'' . esc_js($retry_nonce) . '\')">';
        echo '&#8645; Force sync widget override';
        echo '</button>';
        echo '<span id="lgw-sheets-retry-' . intval($post_id) . '" style="margin-left:8px;font-size:12px"></span>';
        echo '</p>';
    }
    echo '<script>';
    echo 'if(typeof lgwSheetsRetry==="undefined"){';
    echo 'function lgwSheetsRetry(postId, nonce) {';
    echo '    var span = document.getElementById(\'lgw-sheets-retry-\'+postId);';
    echo '    span.textContent = \'Retrying\u2026\';';
    echo '    var fd = new FormData();';
    echo '    fd.append(\'action\',  \'lgw_sheets_retry\');';
    echo '    fd.append(\'nonce\',   nonce);';
    echo '    fd.append(\'post_id\', postId);';
    echo '    var xhr = new XMLHttpRequest();';
    echo '    xhr.open(\'POST\', ajaxurl);';
    echo '    xhr.onload = function() {';
    echo '        try {';
    echo '            var d = JSON.parse(xhr.responseText);';
    echo '            span.textContent = d.success ? \'\u2705 \'+d.data : \'\u274c \'+d.data;';
    echo '            span.style.color = d.success ? \'green\' : \'red\';';
    echo '        } catch(e) { span.textContent = \'Bad response\'; span.style.color=\'red\'; }';
    echo '    };';
    echo '    xhr.onerror = function() { span.textContent = \'Request failed\'; span.style.color=\'red\'; };';
    echo '    xhr.send(fd);';
    echo '}}';
    echo 'if(typeof lgwSyncOverride==="undefined"){';
    echo 'function lgwSyncOverride(postId, nonce) {';
    echo '    var span = document.getElementById(\'lgw-sheets-retry-\'+postId);';
    echo '    span.textContent = \'Syncing\u2026\';';
    echo '    var fd = new FormData();';
    echo '    fd.append(\'action\',  \'lgw_sync_override\');';
    echo '    fd.append(\'nonce\',   nonce);';
    echo '    fd.append(\'post_id\', postId);';
    echo '    var xhr = new XMLHttpRequest();';
    echo '    xhr.open(\'POST\', ajaxurl);';
    echo '    xhr.onload = function() {';
    echo '        try {';
    echo '            var d = JSON.parse(xhr.responseText);';
    echo '            span.textContent = d.success ? \'\u2705 \'+d.data : \'\u274c \'+d.data;';
    echo '            span.style.color = d.success ? \'green\' : \'red\';';
    echo '        } catch(e) { span.textContent = \'Bad response\'; span.style.color=\'red\'; }';
    echo '    };';
    echo '    xhr.onerror = function() { span.textContent = \'Request failed\'; span.style.color=\'red\'; };';
    echo '    xhr.send(fd);';
    echo '}}';
    echo '</script>';
}

// ── AJAX: Get team names for a division (for scorecard form validation) ────────
add_action('wp_ajax_nopriv_lgw_get_division_teams', 'lgw_ajax_get_division_teams');
add_action('wp_ajax_lgw_get_division_teams',        'lgw_ajax_get_division_teams');
function lgw_ajax_get_division_teams() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');

    $division = sanitize_text_field($_POST['division'] ?? '');
    if (!$division) wp_send_json_error('No division specified');

    $opts    = get_option('lgw_drive', []);
    $mapping = $opts['sheets_tabs'] ?? [];

    // Find the CSV URL for this division
    $csv_url = '';
    foreach ($mapping as $entry) {
        if (strcasecmp(trim($entry['division'] ?? ''), $division) === 0) {
            $csv_url = trim($entry['csv_url'] ?? '');
            break;
        }
    }

    if (!$csv_url) {
        wp_send_json_error('No CSV URL configured for this division');
    }

    // Validate it's a Google Sheets URL
    $parsed_url = parse_url($csv_url);
    if (!isset($parsed_url['host']) || $parsed_url['host'] !== 'docs.google.com') {
        wp_send_json_error('Invalid CSV URL for this division');
    }

    // Fetch (reuse existing transient cache)
    $cache_key = 'lgw_csv_' . md5($csv_url);
    $body = get_transient($cache_key);
    if ($body === false) {
        $response = wp_remote_get($csv_url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0']);
        if (is_wp_error($response)) {
            wp_send_json_error('Could not fetch division data: ' . $response->get_error_message());
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Could not fetch division data (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
        }
        $body = wp_remote_retrieve_body($response);
        $cache_mins = intval(get_option('lgw_cache_mins', 5));
        set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);
    }

    // Parse CSV into rows then extract teams and fixtures
    $csv_parsed = lgw_sheets_parse_division_csv($body);
    wp_send_json_success(['teams' => $csv_parsed['teams'], 'fixtures' => $csv_parsed['fixtures']]);
}

/**
 * Parse a division CSV and return both team names and fixture pairings.
 *
 * Returns:
 *   teams    — array of team name strings from the LEAGUE TABLE section
 *   fixtures — array of ['home' => string, 'away' => string] from the FIXTURES section
 */
function lgw_sheets_parse_division_csv($csv_body) {
    $rows = [];
    foreach (explode("\n", $csv_body) as $line) {
        $line = rtrim($line, "\r");
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }
    $n = count($rows);

    // ── League table teams ────────────────────────────────────────────────────
    $teams = [];
    $i = 0;
    while ($i < $n && strpos(implode('', $rows[$i]), 'LEAGUE TABLE') === false) $i++;
    $i++;
    while ($i < $n && implode('', $rows[$i]) === '') $i++;
    while ($i < $n && ($rows[$i][0] ?? '') !== 'POS') $i++;
    $i++;
    while ($i < $n) {
        $pos  = trim($rows[$i][0] ?? '');
        $team = trim($rows[$i][1] ?? '');
        if ($pos === '' || $team === '') break;
        if (is_numeric($pos)) $teams[] = $team;
        $i++;
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────
    $fixtures = [];
    $i = 0;
    while ($i < $n && strpos(implode('', $rows[$i]), 'FIXTURES') === false) $i++;
    $i++;
    if ($i < $n) {
        // Detect column positions from header row containing HPts/HTeam
        $col_hteam = 2;
        $col_ateam = 10;
        for ($h = $i; $h < min($i + 5, $n); $h++) {
            if (strpos(implode('', $rows[$h]), 'HPts') !== false || strpos(implode('', $rows[$h]), 'HTeam') !== false) {
                foreach ($rows[$h] as $c => $val) {
                    $v = trim($val);
                    if ($v === 'HTeam') $col_hteam = $c;
                    if ($v === 'ATeam') $col_ateam = $c;
                }
                $i = $h + 1;
                break;
            }
        }
        $date_re = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
        while ($i < $n) {
            $row   = $rows[$i];
            $first = trim($row[0] ?? ($row[1] ?? ''));
            if (!preg_match($date_re, $first)) {
                $home = trim($row[$col_hteam] ?? '');
                $away = trim($row[$col_ateam] ?? '');
                if ($home && $away) {
                    $fixtures[] = ['home' => $home, 'away' => $away];
                }
            }
            $i++;
        }
    }

    return ['teams' => $teams, 'fixtures' => $fixtures];
}
