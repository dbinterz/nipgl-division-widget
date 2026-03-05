<?php
/**
 * Plugin Name: NIPGL Division Widget
 * Description: Renders mobile-friendly league table and fixtures from Google Sheets CSV. Use shortcode [nipgl_division csv="URL" title="Division 1"] on any page.
 * Version: 5.2
 * Author: NIPGL
 * GitHub Plugin URI: https://github.com/dbinterz/nipgl-division-widget
 * Primary Branch: main
 */

define('NIPGL_VERSION', '5.2');

// Include scorecard feature
require_once plugin_dir_path(__FILE__) . 'nipgl-scorecards.php';

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
            'description' => 'Mobile-friendly league table and fixtures widget for NIPGL, powered by Google Sheets.',
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
    wp_redirect(admin_url('options-general.php?page=nipgl-settings&updated=1'));
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

    $response = wp_remote_get($url, array('timeout' => 10, 'user-agent' => 'Mozilla/5.0'));
    if (is_wp_error($response)) {
        wp_die('Fetch failed: ' . $response->get_error_message(), '', array('response' => 502));
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) { wp_die('Upstream error: ' . $code, '', array('response' => 502)); }

    $body = wp_remote_retrieve_body($response);

    // Cache for configured duration (default 5 minutes)
    $cache_mins = intval(get_option('nipgl_cache_mins', 5));
    set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);

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
    wp_enqueue_script('nipgl-widget', plugin_dir_url(__FILE__) . 'nipgl-widget.js', array(), NIPGL_VERSION, true);

    $badges       = get_option('nipgl_badges',       array());
    $club_badges  = get_option('nipgl_club_badges',  array());
    $sponsors     = get_option('nipgl_sponsors',     array());
    wp_localize_script('nipgl-widget', 'nipglData', array(
        'ajaxUrl'     => admin_url('admin-ajax.php'),
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
        'sponsor_img'  => '',  // override: image URL for primary sponsor
        'sponsor_url'  => '',  // override: link URL for primary sponsor
        'sponsor_name' => '',  // override: alt text for primary sponsor
    ), $atts);

    if (!$atts['csv']) return '<p>No CSV URL provided.</p>';

    $id          = 'nipgl-' . substr(md5($atts['csv']), 0, 8);
    $csv_escaped = esc_attr($atts['csv']);

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

    return $primary_html
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
        . '</div>';
}

// ── 4. Admin Settings Page ────────────────────────────────────────────────────
add_action('admin_menu', 'nipgl_admin_menu');
function nipgl_admin_menu() {
    add_options_page(
        'NIPGL Widget Settings',
        'NIPGL Widget',
        'manage_options',
        'nipgl-settings',
        'nipgl_settings_page'
    );
    // Scorecards viewer in admin
    add_menu_page(
        'NIPGL Scorecards',
        'Scorecards',
        'manage_options',
        'nipgl-scorecards',
        'nipgl_scorecards_admin_page',
        'dashicons-clipboard',
        30
    );
}

function nipgl_scorecards_admin_page() {
    $posts = get_posts(array('post_type'=>'nipgl_scorecard','posts_per_page'=>50,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'));
    echo '<div class="wrap"><h1>Submitted Scorecards</h1>';
    if (empty($posts)) { echo '<p>No scorecards submitted yet.</p></div>'; return; }
    echo '<table class="widefat"><thead><tr><th>Match</th><th>Division</th><th>Date</th><th>Result</th><th>Submitted</th><th></th></tr></thead><tbody>';
    foreach ($posts as $p) {
        $sc = get_post_meta($p->ID, 'nipgl_scorecard_data', true);
        $result = ($sc && $sc['home_points'] !== null)
            ? esc_html($sc['home_team']).' '.$sc['home_total'].' ('.$sc['home_points'].'pts) v '.$sc['away_total'].' ('.$sc['away_points'].'pts) '.esc_html($sc['away_team'])
            : '—';
        echo '<tr>';
        echo '<td>'.esc_html($p->post_title).'</td>';
        echo '<td>'.esc_html($sc['division'] ?? '—').'</td>';
        echo '<td>'.esc_html($sc['date'] ?? '—').'</td>';
        echo '<td>'.$result.'</td>';
        echo '<td>'.get_the_date('d M Y H:i', $p).'</td>';
        echo '<td><a href="'.get_delete_post_link($p->ID).'" class="button-link-delete" onclick="return confirm(\'Delete this scorecard?\')">Delete</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

add_action('admin_enqueue_scripts', 'nipgl_admin_enqueue');
function nipgl_admin_enqueue($hook) {
    if ($hook !== 'settings_page_nipgl-settings') return;
    wp_enqueue_media();
    wp_enqueue_script('nipgl-admin', plugin_dir_url(__FILE__) . 'nipgl-admin.js', array('jquery'), NIPGL_VERSION, true);
    wp_enqueue_style('nipgl-admin', plugin_dir_url(__FILE__) . 'nipgl-admin.css', array(), NIPGL_VERSION);
}

add_action('admin_post_nipgl_save_settings', 'nipgl_save_settings');
function nipgl_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_settings_nonce');

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

    // Save sponsors
    $sp_images = isset($_POST['nipgl_sp_image']) ? array_map('esc_url_raw',        $_POST['nipgl_sp_image']) : array();
    $sp_urls   = isset($_POST['nipgl_sp_url'])   ? array_map('esc_url_raw',        $_POST['nipgl_sp_url'])   : array();
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

    $cache_mins = isset($_POST['nipgl_cache_mins']) ? max(1, intval($_POST['nipgl_cache_mins'])) : 5;
    update_option('nipgl_cache_mins', $cache_mins);

    // API key and score entry PIN
    if (isset($_POST['nipgl_anthropic_key'])) {
        update_option('nipgl_anthropic_key', sanitize_text_field($_POST['nipgl_anthropic_key']));
    }
    if (!empty($_POST['nipgl_submit_pin'])) {
        $pin = sanitize_text_field($_POST['nipgl_submit_pin']);
        update_option('nipgl_submit_pin', hash('sha256', $pin));
    }

    wp_redirect(admin_url('options-general.php?page=nipgl-settings&saved=1'));
    exit;
}

// Clear cache action
add_action('admin_post_nipgl_clear_cache', 'nipgl_clear_cache');
function nipgl_clear_cache() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_clear_cache_nonce');
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nipgl_csv_%' OR option_name LIKE '_transient_timeout_nipgl_csv_%'");
    wp_redirect(admin_url('options-general.php?page=nipgl-settings&saved=1&cleared=1'));
    exit;
}

function nipgl_settings_page() {
    $badges = get_option('nipgl_badges', array());
    $saved  = isset($_GET['saved']);
    ?>
    <div class="wrap nipgl-admin-wrap">
        <h1>NIPGL Widget Settings</h1>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['cleared'])): ?>
            <div class="notice notice-success is-dismissible"><p>Cache cleared — all divisions will fetch fresh data on next load.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>Update check complete — WordPress will now show any available updates on the <a href="<?php echo admin_url('update-core.php'); ?>">Updates page</a>.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_settings_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_save_settings">

            <h2>Sponsors</h2>
            <p>The <strong>first sponsor</strong> appears above the division title on all pages. Additional sponsors rotate randomly below the league table. Add a per-division override via the shortcode if needed.</p>
            <table class="widefat nipgl-badge-table" id="nipgl-sponsor-table">
                <thead>
                    <tr>
                        <th>Sponsor Name / Alt Text</th>
                        <th>Logo Image</th>
                        <th>Link URL</th>
                        <th>Preview</th>
                        <th></th>
                    </tr>
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

            <hr>
            <h2>Club Badges</h2>
            <p>Enter a name and choose a badge. Set <strong>Type</strong> to <strong>Club prefix</strong> for clubs with multiple teams — e.g. enter <code>MALONE</code> once and it will match <code>MALONE A</code>, <code>MALONE B</code>, <code>MALONE C</code> etc. Use <strong>Exact</strong> when a team needs its own specific badge. Exact matches always take priority over club prefix matches.</p>

            <table class="widefat nipgl-badge-table" id="nipgl-badge-table">
                <thead>
                    <tr>
                        <th>Club / Team Name</th>
                        <th>Type</th>
                        <th>Badge Image</th>
                        <th>Preview</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $club_badges = get_option('nipgl_club_badges', array());
                    // Merge for display: exact badges first, then club badges
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
            <h2>Shortcode Usage</h2>
            <p>Use this shortcode in a <strong>Shortcode block</strong> on any division page:</p>
            <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block">[nipgl_division csv="YOUR_CSV_URL" title="Division 1"]</pre>
            <ul style="margin-top:10px;list-style:disc;padding-left:20px;line-height:2">
                <li><code>csv</code> — published Google Sheets CSV URL <em>(required)</em></li>
                <li><code>title</code> — heading displayed above the widget <em>(optional)</em></li>
                <li><code>promote</code> — number of promotion places <em>(optional)</em></li>
                <li><code>relegate</code> — number of relegation places <em>(optional)</em></li>
                <li><code>sponsor_img</code> — override primary sponsor image for this division <em>(optional)</em></li>
                <li><code>sponsor_url</code> — override primary sponsor link for this division <em>(optional)</em></li>
                <li><code>sponsor_name</code> — override primary sponsor alt text for this division <em>(optional)</em></li>
            </ul>

            <hr>
            <h2>Score Entry</h2>
            <p>Configure score submission via the <code>[nipgl_submit]</code> shortcode. Add this shortcode to any page to create the score entry form.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nipgl_submit_pin">Score Entry PIN</label></th>
                    <td>
                        <input type="password" id="nipgl_submit_pin" name="nipgl_submit_pin" value="" placeholder="Enter new PIN to change" autocomplete="new-password" style="width:200px">
                        <p class="description">Leave blank to keep the current PIN. Share this PIN with club secretaries who will submit scorecards.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nipgl_anthropic_key">Anthropic API Key</label></th>
                    <td>
                        <?php $key = get_option('nipgl_anthropic_key',''); ?>
                        <input type="password" id="nipgl_anthropic_key" name="nipgl_anthropic_key" value="<?php echo esc_attr($key); ?>" placeholder="sk-ant-…" style="width:380px">
                        <p class="description">Required for AI photo reading. Get a key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>. The key is stored securely in your WordPress database.</p>
                    </td>
                </tr>
            </table>

            <hr>
            <h2>Cache Settings</h2>
            <p>Data is cached on your server to speed up page loads. Visitors see cached data until it expires, then a fresh fetch is made from Google Sheets.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nipgl_cache_mins">Cache duration (minutes)</label></th>
                    <td>
                        <input type="number" id="nipgl_cache_mins" name="nipgl_cache_mins" value="<?php echo intval(get_option('nipgl_cache_mins', 5)); ?>" min="1" max="60" style="width:80px">
                        <p class="description">How long to cache each division's data. 5 minutes is a good default during the season. Lower = fresher data but more Google requests.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <h2>Plugin Updates</h2>
        <p>Current version: <strong><?php echo NIPGL_VERSION; ?></strong>. Click below to force WordPress to check GitHub for a newer release immediately, rather than waiting up to 6 hours.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_check_updates_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_check_updates">
            <p><input type="submit" class="button button-secondary" value="Check for Updates Now"></p>
        </form>

        <hr>
        <h2>Clear Cache Now</h2>
        <p>Force all divisions to fetch fresh data from Google Sheets on the next page load — useful immediately after entering results.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('nipgl_clear_cache_nonce'); ?>
            <input type="hidden" name="action" value="nipgl_clear_cache">
            <p><input type="submit" class="button button-secondary" value="Clear Cache Now"></p>
        </form>
    </div>
    <?php
}
