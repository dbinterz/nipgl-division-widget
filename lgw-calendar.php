<?php
/**
 * LGW Calendar Widget
 * Renders a mobile-friendly monthly calendar from a .xlsx file in the Media Library.
 * Shortcode: [lgw_calendar xlsx="..." title="..."]
 *   xlsx=  Google Sheets xlsx export URL, e.g.:
 *          https://docs.google.com/spreadsheets/d/{ID}/export?format=xlsx&gid={GID}
 *          The sheet must be shared publicly (or with link) for the server to fetch it.
 *   title= Widget heading (optional, default "Calendar")
 *
 * The xlsx is fetched server-side, parsed via ZipArchive + XML (no Composer dependency),
 * and cached as a WordPress transient (respects the lgw_cache_mins setting).
 * Cell values, horizontal merge spans, and fill colours are all read in one pass.
 *
 * @version 7.1.16
 */

if (!defined('ABSPATH')) exit;

// ── Enqueue calendar assets ───────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'lgw_calendar_enqueue');
function lgw_calendar_enqueue() {
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'lgw_calendar')) return;

    wp_enqueue_style(
        'lgw-calendar',
        plugin_dir_url(__FILE__) . 'lgw-calendar.css',
        array('lgw-widget'),
        LGW_VERSION
    );
    wp_enqueue_script(
        'lgw-calendar',
        plugin_dir_url(__FILE__) . 'lgw-calendar.js',
        array(),
        LGW_VERSION,
        true
    );
    wp_localize_script('lgw-calendar', 'lgwCalendarData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ));
}

// ── Shortcode ─────────────────────────────────────────────────────────────────
add_shortcode('lgw_calendar', 'lgw_calendar_shortcode');
function lgw_calendar_shortcode($atts) {
    $atts = shortcode_atts(array(
        'xlsx'  => '',
        'title' => 'Calendar',
    ), $atts);

    if (!$atts['xlsx']) return '<p>No xlsx URL provided for LGW Calendar.</p>';

    $id           = 'lgw-cal-' . substr(md5($atts['xlsx']), 0, 8);
    $xlsx_escaped = esc_attr($atts['xlsx']);
    $title_html   = '';
    if (!empty($atts['title'])) {
        $title_html = '<div class="lgw-title">' . esc_html($atts['title']) . '</div>';
    }

    return $title_html
        . '<div class="lgw-cal-wrap" id="' . $id . '"'
        . ' data-xlsx="' . $xlsx_escaped . '">'
        . '<div class="lgw-cal-status">Loading&hellip;</div>'
        . '</div>';
}

// ── AJAX: parse xlsx and return calendar data as JSON ─────────────────────────
add_action('wp_ajax_lgw_cal_xlsx',        'lgw_cal_xlsx_handler');
add_action('wp_ajax_nopriv_lgw_cal_xlsx', 'lgw_cal_xlsx_handler');
function lgw_cal_xlsx_handler() {
    $xlsx_url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
    if (!$xlsx_url) { wp_send_json_error('Missing url'); }

    // Only allow Google Sheets/Drive export URLs
    $parsed = parse_url($xlsx_url);
    $host   = $parsed['host'] ?? '';
    if (!in_array($host, array('docs.google.com', 'drive.google.com'), true)) {
        wp_send_json_error('URL must be a Google Sheets or Drive export URL');
    }

    if (!class_exists('ZipArchive')) {
        wp_send_json_error('PHP ZipArchive extension is not available on this server');
    }

    $cache_key = 'lgw_cal_xlsx_' . md5($xlsx_url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-LGW-Cache: HIT');
        echo $cached;
        exit;
    }

    // Fetch the xlsx from Google
    $response = wp_remote_get($xlsx_url, array(
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0',
    ));

    // Retry once on failure
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        sleep(1);
        $response = wp_remote_get($xlsx_url, array('timeout' => 20, 'user-agent' => 'Mozilla/5.0'));
    }

    if (is_wp_error($response)) {
        // Serve stale cache as fallback
        $stale = get_transient('lgw_cal_xlsx_stale_' . md5($xlsx_url));
        if ($stale !== false) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-LGW-Cache: STALE');
            echo $stale;
            exit;
        }
        wp_send_json_error('Could not reach Google Sheets: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        wp_send_json_error('Google returned HTTP ' . $code . ' — check the sheet is shared publicly');
    }

    $xlsx_bytes = wp_remote_retrieve_body($response);

    // Write to a temp file so ZipArchive can open it
    $tmp = wp_tempnam('lgw_cal_') . '.xlsx';
    if (file_put_contents($tmp, $xlsx_bytes) === false) {
        wp_send_json_error('Could not write temporary file');
    }

    try {
        $data = lgw_parse_calendar_xlsx($tmp);
    } catch (Exception $e) {
        @unlink($tmp);
        wp_send_json_error('Error parsing xlsx: ' . $e->getMessage());
    }
    @unlink($tmp);

    $json = wp_json_encode($data);

    // Cache for configured duration (same as CSV — default 5 minutes)
    $cache_mins = intval(get_option('lgw_cache_mins', 5));
    set_transient($cache_key, $json, $cache_mins * MINUTE_IN_SECONDS);
    // Stale fallback for 24 hours
    set_transient('lgw_cal_xlsx_stale_' . md5($xlsx_url), $json, DAY_IN_SECONDS);

    header('Content-Type: application/json; charset=utf-8');
    header('X-LGW-Cache: MISS');
    echo $json;
    exit;
}

// ── xlsx parser ───────────────────────────────────────────────────────────────
function lgw_parse_calendar_xlsx($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Could not open xlsx file');
    }

    // 1. Shared strings
    $shared_strings = array();
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml !== false) {
        $ss = lgw_cal_parse_xml($ss_xml);
        foreach ($ss->xpath('//si') as $si) {
            $text = '';
            foreach ($si->xpath('.//t') as $t) { $text .= (string) $t; }
            $shared_strings[] = $text;
        }
    }

    // 2. Styles: styleIndex -> hex fill colour
    $style_colours = array();
    $styles_xml    = $zip->getFromName('xl/styles.xml');
    if ($styles_xml !== false) {
        $sx = lgw_cal_parse_xml($styles_xml);

        $fill_colours = array();
        $fill_idx = 0;
        foreach ($sx->xpath('//fills/fill') as $fill) {
            $rgb = null;
            $fg  = $fill->xpath('.//fgColor[@rgb]');
            if ($fg) {
                $raw = strtoupper((string) $fg[0]['rgb']);
                if (!in_array($raw, array('00000000','FF000000','FFFFFFFF','00FFFFFF'))) {
                    $rgb = '#' . substr($raw, 2);
                }
            }
            $fill_colours[$fill_idx++] = $rgb;
        }

        $xf_idx = 0;
        foreach ($sx->xpath('//cellXfs/xf') as $xf) {
            $fid = isset($xf['fillId']) ? (int)(string)$xf['fillId'] : 0;
            $style_colours[$xf_idx++] = isset($fill_colours[$fid]) ? $fill_colours[$fid] : null;
        }
    }

    // 3. Sheet: cells + merge map
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        $zip->close();
        throw new Exception('sheet1.xml not found in xlsx');
    }
    $sx = lgw_cal_parse_xml($sheet_xml);

    // Merge map: "row:col" => spanWidth (horizontal, cols 1-7 only)
    $merge_map = array();
    foreach ($sx->xpath('//mergeCell') as $mc) {
        $ref = (string) $mc['ref'];
        if (!$ref || strpos($ref, ':') === false) continue;
        list($from, $to) = explode(':', $ref);
        if (!preg_match('/^([A-Z]+)(\d+)$/', $from, $fm)) continue;
        if (!preg_match('/^([A-Z]+)(\d+)$/', $to,   $tm)) continue;
        $c1 = lgw_cal_col_to_num($fm[1]);
        $c2 = lgw_cal_col_to_num($tm[1]);
        $r1 = (int) $fm[2];
        if ($c1 <= 7 && $c2 > $c1) {
            $merge_map["{$r1}:{$c1}"] = $c2 - $c1 + 1;
        }
    }

    // Cell data: sparse [rowNum][colNum] = { v, c }
    $cells = array();
    foreach ($sx->xpath('//row') as $row_el) {
        $row_num = (int)(string)$row_el['r'];
        foreach ($row_el->xpath('.//c') as $cell_el) {
            $ref = (string) $cell_el['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $cm)) continue;
            $col_num = lgw_cal_col_to_num($cm[1]);
            if ($col_num > 7) continue;

            $val  = '';
            $type = (string)($cell_el['t'] ?? '');
            $v_el = $cell_el->xpath('.//v');
            if ($v_el) {
                $raw = (string) $v_el[0];
                $val = ($type === 's' && isset($shared_strings[(int)$raw]))
                     ? $shared_strings[(int)$raw]
                     : $raw;
            }
            $val = trim($val);

            $style_idx = isset($cell_el['s']) ? (int)(string)$cell_el['s'] : 0;
            $colour    = isset($style_colours[$style_idx]) ? $style_colours[$style_idx] : null;

            if ($val !== '' || $colour !== null) {
                if (!isset($cells[$row_num])) $cells[$row_num] = array();
                $cells[$row_num][$col_num] = array('v' => $val, 'c' => $colour);
            }
        }
    }

    $zip->close();

    return array('cells' => $cells, 'merges' => $merge_map);
}

function lgw_cal_parse_xml($xml_str) {
    libxml_use_internal_errors(true);
    // Strip namespace declarations so xpath works without prefixes
    $xml_str = preg_replace('/\sxmlns[^=]*="[^"]*"/', '', $xml_str);
    $xml = simplexml_load_string($xml_str);
    if (!$xml) throw new Exception('XML parse error');
    return $xml;
}

function lgw_cal_col_to_num($letters) {
    $n = 0;
    $letters = strtoupper(trim($letters));
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n;
}
