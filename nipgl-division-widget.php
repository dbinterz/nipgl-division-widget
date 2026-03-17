<?php
/**
 * Plugin Name: NIPGL Division Widget
 * Description: Mobile-friendly league tables, fixtures, and scorecard submission for bowls leagues. Fetches live data from Google Sheets CSV. Supports per-club passphrase authentication, two-party scorecard confirmation, photo/Excel parsing via AI, player appearance tracking, sponsor branding, and animated cup bracket draws.
 * Version: 6.4.22
 * Author: NIPGL
 * Plugin URI: https://github.com/dbinterz/nipgl-division-widget
 * GitHub Plugin URI: https://github.com/dbinterz/nipgl-division-widget
 * Primary Branch: main
 * Release Asset: true
 */

define('NIPGL_PLUGIN_FILE', __FILE__);
define('NIPGL_VERSION', '6.4.22');

// Include scorecard feature
require_once plugin_dir_path(__FILE__) . 'nipgl-draw.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-scorecards.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-cup.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-champ.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-players.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-sc-admin.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-drive.php';
require_once plugin_dir_path(__FILE__) . 'nipgl-sheets.php';

// ── Auto-updater (checks GitHub releases) ────────────────────────────────────
add_filter('pre_set_site_transient_update_plugins', 'nipgl_check_for_update');
function nipgl_check_for_update($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = plugin_basename(__FILE__);
    $current_version = NIPGL_VERSION;
    $github_user = 'dbinterz';
    $github_repo = 'nipgl-division-widget';

    $cache_key = 'nipgl_github_update';
    $release = get_transient($cache_key);

    if ($release === false) {
        $response = wp_remote_get(
            "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
            array('headers' => array('Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version')))
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $transient;
        }
        $release = json_decode(wp_remote_retrieve_body($response));
        set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
    }

    if (empty($release->tag_name)) return $transient;

    $latest_version = ltrim($release->tag_name, 'v');

    if (version_compare($latest_version, $current_version, '>')) {
        $transient->response[$plugin_slug] = (object) array(
            'slug'        => 'nipgl-division-widget',
            'plugin'      => $plugin_slug,
            'new_version' => $latest_version,
            'url'         => "https://github.com/{$github_user}/{$github_repo}",
            'package'     => $release->zipball_url,
        );
    }

    return $transient;
}

// Show plugin info popup from GitHub release notes
add_filter('plugins_api', 'nipgl_plugin_info', 10, 3);
function nipgl_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'nipgl-division-widget') return $result;

    $github_user = 'dbinterz';
    $github_repo = 'nipgl-division-widget';

    $response = wp_remote_get(
        "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
        array('headers' => array('Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version')))
    );
    if (is_wp_error($response)) return $result;

    $release = json_decode(wp_remote_retrieve_body($response));
    if (empty($release->tag_name)) return $result;

    return (object) array(
        'name'          => 'NIPGL Division Widget',
        'slug'          => 'nipgl-division-widget',
        'version'       => ltrim($release->tag_name, 'v'),
        'author'        => 'NIPGL',
        'homepage'      => "https://github.com/{$github_user}/{$github_repo}",
        'sections'      => array(
            'description' => 'Scorecard records submitted via the NIPGL scorecard submission form.',
            'changelog'   => nl2br(isset($release->body) ? esc_html($release->body) : 'See GitHub releases for changelog.'),
        ),
        'download_link' => $release->zipball_url,
    );
}

// Check for updates now action
add_action('admin_post_nipgl_check_updates', 'nipgl_check_updates_now');
function nipgl_check_updates_now() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_check_updates_nonce');
    // Clear the cached GitHub release so next check hits the API fresh
    delete_transient('nipgl_github_update');
    // Also clear WordPress's own plugin update transient so it re-checks immediately
    delete_site_transient('update_plugins');
    wp_redirect(admin_url('admin.php?page=nipgl-settings&updated=1'));
    exit;
}
add_action('upgrader_process_complete', 'nipgl_clear_update_cache', 10, 2);
function nipgl_clear_update_cache($upgrader, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        delete_transient('nipgl_github_update');
    }
}

// ── 1. CSV Proxy ─────────────────────────────────────────────────────────────
add_action('wp_ajax_nipgl_csv', 'nipgl_csv_proxy');
add_action('wp_ajax_nopriv_nipgl_csv', 'nipgl_csv_proxy');

function nipgl_csv_proxy() {
    $allowed_host = 'docs.google.com';
    $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
    if (!$url) { wp_die('Missing url', '', array('response' => 400)); }
    $parsed = parse_url($url);
    if (!isset($parsed['host']) || $parsed['host'] !== $allowed_host) {
        wp_die('Forbidden', '', array('response' => 403));
    }

    // Cache key based on the URL
    $cache_key = 'nipgl_csv_' . md5($url);
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        // Serve from cache
        header('Content-Type: text/plain; charset=utf-8');
        header('X-NIPGL-Cache: HIT');
        echo $cached;
        exit;
    }

    $fetch_args = array('timeout' => 10, 'user-agent' => 'Mozilla/5.0');
    $response   = wp_remote_get($url, $fetch_args);

    // Retry once on failure after a short delay
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        sleep(1);
        $response = wp_remote_get($url, $fetch_args);
    }

    if (is_wp_error($response)) {
        // Serve stale cache as fallback if available
        $stale = get_transient('nipgl_csv_stale_' . md5($url));
        if ($stale !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-NIPGL-Cache: STALE');
            echo $stale;
            exit;
        }
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(array('error' => 'Could not reach Google Sheets. Please try again shortly.'));
        exit;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        // Serve stale cache as fallback if available
        $stale = get_transient('nipgl_csv_stale_' . md5($url));
        if ($stale !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-NIPGL-Cache: STALE');
            echo $stale;
            exit;
        }
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(array('error' => 'Google Sheets returned an unexpected response. Please check the sheet is published and the URL is correct.'));
        exit;
    }

    $body = wp_remote_retrieve_body($response);

    // Cache for configured duration (default 5 minutes)
    $cache_mins = intval(get_option('nipgl_cache_mins', 5));
    set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);
    // Store a longer-lived stale copy as fallback for transient fetch failures (24 hours)
    set_transient('nipgl_csv_stale_' . md5($url), $body, DAY_IN_SECONDS);

    header('Content-Type: text/plain; charset=utf-8');
    header('X-NIPGL-Cache: MISS');
    echo $body;
    exit;
}

// ── 2. Enqueue CSS + JS ───────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'nipgl_enqueue');
function nipgl_enqueue() {
    global $post;
    if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'nipgl_division')) return;

    wp_enqueue_style('nipgl-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null);
    wp_enqueue_style('nipgl-widget', plugin_dir_url(__FILE__) . 'nipgl-widget.css', array('nipgl-saira'), NIPGL_VERSION);

    // Register scorecard script here so nipgl-widget can declare it as a dependency.
    // This guarantees nipgl-scorecard.js loads before nipgl-widget.js on any page
    // with [nipgl_division], even if [nipgl_submit] is on a different page.
    if (!wp_script_is('nipgl-scorecard', 'registered')) {
        wp_register_script('nipgl-scorecard', plugin_dir_url(__FILE__) . 'nipgl-scorecard.js', array(), NIPGL_VERSION, true);
        wp_register_style('nipgl-scorecard',  plugin_dir_url(__FILE__) . 'nipgl-scorecard.css', array(), NIPGL_VERSION);
    }
    if (!wp_script_is('nipgl-scorecard', 'enqueued')) {
        wp_enqueue_script('nipgl-scorecard');
        wp_enqueue_style('nipgl-scorecard');
    }
    // Always localise — safe to call multiple times, ensures nipglSubmit is defined
    // even if script was registered (not enqueued) by a prior call.
    wp_localize_script('nipgl-scorecard', 'nipglSubmit', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('nipgl_submit_nonce'),
        'authClub' => nipgl_get_auth_club(),
    ));

    wp_enqueue_script('nipgl-widget', plugin_dir_url(__FILE__) . 'nipgl-widget.js', array('nipgl-scorecard'), NIPGL_VERSION, true);

    $badges       = get_option('nipgl_badges',       array());
    $club_badges  = get_option('nipgl_club_badges',  array());
    $sponsors     = get_option('nipgl_sponsors',     array());
    wp_localize_script('nipgl-widget', 'nipglData', array(
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'scNonce'     => wp_create_nonce('nipgl_submit_nonce'),
        'badges'      => $badges,
        'clubBadges'  => $club_badges,
        'sponsors'    => $sponsors,
    ));
}

// ── 3. Shortcode ─────────────────────────────────────────────────────────────
add_shortcode('nipgl_division', 'nipgl_division_shortcode');
function nipgl_division_shortcode($atts) {
    $atts = shortcode_atts(array(
        'csv'          => '',
        'title'        => '',
        'promote'      => '0',
        'relegate'     => '0',
        'sponsor_img'  => '',
        'sponsor_url'  => '',
        'sponsor_name' => '',
        'color_primary'   => '',  // e.g. #1a2e5a
        'color_secondary' => '',  // e.g. #e8b400
        'color_bg'        => '',  // e.g. #ffffff
    ), $atts);

    if (!$atts['csv']) return '<p>No CSV URL provided.</p>';

    $id          = 'nipgl-' . substr(md5($atts['csv']), 0, 8);
    $csv_escaped = esc_attr($atts['csv']);

    // Resolve theme: shortcode override > global setting > CSS defaults
    $global_theme = get_option('nipgl_theme', array());
    $primary   = sanitize_hex_color($atts['color_primary'])   ?: ($global_theme['color_primary']   ?? '');
    $secondary = sanitize_hex_color($atts['color_secondary']) ?: ($global_theme['color_secondary'] ?? '');
    $bg        = sanitize_hex_color($atts['color_bg'])        ?: ($global_theme['color_bg']        ?? '');

    // Build scoped CSS variable overrides if any theme colour is set
    $theme_style = '';
    if ($primary || $secondary || $bg) {
        $vars = '';
        if ($primary) {
            $vars .= '--nipgl-navy:' . $primary . ';';
            $vars .= '--nipgl-navy-mid:' . nipgl_theme_lighten($primary, 20) . ';';
            $vars .= '--nipgl-tab-bg:' . nipgl_theme_mix($primary, '#ffffff', 85) . ';';
            $vars .= '--nipgl-pts:' . $primary . ';';
        }
        if ($secondary) {
            $vars .= '--nipgl-gold:' . $secondary . ';';
        }
        if ($bg) {
            $vars .= '--nipgl-bg:' . $bg . ';';
            $vars .= '--nipgl-bg-alt:' . nipgl_theme_darken($bg, 5) . ';';
            $vars .= '--nipgl-bg-hover:' . nipgl_theme_darken($bg, 10) . ';';
        }
        $theme_style = '<style>#' . $id . '-wrap{' . $vars . '}</style>';
    }

    // Resolve sponsors: shortcode override takes priority over global setting
    $global_sponsors = get_option('nipgl_sponsors', array());
    if (!empty($atts['sponsor_img'])) {
        $primary_sponsor = array(
            'image' => esc_url($atts['sponsor_img']),
            'url'   => esc_url($atts['sponsor_url']),
            'name'  => esc_attr($atts['sponsor_name']),
        );
        $extra_sponsors = array_slice($global_sponsors, 1); // keep globals beyond first as extras
    } else {
        $primary_sponsor = !empty($global_sponsors[0]) ? $global_sponsors[0] : null;
        $extra_sponsors  = array_slice($global_sponsors, 1);
    }

    // Primary sponsor bar (above title)
    $primary_html = '';
    if ($primary_sponsor && !empty($primary_sponsor['image'])) {
        $img = '<img src="' . esc_url($primary_sponsor['image']) . '" alt="' . esc_attr($primary_sponsor['name'] ?: 'Sponsor') . '" class="nipgl-sponsor-img">';
        $primary_html = '<div class="nipgl-sponsor-bar nipgl-sponsor-primary">'
            . (!empty($primary_sponsor['url']) ? '<a href="' . esc_url($primary_sponsor['url']) . '" target="_blank" rel="noopener">' . $img . '</a>' : $img)
            . '</div>';
    }

    // Title
    $title_html = '';
    if (!empty($atts['title'])) {
        $title_html = '<div class="nipgl-title">' . esc_html($atts['title']) . '</div>';
    }

    // Extra sponsors JSON for JS random rotation below table
    $extra_json = esc_attr(json_encode($extra_sponsors));

    return $theme_style
        . '<div class="nipgl-widget-wrap" id="' . $id . '-wrap">'
        . $primary_html
        . $title_html
        . '<div class="nipgl-w" id="' . $id . '"'
        . ' data-csv="' . $csv_escaped . '"'
        . ' data-promote="' . intval($atts['promote']) . '"'
        . ' data-relegate="' . intval($atts['relegate']) . '"'
        . ' data-sponsors="' . $extra_json . '">'
        . '<div class="nipgl-tabs">'
        . '<div class="nipgl-tab active" data-tab="table">League Table</div>'
        . '<div class="nipgl-tab" data-tab="fixtures">Fixtures &amp; Results</div>'
        . '</div>'
        . '<div class="nipgl-panel active" data-panel="table"><div class="nipgl-status">Loading&hellip;</div></div>'
        . '<div class="nipgl-panel" data-panel="fixtures"><div class="nipgl-status">Loading&hellip;</div></div>'
        . '</div>'
        . '</div>';
}

// ── 4. Admin Settings Page ────────────────────────────────────────────────────
add_action('admin_menu', 'nipgl_admin_menu');
function nipgl_admin_menu() {
    // Top-level NIPGL menu
    add_menu_page(
        'NIPGL Scorecards',
        'NIPGL',
        'manage_options',
        'nipgl-scorecards',
        'nipgl_scorecards_admin_page',
        'dashicons-clipboard',
        30
    );
    // Rename the auto-created first submenu item
    add_submenu_page(
        'nipgl-scorecards',
        'NIPGL Scorecards',
        'Scorecards',
        'manage_options',
        'nipgl-scorecards',
        'nipgl_scorecards_admin_page'
    );
    // Players
    add_submenu_page(
        'nipgl-scorecards',
        'Player Tracking',
        'Players',
        'manage_options',
        'nipgl-players',
        'nipgl_players_admin_page'
    );
    // Cups — function defined in nipgl-cup.php
    if (function_exists('nipgl_cups_register_submenu')) {
        nipgl_cups_register_submenu();
    }
    // Championships — function defined in nipgl-champ.php
    if (function_exists('nipgl_champs_register_submenu')) {
        nipgl_champs_register_submenu();
    }
    // League Setup — cache, API key, Drive/Sheets integration, shortcode reference
    add_submenu_page(
        'nipgl-scorecards',
        'League Setup',
        'League Setup',
        'manage_options',
        'nipgl-league-setup',
        'nipgl_league_setup_page'
    );
    // Settings — theme, sponsors, club badges, clubs/passphrases
    add_submenu_page(
        'nipgl-scorecards',
        'NIPGL Settings',
        'Settings',
        'manage_options',
        'nipgl-settings',
        'nipgl_settings_page'
    );
}

function nipgl_scorecards_admin_page() {
    $posts = get_posts(array('post_type'=>'nipgl_scorecard','posts_per_page'=>100,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'));
    $nonce = wp_create_nonce('nipgl_admin_nonce');
    ?>
    <div class="wrap">
    <h1>Submitted Scorecards</h1>
    <style>
    .nipgl-sc-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600}
    .nipgl-sc-status.pending{background:#fff3cd;color:#856404}
    .nipgl-sc-status.confirmed{background:#d1e7dd;color:#0a3622}
    .nipgl-sc-status.disputed{background:#f8d7da;color:#842029}
    .nipgl-admin-sc-wrap{display:none;background:#f6f7f7;border:1px solid #ddd;padding:16px;margin:8px 0}
    .nipgl-admin-sc-wrap.open{display:block}
    .nipgl-sc-compare{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px}
    .nipgl-sc-version{background:#fff;border:1px solid #ddd;padding:12px;border-radius:4px}
    .nipgl-sc-version h4{margin:0 0 8px;font-size:13px;color:#1a2e5a}
    .nipgl-sc-rink-row{font-size:12px;padding:3px 0;border-bottom:1px solid #eee}
    .nipgl-sc-totals{font-weight:600;margin-top:6px;font-size:13px}
    .nipgl-resolve-btns{margin-top:10px}
    .nipgl-resolve-btns .button{margin-right:8px}
    #nipgl-resolve-msg{padding:8px;margin-top:8px;background:#d1e7dd;color:#0a3622;display:none;border-radius:4px}
    /* Edit form */
    .nipgl-edit-form{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-top:12px}
    .nipgl-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
    .nipgl-edit-row{display:flex;flex-direction:column;gap:4px}
    .nipgl-edit-row label{font-weight:600;font-size:12px;color:#555}
    .nipgl-edit-rinks-table td{padding:6px 8px;vertical-align:middle}
    .nipgl-edit-rinks-table input.large-text{width:100%}
    .nipgl-edit-msg.success{color:#0a3622;background:#d1e7dd;padding:6px 10px;border-radius:3px}
    .nipgl-edit-msg.error{color:#842029;background:#f8d7da;padding:6px 10px;border-radius:3px}
    /* Audit log */
    .nipgl-audit-log{border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-top:4px}
    .nipgl-audit-entry{padding:10px 12px;border-bottom:1px solid #eee;font-size:12px}
    .nipgl-audit-entry:last-child{border-bottom:none}
    .nipgl-audit-header{display:flex;align-items:center;gap:8px;margin-bottom:4px}
    .nipgl-audit-icon{font-size:14px}
    .nipgl-audit-action{font-weight:700;padding:1px 6px;border-radius:3px;font-size:11px;text-transform:uppercase}
    .nipgl-audit-action-edited{background:#fff3cd;color:#856404}
    .nipgl-audit-action-confirmed{background:#d1e7dd;color:#0a3622}
    .nipgl-audit-action-resolved{background:#cfe2ff;color:#084298}
    .nipgl-audit-action-submitted{background:#f0f0f0;color:#333}
    .nipgl-audit-user{font-weight:600;color:#1a2e5a}
    .nipgl-audit-ts{color:#888;margin-left:auto}
    .nipgl-audit-note{color:#444;line-height:1.4}
    .nipgl-audit-changes{margin:4px 0 0 18px;padding:0;color:#666}
    .nipgl-audit-changes li{margin-bottom:2px}
    .nipgl-sc-amended{font-size:10px;background:#fff3cd;color:#856404;padding:1px 5px;border-radius:3px;margin-left:6px;vertical-align:middle}
    .nipgl-sc-div-warn{font-size:10px;background:#f8d7da;color:#842029;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;font-weight:600}
    </style>
    <script>
    function nipglResolve(postId, version, nonce){
        if(!confirm('Accept the '+version+' version as the official result?')) return;
        var data=new FormData();
        data.append('action','nipgl_admin_resolve');
        data.append('post_id',postId);
        data.append('version',version);
        data.append('nonce',nonce);
        fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(res){
                var msg=document.getElementById('nipgl-resolve-msg-'+postId);
                if(res.success){
                    msg.style.display='block';
                    msg.textContent=res.data.message||'Resolved.';
                    setTimeout(function(){location.reload();},1500);
                } else {
                    alert('Error: '+(res.data||'unknown'));
                }
            });
    }
    function nipglShowPanel(postId, panel) {
        // Toggle sub-panels within the sc wrap: 'view', 'edit', 'history'
        var wrap = document.getElementById('sc-'+postId);
        if (!wrap) return;
        var panels = wrap.querySelectorAll('.nipgl-sc-subpanel');
        var isOpen = wrap.classList.contains('open');
        var current = wrap.dataset.panel || '';
        if (isOpen && current === panel) {
            // clicking same panel again closes
            wrap.classList.remove('open');
            wrap.dataset.panel = '';
            return;
        }
        wrap.classList.add('open');
        wrap.dataset.panel = panel;
        panels.forEach(function(p){ p.style.display = p.dataset.panel === panel ? '' : 'none'; });
    }
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.nipgl-save-edit').forEach(function(btn){
            btn.addEventListener('click', function(){
                var postId  = btn.dataset.postid;
                var nonce   = btn.dataset.nonce;
                var wrap    = document.getElementById('sc-'+postId);
                var form    = wrap ? wrap.querySelector('.nipgl-edit-form') : null;
                var msgEl   = form  ? form.querySelector('.nipgl-edit-msg') : null;
                if (!form) return;
                var data = new FormData();
                data.append('action','nipgl_admin_edit_scorecard');
                data.append('post_id', postId);
                data.append('nonce', nonce);
                // Scalar fields
                ['home_team','away_team','match_date','venue','division','competition',
                 'home_total','away_total','home_points','away_points'].forEach(function(f){
                    var el = form.querySelector('[name="'+f+'"]');
                    if (el) data.append(f, el.value);
                });
                // Rink arrays
                form.querySelectorAll('[name="rink_num[]"]').forEach(function(el,i){
                    data.append('rink_num[]', el.value);
                });
                form.querySelectorAll('[name="rink_home_score[]"]').forEach(function(el){ data.append('rink_home_score[]', el.value); });
                form.querySelectorAll('[name="rink_away_score[]"]').forEach(function(el){ data.append('rink_away_score[]', el.value); });
                form.querySelectorAll('[name="rink_home_players[]"]').forEach(function(el){ data.append('rink_home_players[]', el.value); });
                form.querySelectorAll('[name="rink_away_players[]"]').forEach(function(el){ data.append('rink_away_players[]', el.value); });

                btn.disabled = true;
                btn.textContent = 'Saving…';
                fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
                    .then(function(r){
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.text().then(function(txt){
                            try { return JSON.parse(txt); }
                            catch(e) { throw new Error('Bad JSON: ' + txt.slice(0,300)); }
                        });
                    })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = '💾 Save Changes';
                        if (msgEl) {
                            msgEl.style.display = '';
                            msgEl.className = 'nipgl-edit-msg ' + (res.success ? 'success' : 'error');
                            msgEl.textContent = res.success ? (res.data.message||'Saved.') : ('Error: '+(res.data||'unknown'));
                            if (res.success) setTimeout(function(){ location.reload(); }, 1800);
                        }
                    })
                    .catch(function(err){
                        btn.disabled = false;
                        btn.textContent = '💾 Save Changes';
                        if (msgEl) {
                            msgEl.style.display = '';
                            msgEl.className = 'nipgl-edit-msg error';
                            msgEl.textContent = 'Request failed: ' + err.message;
                        } else {
                            alert('Request failed: ' + err.message);
                        }
                    });
            });
        });
    });
    </script>
    <?php if (empty($posts)): ?>
        <p>No scorecards submitted yet.</p>
    <?php else: ?>
    <table class="widefat striped">
    <thead><tr>
        <th>Match</th><th>Division</th>
        <th>Result (home v away)</th>
        <th>Status</th>
        <th>Submitted</th>
        <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($posts as $p):
        $sc       = get_post_meta($p->ID, 'nipgl_scorecard_data',  true);
        $status   = get_post_meta($p->ID, 'nipgl_sc_status',       true) ?: 'pending';
        $sub_by   = get_post_meta($p->ID, 'nipgl_submitted_by',    true);
        $con_by   = get_post_meta($p->ID, 'nipgl_confirmed_by',    true);
        $away_sc  = get_post_meta($p->ID, 'nipgl_away_scorecard',  true);
        $div_unresolved = get_post_meta($p->ID, 'nipgl_division_unresolved', true);
        $result   = ($sc && isset($sc['home_total']))
            ? $sc['home_total'].' ('.$sc['home_points'].'pts) – '.$sc['away_total'].' ('.$sc['away_points'].'pts)'
            : '—';
        $status_labels = array('pending'=>'Pending','confirmed'=>'Confirmed','disputed'=>'Disputed');
    ?>
    <tr>
        <td>
            <strong><?php echo esc_html($p->post_title); ?></strong>
            <?php if (get_post_meta($p->ID, 'nipgl_admin_edited', true)): ?>
                <span class="nipgl-sc-amended" title="Amended by admin">Amended</span>
            <?php endif; ?>
        </td>
        <td>
            <?php echo esc_html($sc['division'] ?? '—'); ?>
            <?php if ($div_unresolved): ?>
                <span class="nipgl-sc-div-warn" title="Division not matched to a sheet tab — sheet writeback will be skipped until corrected">⚠️ Unresolved</span>
            <?php endif; ?>
        </td>
        <td><?php echo esc_html($result); ?></td>
        <td><span class="nipgl-sc-status <?php echo esc_attr($status); ?>"><?php echo $status_labels[$status] ?? $status; ?></span></td>
        <td><?php echo get_the_date('d M Y H:i', $p); ?><br><small>by <?php echo esc_html($sub_by ?: '—'); ?></small></td>
        <td style="white-space:nowrap">
            <button class="button button-small" onclick="nipglShowPanel(<?php echo $p->ID; ?>,'view')">View</button>
            <button class="button button-small" onclick="nipglShowPanel(<?php echo $p->ID; ?>,'edit')">✏️ Edit</button>
            <button class="button button-small" onclick="nipglShowPanel(<?php echo $p->ID; ?>,'history')">📋 History</button>
            <a href="<?php echo get_delete_post_link($p->ID); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this scorecard?')">Delete</a>
        </td>
    </tr>
    <tr>
        <td colspan="6" style="padding:0">
        <div class="nipgl-admin-sc-wrap" id="sc-<?php echo $p->ID; ?>">

        <?php // ── View sub-panel ── ?>
        <div class="nipgl-sc-subpanel" data-panel="view">
        <?php if ($status === 'disputed' && $away_sc): ?>
            <p><strong>⚠️ Disputed result</strong> — <?php echo esc_html($sub_by); ?> submitted first, <?php echo esc_html($con_by); ?> submitted a different result. Choose which version to accept as official:</p>
            <div class="nipgl-sc-compare">
                <div class="nipgl-sc-version">
                    <h4>Version A — submitted by <?php echo esc_html($sub_by); ?></h4>
                    <?php nipgl_admin_render_sc_summary($sc); ?>
                </div>
                <div class="nipgl-sc-version">
                    <h4>Version B — submitted by <?php echo esc_html($con_by); ?></h4>
                    <?php nipgl_admin_render_sc_summary($away_sc); ?>
                </div>
            </div>
            <div class="nipgl-resolve-btns">
                <button class="button button-primary" onclick="nipglResolve(<?php echo $p->ID; ?>,'home','<?php echo $nonce; ?>')">✅ Accept Version A (<?php echo esc_html($sub_by); ?>)</button>
                <button class="button button-primary" onclick="nipglResolve(<?php echo $p->ID; ?>,'away','<?php echo $nonce; ?>')">✅ Accept Version B (<?php echo esc_html($con_by); ?>)</button>
            </div>
            <div id="nipgl-resolve-msg-<?php echo $p->ID; ?>" style="display:none;margin-top:8px;padding:8px;background:#d1e7dd;color:#0a3622;border-radius:4px"></div>
        <?php else: ?>
            <div class="nipgl-sc-version">
                <?php nipgl_admin_render_sc_summary($sc); ?>
                <?php if ($status === 'confirmed'): ?>
                    <p style="color:#0a3622;font-size:12px">✅ Confirmed by <?php echo esc_html($con_by); ?></p>
                <?php elseif ($status === 'pending'): ?>
                    <p style="color:#856404;font-size:12px">⏳ Awaiting confirmation from the other club</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>

        <?php // ── Edit sub-panel ── ?>
        <div class="nipgl-sc-subpanel" data-panel="edit" style="display:none">
            <?php nipgl_render_admin_edit_form($p->ID, $sc); ?>
        </div>

        <?php // ── History sub-panel ── ?>
        <div class="nipgl-sc-subpanel" data-panel="history" style="display:none">
            <h4 style="margin:0 0 10px;color:#1a2e5a">📋 Audit History</h4>
            <?php nipgl_render_audit_log($p->ID); ?>
            <h4 style="margin:14px 0 6px;color:#1a2e5a">☁️ Google Drive Log</h4>
            <?php nipgl_render_drive_log($p->ID); ?>
            <?php nipgl_render_sheets_log($p->ID); ?>
        </div>

        </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <?php endif; ?>
    </div>
    <?php
}

function nipgl_admin_render_sc_summary($sc) {
    if (!$sc) { echo '<p>No data.</p>'; return; }
    echo '<div class="nipgl-sc-rink-row"><strong>'.esc_html($sc['home_team'] ?? '').' v '.esc_html($sc['away_team'] ?? '').'</strong></div>';
    foreach (($sc['rinks'] ?? array()) as $rk) {
        echo '<div class="nipgl-sc-rink-row">Rink '.$rk['rink'].': '.intval($rk['home_score']).' – '.intval($rk['away_score']).'</div>';
    }
    echo '<div class="nipgl-sc-totals">Total: '.($sc['home_total'] ?? '?').' – '.($sc['away_total'] ?? '?')
        .'&nbsp;&nbsp;Points: '.($sc['home_points'] ?? '?').' – '.($sc['away_points'] ?? '?').'</div>';
}

add_action('admin_enqueue_scripts', 'nipgl_admin_enqueue');
function nipgl_admin_enqueue($hook) {
    $nipgl_hooks = array(
        'nipgl_page_nipgl-settings',        // Settings submenu
        'nipgl_page_nipgl-league-setup',    // League Setup submenu
        'nipgl_page_nipgl-cups',            // Cups submenu
        'nipgl_page_nipgl-champs',          // Championships submenu
        'toplevel_page_nipgl-scorecards',   // Top-level itself
    );
    if (!in_array($hook, $nipgl_hooks, true)) return;
    wp_enqueue_media();
    wp_enqueue_script('nipgl-admin', plugin_dir_url(__FILE__) . 'nipgl-admin.js', array('jquery'), NIPGL_VERSION, true);
    wp_enqueue_style('nipgl-admin', plugin_dir_url(__FILE__) . 'nipgl-admin.css', array(), NIPGL_VERSION);
}

add_action('admin_post_nipgl_save_settings', 'nipgl_save_settings');
function nipgl_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_settings_nonce');

    // Club badges
    $teams  = isset($_POST['nipgl_team'])       ? array_map('sanitize_text_field', $_POST['nipgl_team'])       : array();
    $images = isset($_POST['nipgl_image'])      ? array_map('esc_url_raw',         $_POST['nipgl_image'])      : array();
    $types  = isset($_POST['nipgl_badge_type']) ? array_map('sanitize_text_field', $_POST['nipgl_badge_type']) : array();

    $badges      = array();
    $club_badges = array();
    foreach ($teams as $i => $team) {
        $team = trim($team);
        if ($team !== '' && !empty($images[$i])) {
            $type = isset($types[$i]) ? $types[$i] : 'exact';
            if ($type === 'club') {
                $club_badges[$team] = $images[$i];
            } else {
                $badges[$team] = $images[$i];
            }
        }
    }
    update_option('nipgl_badges',      $badges);
    update_option('nipgl_club_badges', $club_badges);

    // Sponsors
    $sp_images = isset($_POST['nipgl_sp_image']) ? array_map('esc_url_raw',         $_POST['nipgl_sp_image']) : array();
    $sp_urls   = isset($_POST['nipgl_sp_url'])   ? array_map('esc_url_raw',         $_POST['nipgl_sp_url'])   : array();
    $sp_names  = isset($_POST['nipgl_sp_name'])  ? array_map('sanitize_text_field', $_POST['nipgl_sp_name'])  : array();
    $sponsors  = array();
    foreach ($sp_images as $i => $img) {
        if (!empty($img)) {
            $sponsors[] = array(
                'image' => $img,
                'url'   => !empty($sp_urls[$i])  ? $sp_urls[$i]  : '',
                'name'  => !empty($sp_names[$i]) ? $sp_names[$i] : '',
            );
        }
    }
    update_option('nipgl_sponsors', $sponsors);

    // Theme colours
    $theme = array(
        'color_primary'   => sanitize_hex_color($_POST['nipgl_color_primary']   ?? '') ?: '',
        'color_secondary' => sanitize_hex_color($_POST['nipgl_color_secondary'] ?? '') ?: '',
        'color_bg'        => sanitize_hex_color($_POST['nipgl_color_bg']        ?? '') ?: '',
    );
    update_option('nipgl_theme', $theme);

    // Clubs / passphrases
    $club_names     = isset($_POST['nipgl_club_name']) ? array_map('sanitize_text_field', $_POST['nipgl_club_name']) : array();
    $club_pins      = isset($_POST['nipgl_club_pin'])  ? $_POST['nipgl_club_pin']  : array();
    $existing_clubs = get_option('nipgl_clubs', array());
    $clubs = array();
    foreach ($club_names as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $pin_raw        = trim($club_pins[$i] ?? '');
        $pin_normalised = strtolower(preg_replace('/\s+/', ' ', $pin_raw));
        $existing_hash  = '';
        foreach ($existing_clubs as $ec) {
            if (strtolower($ec['name']) === strtolower($name)) { $existing_hash = $ec['pin']; break; }
        }
        $clubs[] = array(
            'name' => $name,
            'pin'  => $pin_normalised !== '' ? hash('sha256', $pin_normalised) : $existing_hash,
        );
    }
    update_option('nipgl_clubs', $clubs);

    wp_redirect(admin_url('admin.php?page=nipgl-settings&saved=1'));
    exit;
}

add_action('admin_post_nipgl_save_league_setup', 'nipgl_save_league_setup');
function nipgl_save_league_setup() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_league_setup_nonce');

    // Cache duration
    $cache_mins = isset($_POST['nipgl_cache_mins']) ? max(1, intval($_POST['nipgl_cache_mins'])) : 5;
    update_option('nipgl_cache_mins', $cache_mins);

    // Anthropic API key
    if (isset($_POST['nipgl_anthropic_key'])) {
        update_option('nipgl_anthropic_key', sanitize_text_field($_POST['nipgl_anthropic_key']));
    }

    // Drive + Sheets
    nipgl_drive_save_settings();
    nipgl_sheets_save_settings();

    wp_redirect(admin_url('admin.php?page=nipgl-league-setup&saved=1'));
    exit;
}

// ── Theme colour helpers ───────────────────────────────────────────────────────

function nipgl_hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return array(hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)));
}

function nipgl_rgb_to_hex($r, $g, $b) {
    return sprintf('#%02x%02x%02x', max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
}

// Lighten a hex colour by $amount (0-255 per channel)
function nipgl_theme_lighten($hex, $amount) {
    list($r,$g,$b) = nipgl_hex_to_rgb($hex);
    return nipgl_rgb_to_hex($r+$amount, $g+$amount, $b+$amount);
}

// Darken a hex colour by $pct percent (0-100)
function nipgl_theme_darken($hex, $pct) {
    list($r,$g,$b) = nipgl_hex_to_rgb($hex);
    $f = 1 - ($pct / 100);
    return nipgl_rgb_to_hex(intval($r*$f), intval($g*$f), intval($b*$f));
}

// Mix hex colour with white by $whitePct percent (higher = more white)
function nipgl_theme_mix($hex, $white, $whitePct) {
    list($r1,$g1,$b1) = nipgl_hex_to_rgb($hex);
    list($r2,$g2,$b2) = nipgl_hex_to_rgb($white);
    $t = $whitePct / 100;
    return nipgl_rgb_to_hex(intval($r1+(($r2-$r1)*$t)), intval($g1+(($g2-$g1)*$t)), intval($b1+(($b2-$b1)*$t)));
}

// Clear cache action
add_action('admin_post_nipgl_clear_cache', 'nipgl_clear_cache');
function nipgl_clear_cache() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_clear_cache_nonce');
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nipgl_csv_%' OR option_name LIKE '_transient_timeout_nipgl_csv_%'");
    wp_redirect(admin_url('admin.php?page=nipgl-settings&saved=1&cleared=1'));
    exit;
}

// Reset theme colours — must run on admin_init before any output
add_action('admin_init', 'nipgl_maybe_reset_theme');
function nipgl_maybe_reset_theme() {
    if (!isset($_GET['nipgl_reset_theme'])) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'nipgl-settings') return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('nipgl_reset_theme_nonce');
    update_option('nipgl_theme', array());
    wp_redirect(admin_url('admin.php?page=nipgl-settings&saved=1'));
    exit;
}

function nipgl_settings_page() {
    $badges = get_option('nipgl_badges', array());
    $saved  = isset($_GET['saved']);
    ?>
    <div class="wrap nipgl-admin-wrap">
        <h1>NIPGL Settings</h1>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_settings_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_save_settings">

            <h2>Clubs &amp; Passphrases</h2>
            <p>Add clubs and set a passphrase for each one. Used across all features — scorecards, cups, and championships.<br>
            <em>Tip: the <a href="https://what3words.com" target="_blank">what3words</a> address for your clubhouse makes a good passphrase (e.g. <code>filled.count.ripen</code>).</em><br>
            Leave the passphrase blank when editing to keep the existing one.</p>
            <table class="widefat nipgl-badge-table" id="nipgl-club-table">
                <thead><tr><th>Club Name</th><th>Passphrase (leave blank to keep existing)</th><th></th></tr></thead>
                <tbody>
                <?php
                $clubs = get_option('nipgl_clubs', array());
                if (empty($clubs)) $clubs = array(array('name'=>'','pin'=>''));
                foreach ($clubs as $club): ?>
                <tr class="nipgl-club-row">
                    <td><input type="text" name="nipgl_club_name[]" value="<?php echo esc_attr($club['name']); ?>" placeholder="e.g. Ards" class="regular-text"></td>
                    <td><input type="text" name="nipgl_club_pin[]" value="" placeholder="<?php echo $club['pin'] ? '(passphrase set — enter new to change)' : 'Set passphrase (word.word.word)'; ?>" autocomplete="off" autocapitalize="none" spellcheck="false" class="regular-text" style="width:240px"></td>
                    <td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="nipgl-add-club">+ Add Club</button></p>

            <hr>
            <h2>Theme Colours</h2>
            <p>Default colours for all widgets on this site. Can be overridden per-widget using shortcode attributes: <code>color_primary</code>, <code>color_secondary</code>, <code>color_bg</code>.</p>
            <?php $theme = get_option('nipgl_theme', array()); ?>
            <table class="form-table">
                <tr>
                    <th>Primary Colour <span style="font-weight:400;color:#666">(tabs, headers, accents)</span></th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_primary'] ?? '#1a2e5a'); ?>">
                        <input type="text" name="nipgl_color_primary" value="<?php echo esc_attr($theme['color_primary'] ?? '#1a2e5a'); ?>" class="small-text nipgl-hex-input" maxlength="7" placeholder="#1a2e5a">
                        <?php if (empty($theme['color_primary'])): ?><em style="color:#999;font-size:12px">Using default navy</em><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Secondary Colour <span style="font-weight:400;color:#666">(highlight / gold)</span></th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_secondary'] ?? '#e8b400'); ?>">
                        <input type="text" name="nipgl_color_secondary" value="<?php echo esc_attr($theme['color_secondary'] ?? '#e8b400'); ?>" class="small-text nipgl-hex-input" maxlength="7" placeholder="#e8b400">
                        <?php if (empty($theme['color_secondary'])): ?><em style="color:#999;font-size:12px">Using default gold</em><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Background Colour</th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_bg'] ?? '#ffffff'); ?>">
                        <input type="text" name="nipgl_color_bg" value="<?php echo esc_attr($theme['color_bg'] ?? '#ffffff'); ?>" class="small-text nipgl-hex-input" maxlength="7" placeholder="#ffffff">
                        <?php if (empty($theme['color_bg'])): ?><em style="color:#999;font-size:12px">Using default white</em><?php endif; ?>
                    </td>
                </tr>
            </table>
            <p style="margin-top:0"><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=nipgl-settings&nipgl_reset_theme=1'), 'nipgl_reset_theme_nonce'); ?>" class="button" onclick="return confirm('Reset theme colours to defaults?')">Reset to defaults</a></p>

            <hr>
            <h2>Club Badges</h2>
            <p>Assign badge images to clubs. Used across league tables, cup brackets, and championship draws.<br>
            Set <strong>Type</strong> to <strong>Club prefix</strong> for clubs with multiple teams — e.g. enter <code>MALONE</code> to match <code>MALONE A</code>, <code>MALONE B</code>, etc. Use <strong>Exact</strong> for a team-specific badge. Exact matches take priority.</p>
            <table class="widefat nipgl-badge-table" id="nipgl-badge-table">
                <thead>
                    <tr><th>Club / Team Name</th><th>Type</th><th>Badge Image</th><th>Preview</th><th></th></tr>
                </thead>
                <tbody>
                    <?php
                    $club_badges    = get_option('nipgl_club_badges', array());
                    $all_badge_rows = array();
                    foreach ($badges as $team => $img) {
                        $all_badge_rows[] = array('name' => $team, 'image' => $img, 'type' => 'exact');
                    }
                    foreach ($club_badges as $team => $img) {
                        $all_badge_rows[] = array('name' => $team, 'image' => $img, 'type' => 'club');
                    }
                    if (empty($all_badge_rows)) {
                        $all_badge_rows = array(array('name' => '', 'image' => '', 'type' => 'club'));
                    }
                    foreach ($all_badge_rows as $row): ?>
                    <tr class="nipgl-badge-row">
                        <td><input type="text" name="nipgl_team[]" value="<?php echo esc_attr($row['name']); ?>" placeholder="e.g. MALONE" class="regular-text"></td>
                        <td>
                            <select name="nipgl_badge_type[]" class="nipgl-badge-type">
                                <option value="club"  <?php selected($row['type'], 'club');  ?>>Club prefix</option>
                                <option value="exact" <?php selected($row['type'], 'exact'); ?>>Exact</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="nipgl_image[]" value="<?php echo esc_url($row['image']); ?>" placeholder="Image URL" class="regular-text nipgl-image-url" readonly>
                            <button type="button" class="button nipgl-pick-image">Choose Image</button>
                        </td>
                        <td><img class="nipgl-badge-preview" src="<?php echo esc_url($row['image']); ?>" style="width:48px;height:48px;object-fit:contain;<?php echo $row['image'] ? '' : 'display:none;'; ?>"></td>
                        <td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="nipgl-add-row">+ Add Badge</button></p>

            <hr>
            <h2>Sponsors</h2>
            <p>The <strong>first sponsor</strong> appears above the division title. Additional sponsors rotate randomly below the league table. Add a per-division override via the shortcode if needed.</p>
            <table class="widefat nipgl-badge-table" id="nipgl-sponsor-table">
                <thead>
                    <tr><th>Sponsor Name / Alt Text</th><th>Logo Image</th><th>Link URL</th><th>Preview</th><th></th></tr>
                </thead>
                <tbody>
                    <?php
                    $sponsors = get_option('nipgl_sponsors', array());
                    if (empty($sponsors)) $sponsors = array(array('image'=>'','url'=>'','name'=>''));
                    foreach ($sponsors as $sp): ?>
                    <tr class="nipgl-sponsor-row">
                        <td><input type="text" name="nipgl_sp_name[]" value="<?php echo esc_attr($sp['name']); ?>" placeholder="e.g. Acme Ltd" class="regular-text"></td>
                        <td>
                            <input type="text" name="nipgl_sp_image[]" value="<?php echo esc_url($sp['image']); ?>" placeholder="Image URL" class="regular-text nipgl-image-url" readonly>
                            <button type="button" class="button nipgl-pick-image">Choose Image</button>
                        </td>
                        <td><input type="text" name="nipgl_sp_url[]" value="<?php echo esc_url($sp['url']); ?>" placeholder="https://" class="regular-text"></td>
                        <td><img class="nipgl-badge-preview" src="<?php echo esc_url($sp['image']); ?>" style="<?php echo $sp['image'] ? '' : 'display:none;'; ?>height:40px;object-fit:contain;max-width:120px;"></td>
                        <td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="nipgl-add-sponsor">+ Add Sponsor</button></p>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <h2>Plugin Updates</h2>
        <p>Current version: <strong><?php echo NIPGL_VERSION; ?></strong>. Click below to force WordPress to check GitHub for a newer release immediately.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_check_updates_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_check_updates">
            <p><input type="submit" class="button button-secondary" value="Check for Updates Now"></p>
        </form>

        <hr>
        <h2>Clear Cache Now</h2>
        <p>Force all divisions to fetch fresh data from Google Sheets on the next page load.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_clear_cache_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_clear_cache">
            <p><input type="submit" class="button button-secondary" value="Clear Cache Now"></p>
        </form>
    </div>
    <script>
    document.querySelectorAll('input[type="color"]').forEach(function(picker) {
        var row = picker.parentNode;
        var hex = row.querySelector('.nipgl-hex-input');
        if (!hex) return;
        picker.addEventListener('input', function() { hex.value = picker.value; });
        hex.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
        });
    });
    </script>
    <?php
}

// ── League Setup page ─────────────────────────────────────────────────────────
function nipgl_league_setup_page() {
    $saved = isset($_GET['saved']);
    ?>
    <div class="wrap nipgl-admin-wrap">
        <h1>League Setup</h1>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['cleared'])): ?>
            <div class="notice notice-success is-dismissible"><p>Cache cleared — all divisions will fetch fresh data on next load.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_league_setup_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_save_league_setup">

            <h2>Cache Settings</h2>
            <p>Data is cached on your server to speed up page loads. Visitors see cached data until it expires, then a fresh fetch is made from Google Sheets.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nipgl_cache_mins">Cache duration (minutes)</label></th>
                    <td>
                        <input type="number" id="nipgl_cache_mins" name="nipgl_cache_mins" value="<?php echo intval(get_option('nipgl_cache_mins', 5)); ?>" min="1" max="60" style="width:80px">
                        <p class="description">5 minutes is a good default during the season. Lower = fresher data but more Google Sheets requests.</p>
                    </td>
                </tr>
            </table>

            <hr>
            <h2>Anthropic API Key</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nipgl_anthropic_key">API Key</label></th>
                    <td>
                        <?php $key = get_option('nipgl_anthropic_key', ''); ?>
                        <input type="password" id="nipgl_anthropic_key" name="nipgl_anthropic_key" value="<?php echo esc_attr($key); ?>" placeholder="sk-ant-…" style="width:380px">
                        <p class="description">Required for AI photo reading of scorecards. Get a key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.</p>
                    </td>
                </tr>
            </table>

            <?php nipgl_drive_settings_section(); ?>
            <?php nipgl_sheets_settings_html(); ?>

            <?php submit_button('Save League Setup'); ?>
        </form>

        <hr>
        <h2>Shortcode Reference</h2>

        <h3 style="margin-bottom:4px">League table &amp; fixtures</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[nipgl_division csv="YOUR_CSV_URL" title="Division 1"]</pre>
        <table class="widefat striped" style="max-width:680px;margin-top:8px">
            <thead><tr><th style="width:160px">Parameter</th><th>Description</th><th style="width:80px">Required</th></tr></thead>
            <tbody>
            <tr><td><code>csv</code></td><td>Published Google Sheets CSV URL</td><td>Yes</td></tr>
            <tr><td><code>title</code></td><td>Heading above the widget</td><td>No</td></tr>
            <tr><td><code>promote</code></td><td>Promotion places to highlight (default: 0)</td><td>No</td></tr>
            <tr><td><code>relegate</code></td><td>Relegation places to highlight (default: 0)</td><td>No</td></tr>
            <tr><td><code>color_primary</code></td><td>Override primary colour for this widget</td><td>No</td></tr>
            <tr><td><code>color_secondary</code></td><td>Override secondary colour for this widget</td><td>No</td></tr>
            <tr><td><code>color_bg</code></td><td>Override background colour for this widget</td><td>No</td></tr>
            <tr><td><code>sponsor_img</code></td><td>Override primary sponsor image for this division</td><td>No</td></tr>
            <tr><td><code>sponsor_url</code></td><td>Override primary sponsor link for this division</td><td>No</td></tr>
            <tr><td><code>sponsor_name</code></td><td>Override primary sponsor alt text for this division</td><td>No</td></tr>
            </tbody>
        </table>

        <h3 style="margin-top:20px;margin-bottom:4px">Scorecard submission form</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[nipgl_submit]</pre>
        <p style="margin-top:6px;color:#555;font-size:13px">Allows clubs to submit and confirm match scorecards using their passphrase.</p>

        <h3 style="margin-top:20px;margin-bottom:4px">Cup bracket</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[nipgl_cup id="cup-2025"]</pre>

        <h3 style="margin-top:20px;margin-bottom:4px">National championship</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[nipgl_champ id="singles-2025"]</pre>
    </div>
    <?php
}

// ── Import Passphrases Tool ───────────────────────────────────────────────────
add_action('admin_menu', 'nipgl_import_passphrases_menu');
function nipgl_import_passphrases_menu() {
    // Only show if the tool hasn't been disabled
    if (get_option('nipgl_import_tool_disabled')) return;
    add_submenu_page(
        'nipgl-scorecards',
        'Import Passphrases',
        '🔑 Import Passphrases',
        'manage_options',
        'nipgl-import-passphrases',
        'nipgl_import_passphrases_page'
    );
}

function nipgl_import_col_to_idx($col) {
    $idx = 0;
    foreach (str_split($col) as $c) {
        $idx = $idx * 26 + (ord($c) - ord('A') + 1);
    }
    return $idx - 1;
}

function nipgl_read_passphrases_from_xlsx($file_path) {
    if (!class_exists('ZipArchive')) return new WP_Error('no_zip', 'ZipArchive not available — install php-zip.');
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) return new WP_Error('bad_zip', 'Could not open xlsx file.');

    $strings = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m);
        $strings = array_map('html_entity_decode', $m[1]);
    }
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet_xml) return new WP_Error('no_sheet', 'Could not read sheet data from xlsx.');

    $grid  = array();
    $cells = explode('</c>', $sheet_xml);
    foreach ($cells as $cell_str) {
        if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cell_str, $ref)) continue;
        $col     = nipgl_import_col_to_idx($ref[1]);
        $row_num = intval($ref[2]);
        $type    = '';
        if (preg_match('/\bt="([^"]*)"/', $cell_str, $tm)) $type = $tm[1];
        if (!preg_match('/<v>(.*?)<\/v>/s', $cell_str, $vm)) continue;
        $val = $vm[1];
        $grid[$row_num][$col] = ($type === 's') ? ($strings[intval($val)] ?? '') : $val;
    }

    // Col 0 = Club Name, Col 3 = Passphrase. Skip rows 1+2 (header + instructions).
    $pairs = array();
    ksort($grid);
    foreach ($grid as $row_num => $row) {
        if ($row_num <= 2) continue;
        $name   = isset($row[0]) ? trim($row[0]) : '';
        $phrase = isset($row[3]) ? trim($row[3]) : '';
        if ($name === '') continue;
        $pairs[$name] = $phrase;
    }
    return $pairs;
}

function nipgl_import_passphrases_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorised');

    // Handle disable tool
    if (isset($_POST['nipgl_disable_tool']) && check_admin_referer('nipgl_import_tool_nonce')) {
        update_option('nipgl_import_tool_disabled', 1);
        echo '<div class="wrap"><div class="notice notice-success"><p>Import tool removed from menu. You can re-enable it by deleting the <code>nipgl_import_tool_disabled</code> option from the database.</p></div></div>';
        return;
    }

    $result = null;
    $errors = array();

    // Handle upload + import
    if (isset($_POST['nipgl_do_import']) && check_admin_referer('nipgl_import_tool_nonce')) {
        if (empty($_FILES['nipgl_xlsx']['tmp_name'])) {
            $errors[] = 'No file uploaded.';
        } else {
            $pairs = nipgl_read_passphrases_from_xlsx($_FILES['nipgl_xlsx']['tmp_name']);
            if (is_wp_error($pairs)) {
                $errors[] = $pairs->get_error_message();
            } else {
                $existing = get_option('nipgl_clubs', array());
                $indexed  = array();
                foreach ($existing as $club) {
                    $indexed[strtolower($club['name'])] = $club;
                }
                $updated = $inserted = $skipped = 0;
                foreach ($pairs as $name => $phrase) {
                    $key = strtolower($name);
                    if ($phrase === '') {
                        if (!isset($indexed[$key])) { $indexed[$key] = array('name' => $name, 'pin' => ''); $inserted++; }
                        else $skipped++;
                        continue;
                    }
                    $normalised = strtolower(preg_replace('/\s+/', ' ', $phrase));
                    $hash = hash('sha256', $normalised);
                    if (isset($indexed[$key])) { $indexed[$key]['pin'] = $hash; $updated++; }
                    else { $indexed[$key] = array('name' => $name, 'pin' => $hash); $inserted++; }
                }
                update_option('nipgl_clubs', array_values($indexed));
                $result = array('updated' => $updated, 'inserted' => $inserted, 'skipped' => $skipped, 'pairs' => $pairs);
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>🔑 Import Club Passphrases</h1>
        <p>Upload the <code>nipgl-club-passphrases.xlsx</code> file with the Passphrase column filled in. The tool will upsert all clubs — updating existing ones and adding any new ones.</p>

        <?php if (!empty($errors)): ?>
            <div class="notice notice-error"><p><?php echo implode('<br>', array_map('esc_html', $errors)); ?></p></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="notice notice-success">
                <p>✅ <strong>Import complete.</strong> Passphrases set: <strong><?php echo $result['updated']; ?></strong> &nbsp;|&nbsp; New clubs added: <strong><?php echo $result['inserted']; ?></strong> &nbsp;|&nbsp; Skipped (no passphrase): <strong><?php echo $result['skipped']; ?></strong></p>
            </div>
            <?php if (!empty($result['pairs'])): ?>
            <h3>Imported rows</h3>
            <table class="widefat striped" style="max-width:500px">
                <thead><tr><th>Club</th><th>Passphrase</th></tr></thead>
                <tbody>
                <?php foreach ($result['pairs'] as $name => $phrase): ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo $phrase ? esc_html($phrase) : '<em style="color:#999">— none —</em>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px">
            <?php wp_nonce_field('nipgl_import_tool_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="nipgl_xlsx">Passphrases xlsx</label></th>
                    <td>
                        <input type="file" name="nipgl_xlsx" id="nipgl_xlsx" accept=".xlsx">
                        <p class="description">Upload <code>nipgl-club-passphrases.xlsx</code> with the Passphrase column (column D) filled in.</p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="nipgl_do_import" class="button button-primary" value="Import Passphrases"></p>
        </form>

        <hr style="margin-top:40px">
        <h3>Remove this tool</h3>
        <p>Once you've finished importing, remove this page from the menu to keep the admin tidy.</p>
        <form method="post">
            <?php wp_nonce_field('nipgl_import_tool_nonce'); ?>
            <p><input type="submit" name="nipgl_disable_tool" class="button button-secondary" value="Remove Import Tool from Menu"
               onclick="return confirm('Remove the import tool from the admin menu?')"></p>
        </form>
    </div>
    <?php
}
