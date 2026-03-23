<?php
/**
 * NIPGL Google Sheets Writeback - v5.17.10
 *
 * On scorecard confirmation, finds the matching fixture row in the Google Sheet
 * and writes home score, away score, home points, away points into it.
 * Also marks the row with an "Online" flag in a dedicated column.
 *
 * Uses the same service account JWT auth as nipgl-drive.php.
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
add_action('nipgl_scorecard_confirmed',    'nipgl_sheets_on_confirmed');
add_action('nipgl_scorecard_admin_edited', 'nipgl_sheets_on_confirmed');

function nipgl_sheets_on_confirmed($post_id) {
    $opts = get_option('nipgl_drive', []);
    if (empty($opts['sheets_enabled'])) return;
    nipgl_sheets_write_result($post_id);
}

// ── Main entry point ──────────────────────────────────────────────────────────
function nipgl_sheets_write_result($post_id) {
    $sc = get_post_meta($post_id, 'nipgl_scorecard_data', true);
    if (!$sc) {
        nipgl_sheets_log($post_id, 'error', 'No scorecard data found');
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
        nipgl_sheets_log($post_id, 'error', 'Missing team names in scorecard');
        return false;
    }

    // Get sheet config
    $opts        = get_option('nipgl_drive', []);
    $spreadsheet = trim($opts['sheets_id'] ?? '1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc');

    // Resolve sheet tab for this division
    $tab = nipgl_sheets_tab_for_division($division, $opts);
    if (!$tab) {
        nipgl_sheets_log($post_id, 'warn', "No sheet tab mapped for division: $division — skipping writeback");
        return false;
    }

    // Get auth token
    $token = nipgl_drive_get_access_token();
    if (!$token) {
        nipgl_sheets_log($post_id, 'error', 'Could not obtain Sheets auth token');
        return false;
    }

    // Convert scorecard date dd/mm/yyyy → sheet format "Sat 05-Apr-2025"
    $sheet_date = nipgl_sheets_format_date($date_raw);

    // Fetch the sheet data to find the right row
    $sheet_data = nipgl_sheets_fetch($token, $spreadsheet, $tab);
    if ($sheet_data === false) {
        nipgl_sheets_log($post_id, 'error', "Failed to fetch sheet data for tab: $tab");
        return false;
    }

    // Detect column positions from header row
    $cols = nipgl_sheets_detect_cols($sheet_data);

    // Find the matching row
    $match = nipgl_sheets_find_row($sheet_data, $cols, $home_team, $away_team, $sheet_date);
    if ($match === false) {
        nipgl_sheets_log($post_id, 'warn', "Could not find fixture row: '$home_team' v '$away_team' on '$sheet_date' in tab '$tab'");
        return false;
    }

    [$row_index, $row_data] = $match;

    // Build batchUpdate requests — write scores, points, and online flag
    $requests = nipgl_sheets_build_update($spreadsheet, $tab, $row_index, $cols, [
        'home_score' => $home_score,
        'away_score' => $away_score,
        'home_pts'   => $home_pts,
        'away_pts'   => $away_pts,
    ]);

    $result = nipgl_sheets_batch_update($token, $spreadsheet, $requests);
    if ($result === false) {
        nipgl_sheets_log($post_id, 'error', "batchUpdate failed for row $row_index in tab '$tab'");
        return false;
    }

    nipgl_sheets_log($post_id, 'info',
        "Written to sheet: tab='$tab', row=" . ($row_index + 1)
        . ", HScore=$home_score, AScore=$away_score, HPts=$home_pts, APts=$away_pts"
    );
    return true;
}

// ── Date conversion: dd/mm/yyyy → "Sat 05-Apr-2025" ──────────────────────────
function nipgl_sheets_format_date($dmy) {
    if (!$dmy) return '';
    $parts = explode('/', $dmy);
    if (count($parts) !== 3) return $dmy;
    [$d, $m, $y] = $parts;
    $ts = mktime(0, 0, 0, (int)$m, (int)$d, (int)$y);
    if (!$ts) return $dmy;
    // Format: "Sat 05-Apr-2025"
    return date('D d-M-Y', $ts);
}

// ── Resolve division name → sheet tab name ────────────────────────────────────
function nipgl_sheets_tab_for_division($division, $opts) {
    // Try exact match in configured mapping
    $mapping = $opts['sheets_tabs'] ?? [];
    foreach ($mapping as $entry) {
        if (strcasecmp(trim($entry['division'] ?? ''), $division) === 0) {
            return trim($entry['tab']);
        }
    }
    // Fallback: if only one tab configured, use it regardless of division
    if (count($mapping) === 1 && !empty($mapping[0]['tab'])) {
        return trim($mapping[0]['tab']);
    }
    return '';
}

// ── Fetch sheet values ────────────────────────────────────────────────────────
function nipgl_sheets_fetch($token, $spreadsheet, $tab) {
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
function nipgl_sheets_detect_cols($rows) {
    // Defaults matching nipgl-widget.js parseFixtureGroups
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
function nipgl_sheets_find_row($rows, $cols, $home_team, $away_team, $sheet_date) {
    $date_re = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $current_date = '';

    foreach ($rows as $i => $row) {
        $first = trim($row[0] ?? $row[1] ?? '');
        if (preg_match($date_re, $first)) {
            $current_date = $first;
            continue;
        }

        $ht = trim($row[$cols['hteam']] ?? '');
        $at = trim($row[$cols['ateam']] ?? '');
        if (!$ht || !$at) continue;

        $home_match = strcasecmp($ht, $home_team) === 0;
        $away_match = strcasecmp($at, $away_team) === 0;

        if ($home_match && $away_match) {
            // If we have a date to match and it doesn't match, keep looking
            if ($sheet_date && $current_date) {
                if (strcasecmp($current_date, $sheet_date) !== 0) continue;
            }
            return [$i, $row];
        }
    }
    return false;
}

// ── Build batchUpdate requests ────────────────────────────────────────────────
function nipgl_sheets_build_update($spreadsheet, $tab, $row_index, $cols, $values) {
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
        // Skip fields with no value — don't overwrite existing sheet data with blanks
        if ($val === '' || $val === null) continue;
        $a1 = "'" . addslashes($tab) . "'!" . $col_letter($col_idx) . $sheet_row;
        // Cast numeric strings to numbers so Sheets stores them as numbers, not text
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
function nipgl_sheets_batch_update($token, $spreadsheet, $data) {
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

// ── Per-scorecard log (mirrors nipgl_drive_log pattern) ──────────────────────
function nipgl_sheets_log($post_id, $level, $message) {
    $log   = get_post_meta($post_id, 'nipgl_sheets_log', true) ?: [];
    $log[] = [
        'time'    => current_time('mysql'),
        'level'   => $level,
        'message' => $message,
    ];
    // Keep last 20 entries
    if (count($log) > 20) $log = array_slice($log, -20);
    update_post_meta($post_id, 'nipgl_sheets_log', $log);
}

// ── Manual retry AJAX ─────────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_sheets_retry', 'nipgl_ajax_sheets_retry');
function nipgl_ajax_sheets_retry() {
    check_ajax_referer('nipgl_sheets_retry', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post_id');
    $ok = nipgl_sheets_write_result($post_id);
    if ($ok) {
        wp_send_json_success('Result written to sheet.');
    } else {
        $log = get_post_meta($post_id, 'nipgl_sheets_log', true) ?: [];
        $last = end($log);
        wp_send_json_error('Failed: ' . ($last['message'] ?? 'unknown error'));
    }
}

// ── Settings: save sheets config ──────────────────────────────────────────────
// Called from nipgl_save_settings() in nipgl-division-widget.php
function nipgl_sheets_save_settings() {
    $opts = get_option('nipgl_drive', []);

    $opts['sheets_enabled'] = !empty($_POST['nipgl_sheets_enabled']) ? 1 : 0;
    $opts['sheets_id']      = sanitize_text_field($_POST['nipgl_sheets_id'] ?? '1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc');

    // Tab mappings: array of {division, tab, csv_url}
    $divs     = $_POST['nipgl_sheets_div']     ?? [];
    $tabs     = $_POST['nipgl_sheets_tab']     ?? [];
    $csv_urls = $_POST['nipgl_sheets_csv_url'] ?? [];
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

    update_option('nipgl_drive', $opts);
}

// ── Settings UI section ───────────────────────────────────────────────────────
function nipgl_sheets_settings_html() {
    $opts    = get_option('nipgl_drive', []);
    $enabled = !empty($opts['sheets_enabled']);
    $sid     = esc_attr($opts['sheets_id'] ?? '1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc');
    $tabs    = $opts['sheets_tabs'] ?? [];
    if (empty($tabs)) $tabs = [['division' => '', 'tab' => '', 'csv_url' => '']];
    $nonce_test = wp_create_nonce('nipgl_sheets_test');
    ?>
    <hr>
    <h2>Google Sheets Writeback</h2>
    <p>When a scorecard is confirmed, automatically write the result into the matching row in your Google Sheet.
    The same service account used for Drive must have <strong>Editor</strong> access to the spreadsheet.</p>

    <table class="form-table">
    <tr>
        <th>Enable Sheets writeback</th>
        <td><label>
            <input type="checkbox" name="nipgl_sheets_enabled" value="1" <?php checked($enabled); ?>>
            Write confirmed results to Google Sheets automatically
        </label></td>
    </tr>
    <tr>
        <th>Spreadsheet ID</th>
        <td>
            <input type="text" name="nipgl_sheets_id" value="<?php echo $sid; ?>" class="regular-text"
                placeholder="1oCJhKdT5zFxPhqFfNzQOesWNbb4bS9JkKdOXJ_FpdRc">
            <p class="description">The ID from the spreadsheet URL — the long string between <code>/d/</code> and <code>/edit</code>.</p>
        </td>
    </tr>
    <tr>
        <th>Division → Sheet tab mapping</th>
        <td>
            <p class="description">Enter the division name exactly as it appears in the scorecard (e.g. "Division 1"),
            the corresponding sheet tab name, and the published Google Sheets CSV URL for that division (used to validate team names on scorecard submission).</p>
            <table id="nipgl-sheets-tabs" style="border-collapse:collapse;margin-bottom:8px">
            <thead><tr>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Division name (in scorecard)</th>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Sheet tab name</th>
                <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Published CSV URL (for team validation)</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($tabs as $i => $entry): ?>
            <tr class="nipgl-sheets-row">
                <td style="padding:4px 8px">
                    <input type="text" name="nipgl_sheets_div[]"
                        value="<?php echo esc_attr($entry['division'] ?? ''); ?>"
                        placeholder="e.g. Division 1" style="width:160px">
                </td>
                <td style="padding:4px 8px">
                    <input type="text" name="nipgl_sheets_tab[]"
                        value="<?php echo esc_attr($entry['tab'] ?? ''); ?>"
                        placeholder="e.g. Div 1" style="width:120px">
                </td>
                <td style="padding:4px 8px">
                    <input type="text" name="nipgl_sheets_csv_url[]"
                        value="<?php echo esc_attr($entry['csv_url'] ?? ''); ?>"
                        placeholder="https://docs.google.com/spreadsheets/d/…/pub?…&output=csv"
                        style="width:340px">
                </td>
                <td style="padding:4px 8px">
                    <button type="button" class="button button-small nipgl-sheets-remove"
                        onclick="this.closest('tr').remove()"
                        <?php echo count($tabs) <= 1 ? 'style="display:none"' : ''; ?>>Remove</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
            <button type="button" class="button button-small" id="nipgl-sheets-add-row">+ Add division</button>

            <script>
            document.getElementById('nipgl-sheets-add-row').addEventListener('click', function() {
                var tbody = document.querySelector('#nipgl-sheets-tabs tbody');
                var row = document.createElement('tr');
                row.className = 'nipgl-sheets-row';
                row.innerHTML = '<td style="padding:4px 8px"><input type="text" name="nipgl_sheets_div[]" placeholder="e.g. Division 2" style="width:160px"></td>'
                    + '<td style="padding:4px 8px"><input type="text" name="nipgl_sheets_tab[]" placeholder="e.g. Div 2" style="width:120px"></td>'
                    + '<td style="padding:4px 8px"><input type="text" name="nipgl_sheets_csv_url[]" placeholder="https://docs.google.com/spreadsheets/d/…" style="width:340px"></td>'
                    + '<td style="padding:4px 8px"><button type="button" class="button button-small nipgl-sheets-remove" onclick="this.closest(\'tr\').remove()">Remove</button></td>';
                tbody.appendChild(row);
                document.querySelectorAll('.nipgl-sheets-remove').forEach(function(b){ b.style.display=''; });
            });
            </script>
        </td>
    </tr>
    <tr>
        <th>Test connection</th>
        <td>
            <button type="button" class="button" id="nipgl-sheets-test" data-nonce="<?php echo $nonce_test; ?>">
                Test Sheets Access
            </button>
            <span id="nipgl-sheets-test-result" style="margin-left:10px;font-size:13px"></span>
        </td>
    </tr>
    </table>

    <script>
    (function() {
        var testBtn = document.getElementById('nipgl-sheets-test');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var btn = this;
                var res = document.getElementById('nipgl-sheets-test-result');
                var sid = document.querySelector('[name="nipgl_sheets_id"]').value;
                btn.disabled = true;
                res.textContent = 'Testing…';
                res.style.color = '#333';
                var fd = new FormData();
                fd.append('action',    'nipgl_sheets_test');
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
add_action('wp_ajax_nipgl_sheets_test', 'nipgl_ajax_sheets_test');
function nipgl_ajax_sheets_test() {
    check_ajax_referer('nipgl_sheets_test', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $sid = sanitize_text_field($_POST['sheets_id'] ?? '');
    if (!$sid) wp_send_json_error('No spreadsheet ID provided');

    // Clear token cache so we pick up latest key
    delete_transient('nipgl_drive_token');
    $token = nipgl_drive_get_access_token();
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
function nipgl_render_sheets_log($post_id) {
    $log = get_post_meta($post_id, 'nipgl_sheets_log', true) ?: [];
    if (empty($log)) return;

    $level_cls = ['info' => '#155724', 'warn' => '#856404', 'error' => '#721c24'];
    $level_bg  = ['info' => '#d4edda', 'warn' => '#fff3cd', 'error' => '#f8d7da'];

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
    ?>
    <p style="margin-top:8px">
        <button type="button" class="button button-small"
            onclick="nipglSheetsRetry(<?php echo $post_id; ?>, '<?php echo wp_create_nonce('nipgl_sheets_retry'); ?>')">
            ↺ Retry Sheets writeback
        </button>
        <span id="nipgl-sheets-retry-<?php echo $post_id; ?>" style="margin-left:8px;font-size:12px"></span>
    </p>
    <script>
    function nipglSheetsRetry(postId, nonce) {
        var span = document.getElementById('nipgl-sheets-retry-'+postId);
        span.textContent = 'Retrying\u2026';
        var fd = new FormData();
        fd.append('action',  'nipgl_sheets_retry');
        fd.append('nonce',   nonce);
        fd.append('post_id', postId);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxurl);
        xhr.onload = function() {
            try {
                var d = JSON.parse(xhr.responseText);
                span.textContent = d.success ? '\u2705 '+d.data : '\u274c '+d.data;
                span.style.color = d.success ? 'green' : 'red';
            } catch(e) { span.textContent = 'Bad response'; span.style.color='red'; }
        };
        xhr.onerror = function() { span.textContent = 'Request failed'; span.style.color='red'; };
        xhr.send(fd);
    }
    </script>
    <?php
}

// ── AJAX: Get team names for a division (for scorecard form validation) ────────
add_action('wp_ajax_nopriv_nipgl_get_division_teams', 'nipgl_ajax_get_division_teams');
add_action('wp_ajax_nipgl_get_division_teams',        'nipgl_ajax_get_division_teams');
function nipgl_ajax_get_division_teams() {
    check_ajax_referer('nipgl_submit_nonce', 'nonce');

    $division = sanitize_text_field($_POST['division'] ?? '');
    if (!$division) wp_send_json_error('No division specified');

    $opts    = get_option('nipgl_drive', []);
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
    $cache_key = 'nipgl_csv_' . md5($csv_url);
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
        $cache_mins = intval(get_option('nipgl_cache_mins', 5));
        set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);
    }

    // Parse CSV into rows then extract teams and fixtures
    $csv_parsed = nipgl_sheets_parse_division_csv($body);
    wp_send_json_success(['teams' => $csv_parsed['teams'], 'fixtures' => $csv_parsed['fixtures']]);
}

/**
 * Parse a division CSV and return both team names and fixture pairings.
 *
 * Returns:
 *   teams    — array of team name strings from the LEAGUE TABLE section
 *   fixtures — array of ['home' => string, 'away' => string] from the FIXTURES section
 */
function nipgl_sheets_parse_division_csv($csv_body) {
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
