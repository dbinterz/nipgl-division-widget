<?php
/**
 * LGW Group Stage into Knockout Championships
 *
 * A parallel championship system to lgw-champ.php that supports a round-robin
 * group stage feeding into a single-elimination knockout bracket.
 *
 * Storage : WP option  lgw_gchamp_{id}
 * Shortcode: [lgw_gchamp id="..."]
 *
 * Build order (spec §Appendix B):
 *   Step 1 (v7.2.0) — Data model + admin config panel
 *   Step 2 (v7.2.1) — Date preference field + admin override
 *   Step 3 (v7.2.2) — Draw algorithm + group fixture generation
 *   Step 4 (v7.2.3) — Front-end group cards (static)
 *   Step 5 (v7.2.4) — Group score entry + standings recalculation
 *   Step 6 (v7.2.5) — Qualification logic + knockout seeding
 *   Revised (v7.2.7)  — Days-as-sections data model                   ← THIS FILE
 *   Step 7 (v7.2.6) — Player appearance logging for group games
 *   Step 8 (v7.2.7) — Live standings polling + qualification indicators
 *   Step 9 (v7.2.8) — Full integration test + documentation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu registration ────────────────────────────────────────────────────
// Called from lgw_admin_menu() in lgw-division-widget.php — no direct hook here.
function lgw_gchamps_register_submenu() {
    // Group championships share the Championships admin page (lgw-champs).
    // They appear as a separate submenu only for create/edit; the list is merged.
    // Nothing to register as a separate page — the existing lgw-champs page
    // is extended to show both types (see lgw_gchamp_extend_champs_list_page()).
}

// ── Admin: handle saves, resets, deletes ──────────────────────────────────────
add_action( 'admin_init', 'lgw_gchamp_handle_admin_actions' );
function lgw_gchamp_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // ── Save ──
    if ( isset( $_POST['lgw_gchamp_save_nonce'] )
        && wp_verify_nonce( $_POST['lgw_gchamp_save_nonce'], 'lgw_gchamp_save' ) ) {

        $champ_id = sanitize_key( $_POST['gchamp_id'] ?? '' );
        if ( ! $champ_id ) {
            wp_redirect( admin_url( 'admin.php?page=lgw-champs&gchamp_error=missing_id' ) );
            exit;
        }

        $existing = get_option( 'lgw_gchamp_' . $champ_id, array() );

        $entries_raw = sanitize_textarea_field( wp_unslash( $_POST['lgw_gchamp_entries'] ?? '' ) );
        $entries     = array_values( array_filter( array_map( 'trim', explode( "\n", $entries_raw ) ) ) );

        $dates_raw = sanitize_textarea_field( $_POST['lgw_gchamp_dates'] ?? '' );
        $dates     = array_values( array_filter( array_map( 'trim', explode( "\n", $dates_raw ) ) ) );

        $has_ko_bracket  = isset( $_POST['lgw_gchamp_has_ko'] ) ? 1 : 0;
        $ko_bracket_size = intval( $_POST['lgw_gchamp_ko_bracket_size'] ?? 0 );

        // ── Days config ───────────────────────────────────────────────────────
        $days_config    = array();
        $num_days       = max( 1, intval( $_POST['lgw_gchamp_num_days'] ?? 1 ) );
        $day_names_post = $_POST['lgw_gchamp_day_names'] ?? array();
        $day_dates_post = $_POST['lgw_gchamp_day_dates'] ?? array();
        $day_tgs_post   = $_POST['lgw_gchamp_day_tgs']   ?? array();
        $day_wpg_post   = $_POST['lgw_gchamp_day_wpg']   ?? array();
        $day_bru_post   = $_POST['lgw_gchamp_day_bru']   ?? array();

        $day_kobs_post = $_POST['lgw_gchamp_day_kobs'] ?? array();
        for ( $i = 0; $i < $num_days; $i++ ) {
            $days_config[] = array(
                'id'                => $i,
                'name'              => sanitize_text_field( wp_unslash( $day_names_post[$i] ?? ( 'Day ' . ( $i + 1 ) ) ) ),
                'date'              => sanitize_text_field( wp_unslash( $day_dates_post[$i] ?? '' ) ),
                'target_group_size' => max( 2, intval( $day_tgs_post[$i]  ?? 4 ) ),
                'winners_per_group' => max( 1, intval( $day_wpg_post[$i]  ?? 1 ) ),
                'best_runners_up'   => max( 0, intval( $day_bru_post[$i]  ?? 0 ) ),
                'ko_bracket_size'   => max( 0, intval( $day_kobs_post[$i] ?? 0 ) ),
            );
        }

        // ── Entry date preferences ────────────────────────────────────────────
        $pref_raw          = $_POST['lgw_gchamp_entry_prefs'] ?? array();
        $entry_preferences = array();
        foreach ( $entries as $entry ) {
            $pref = sanitize_text_field( wp_unslash( $pref_raw[ md5( $entry ) ] ?? '' ) );
            if ( $pref !== '' ) $entry_preferences[ $entry ] = $pref;
        }
        foreach ( ( $existing['entry_preferences'] ?? array() ) as $e => $p ) {
            if ( in_array( $e, $entries, true ) && ! isset( $entry_preferences[ $e ] ) ) {
                $entry_preferences[ $e ] = $p;
            }
        }

        // ── Preserve draw state ───────────────────────────────────────────────
        $champ_data = array(
            'id'                   => $champ_id,
            'format'               => 'group_knockout',
            'title'                => sanitize_text_field( wp_unslash( $_POST['lgw_gchamp_title']      ?? '' ) ),
            'discipline'           => sanitize_text_field( $_POST['lgw_gchamp_discipline']             ?? 'singles' ),
            'season'               => sanitize_text_field( wp_unslash( $_POST['lgw_gchamp_season']     ?? '' ) ),
            'entries'              => $entries,
            'entry_preferences'    => $entry_preferences,
            'dates'                => $dates,
            'stats_eligible'       => isset( $_POST['lgw_gchamp_stats_eligible'] ) ? 1 : 0,
            'colour_scheme'        => sanitize_hex_color( $_POST['lgw_gchamp_colour'] ?? '' ) ?: '',
            'tab_colour'           => sanitize_hex_color( $_POST['lgw_gchamp_tab_colour'] ?? '' ) ?: '',
            'has_ko_bracket'       => $has_ko_bracket,
            'ko_bracket_size'      => $ko_bracket_size,
            'days_config'          => $days_config,
            'days'                 => $existing['days']                 ?? array(),
            'draw_complete'        => $existing['draw_complete']        ?? false,
            'group_stage_complete' => $existing['group_stage_complete'] ?? false,
            'ko_bracket'           => $existing['ko_bracket']           ?? null,
            'qualifiers'           => $existing['qualifiers']           ?? array(),
        );

        update_option( 'lgw_gchamp_' . $champ_id, $champ_data );
        wp_redirect( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&saved=1' ) );
        exit;
    }
    // ── Reset draw ──
    if ( isset( $_GET['greset_draw'], $_GET['gedit'] ) ) {
        $champ_id = sanitize_key( $_GET['gedit'] );
        if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'lgw_gchamp_reset_' . $champ_id ) ) {
            $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
            // Wipe per-day KO data too
            foreach ( $champ['days'] as &$_day ) {
                $_day['ko_bracket']    = null;
                $_day['ko_qualifiers'] = array();
                $_day['ko_complete']   = false;
                $_day['day_complete']  = false;
                $_day['qualifiers']    = array();
            }
            unset( $_day );
            $champ['days']                 = array();
            $champ['draw_complete']        = false;
            $champ['group_stage_complete'] = false;
            $champ['ko_bracket']           = null;
            $champ['qualifiers']           = array();
            $champ['draw_warnings']        = array();
            update_option( 'lgw_gchamp_' . $champ_id, $champ );

            // Clear all player appearances for this championship
            if ( function_exists( 'lgw_clear_all_champ_appearances' ) ) {
                lgw_clear_all_champ_appearances( $champ_id );
            }

            wp_redirect( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&saved=1' ) );
            exit;
        }
    }

    // ── Delete ──
    if ( isset( $_GET['action'], $_GET['gid'] ) && $_GET['action'] === 'gdelete' ) {
        $del_id = sanitize_key( $_GET['gid'] );
        if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'lgw_gchamp_delete_' . $del_id ) ) {
            if ( function_exists( 'lgw_clear_all_champ_appearances' ) ) {
                lgw_clear_all_champ_appearances( $del_id );
            }
            delete_option( 'lgw_gchamp_' . $del_id );
            wp_redirect( admin_url( 'admin.php?page=lgw-champs&deleted=1' ) );
            exit;
        }
    }
}

// ── Admin page router ─────────────────────────────────────────────────────────
// Hooked from lgw_champs_admin_page() in lgw-champ.php via action.
add_action( 'lgw_champs_admin_router', 'lgw_gchamp_admin_router' );
function lgw_gchamp_admin_router() {
    $gedit = sanitize_key( $_GET['gedit'] ?? '' );
    $gact  = $_GET['gaction'] ?? '';

    if ( $gedit && ( $gact === 'edit' || isset( $_GET['gedit'] ) ) ) {
        lgw_gchamp_edit_page( $gedit );
        return true;
    }
    if ( $gact === 'new' ) {
        lgw_gchamp_edit_page( '' );
        return true;
    }
    return false;
}

// ── Helper: extend the existing championships list page ───────────────────────
// Called at the bottom of lgw_champs_list_page() via action hook.
add_action( 'lgw_champs_list_after', 'lgw_gchamp_list_section' );
function lgw_gchamp_list_section() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_gchamp_%' ORDER BY option_name"
    );
    $gchamps = array();
    foreach ( $rows as $row ) {
        $id  = substr( $row->option_name, strlen( 'lgw_gchamp_' ) );
        $val = maybe_unserialize( $row->option_value );
        if ( is_array( $val ) && isset( $val['title'] ) ) {
            $gchamps[ $id ] = $val;
        }
    }

    ?>
    <hr>
    <h2 style="display:flex;align-items:center;gap:16px">
        Group Stage Championships
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gaction=new' ) ); ?>"
           class="button button-primary">+ New Group Championship</a>
    </h2>
    <p style="color:#555;font-size:13px">
        These championships run a round-robin group stage followed by a knockout bracket.
        Use the shortcode <code>[lgw_gchamp id="..."]</code> to display on the front end.
    </p>

    <?php if ( empty( $gchamps ) ): ?>
        <p>No group-stage championships yet.
           <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gaction=new' ) ); ?>">Create one →</a>
        </p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:1000px">
        <thead>
            <tr>
                <th>Title</th>
                <th>Season</th>
                <th>Discipline</th>
                <th>Entries</th>
                <th>Groups</th>
                <th>Format</th>
                <th>Draw</th>
                <th>Shortcode</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $gchamps as $id => $champ ):
            $days_cfg    = $champ['days_config'] ?? array();
            $num_days_l  = count( $days_cfg );
            $drawn       = ! empty( $champ['draw_complete'] );
            $gs_complete = ! empty( $champ['group_stage_complete'] );
            $ko_drawn    = ! empty( $champ['ko_bracket'] );
            $has_ko      = ! empty( $champ['has_ko_bracket'] );
        ?>
        <tr>
            <td><strong><?php echo esc_html( $champ['title'] ?? $id ); ?></strong></td>
            <td><?php echo esc_html( $champ['season'] ?? '—' ); ?></td>
            <td><?php echo esc_html( ucfirst( $champ['discipline'] ?? 'singles' ) ); ?></td>
            <td><?php echo count( $champ['entries'] ?? array() ); ?></td>
            <td>
                <?php echo $num_days_l; ?> day<?php echo $num_days_l !== 1 ? 's' : ''; ?>
                <?php if($drawn): ?>
                <br><small style="color:#666">
                <?php foreach($champ['days']??array() as $dd): $ng=count($dd['groups']??array()); if($ng): ?>
                    <?php echo esc_html($dd['name'].': '.$ng.'g · '.$dd['winners_per_group'].'W'.($dd['best_runners_up']>0?'+'.$dd['best_runners_up'].'R':'')); ?><br>
                <?php endif; endforeach; ?>
                </small>
                <?php endif; ?>
            </td>
            <td>
                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e8f0fe;color:#072a82">
                    Group<?php echo $has_ko?' + KO':''; ?>
                </span>
            </td>
            <td>
                <?php if ( $ko_drawn ): ?>
                    <span style="color:#138211">✅ KO drawn</span>
                <?php elseif ( $gs_complete ): ?>
                    <span style="color:#e67e22">⚡ Group stage done</span>
                <?php elseif ( $drawn ): ?>
                    <span style="color:#1a4e6e">🎲 Groups drawn</span>
                <?php else: ?>
                    <span style="color:#999">⏳ Not drawn</span>
                <?php endif; ?>
            </td>
            <td><code>[lgw_gchamp id="<?php echo esc_html( $id ); ?>"]</code></td>
            <td style="white-space:nowrap">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . urlencode( $id ) ) ); ?>"
                   class="button button-small">Edit</a>
                <a href="<?php echo esc_url( wp_nonce_url(
                    admin_url( 'admin.php?page=lgw-champs&action=gdelete&gid=' . urlencode( $id ) ),
                    'lgw_gchamp_delete_' . $id
                ) ); ?>"
                   class="button button-small button-link-delete"
                   onclick="return confirm('Delete this group championship? All data will be lost.')">
                    Delete
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif;
}

// ── Edit / New page ───────────────────────────────────────────────────────────
function lgw_gchamp_edit_page( $champ_id ) {
    $champ       = $champ_id ? get_option( 'lgw_gchamp_' . $champ_id, array() ) : array();
    $is_new      = ! $champ_id;
    $drawn       = ! empty( $champ['draw_complete'] );
    $gs_complete = ! empty( $champ['group_stage_complete'] );
    $has_scores  = $drawn && lgw_gchamp_has_any_scores( $champ );
    $disciplines = array( 'singles'=>'Singles','pairs'=>'Pairs','triples'=>'Triples','fours'=>'Fours' );
    $days_config = $champ['days_config'] ?? array( array(
        'id'=>0,'name'=>'Day 1','date'=>'','target_group_size'=>4,'winners_per_group'=>1,'best_runners_up'=>0
    ) );
    $num_days        = count( $days_config );
    $entries_str     = implode( "\n", $champ['entries'] ?? array() );
    $dates_str       = implode( "\n", $champ['dates']   ?? array() );
    $total_entries   = count( $champ['entries'] ?? array() );
    $has_ko          = ! empty( $champ['has_ko_bracket'] );
    $ko_bracket_size = intval( $champ['ko_bracket_size'] ?? 0 );
    ?>
    <div class="wrap">
    <?php lgw_page_header( $is_new ? 'New Group Championship' : 'Edit: ' . ( $champ['title'] ?? $champ_id ) ); ?>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs' ) ); ?>">← Back to championships</a></p>
    <?php if ( isset( $_GET['saved'] ) ): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>

    <?php if ( $drawn ): ?>
    <div class="notice notice-warning" style="border-left-color:#e67e22">
        <p><strong>⚠ Draw already performed.</strong>
        <?php if ( ! $has_scores ): ?>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&greset_draw=1' ), 'lgw_gchamp_reset_' . $champ_id ) ); ?>"
               onclick="return confirm('Reset the draw? All groups and fixtures will be cleared.')">Reset Draw</a>
        <?php else: ?>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&greset_draw=1' ), 'lgw_gchamp_reset_' . $champ_id ) ); ?>"
               style="color:#c0202a;font-weight:600"
               onclick="return confirm('WARNING: Scores have been entered. Resetting will permanently delete all scores and fixtures.\n\nAre you sure?')">
                ⚠ Reset Draw (wipes all scores)
            </a>
        <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <form method="post">
    <?php wp_nonce_field( 'lgw_gchamp_save', 'lgw_gchamp_save_nonce' ); ?>
    <?php if ( $is_new ): ?>
        <input type="hidden" name="gchamp_id" value="">
    <?php else: ?>
        <input type="hidden" name="gchamp_id" value="<?php echo esc_attr( $champ_id ); ?>">
    <?php endif; ?>

    <table class="form-table" style="max-width:780px">
        <?php if ( $is_new ): ?>
        <tr><th><label>Championship ID</label></th>
        <td><input type="text" name="gchamp_id" value="<?php echo esc_attr($champ_id); ?>"
                   placeholder="e.g. seniors-2026" class="regular-text">
            <p class="description">Used in shortcode <code>[lgw_gchamp id="…"]</code>. Lowercase, hyphens only.</p></td></tr>
        <?php else: ?>
        <tr><th>Championship ID</th>
        <td><code><?php echo esc_html($champ_id); ?></code>
            <p class="description">Shortcode: <code>[lgw_gchamp id="<?php echo esc_attr($champ_id); ?>"]</code></p></td></tr>
        <?php endif; ?>
        <tr><th><label for="lgw_gchamp_title">Title</label></th>
        <td><input type="text" id="lgw_gchamp_title" name="lgw_gchamp_title"
                   value="<?php echo esc_attr($champ['title']??''); ?>" placeholder="e.g. Senior Singles 2026"
                   class="regular-text" style="width:360px"></td></tr>
        <tr><th><label for="lgw_gchamp_discipline">Discipline</label></th>
        <td><select id="lgw_gchamp_discipline" name="lgw_gchamp_discipline">
            <?php foreach($disciplines as $v=>$l): ?>
            <option value="<?php echo esc_attr($v);?>" <?php selected($champ['discipline']??'singles',$v);?>><?php echo esc_html($l);?></option>
            <?php endforeach; ?>
        </select></td></tr>
        <tr><th><label for="lgw_gchamp_season">Season</label></th>
        <td><input type="text" id="lgw_gchamp_season" name="lgw_gchamp_season"
                   value="<?php echo esc_attr($champ['season']??''); ?>" placeholder="e.g. 2026"
                   class="regular-text" style="width:160px"></td></tr>
        <tr><th><label for="lgw_gchamp_entries">Entries</label></th>
        <td>
            <textarea id="lgw_gchamp_entries" name="lgw_gchamp_entries" rows="16"
                      style="width:420px;font-family:monospace;font-size:13px"
                      placeholder="One entry per line: Player Name(s), Club"><?php echo esc_textarea($entries_str); ?></textarea>
            <p class="description">
                Format: <code>Player Name(s), Club</code><br>
                Currently: <strong id="lgw-gchamp-entry-count"><?php echo $total_entries; ?></strong> entries.
                <span id="lgw-gchamp-distribution" style="color:#555"></span>
            </p>
        </td></tr>
        <tr><th>Stats eligible</th>
        <td><label>
            <input type="checkbox" name="lgw_gchamp_stats_eligible" value="1" <?php checked($champ['stats_eligible']??1,1);?>>
            Track player appearances and W/D/L for group games
        </label></td></tr>
        <tr><th>Colour scheme</th>
        <td>
            <?php
            $cur_colour = $champ['colour_scheme'] ?? '#c0202a';
            $presets = array(
                '#c0202a' => 'PGL Red',
                '#1a2e5a' => 'Navy',
                '#138211' => 'Green',
                '#e67e22' => 'Orange',
                '#7b2d8b' => 'Purple',
                '#1a6ea8' => 'Blue',
                '#2d2d2d' => 'Charcoal',
                'custom'  => 'Custom…',
            );
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
            <?php foreach($presets as $hex=>$label): if($hex==='custom') continue; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_colour_preset" value="<?php echo esc_attr($hex); ?>"
                       <?php checked($cur_colour,$hex); ?>
                       onchange="document.getElementById('lgw_gchamp_colour').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw_gchamp_colour_hidden').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw-colour-preview').style.background='<?php echo esc_js($hex); ?>'">
                <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo esc_attr($hex); ?>;border:1px solid rgba(0,0,0,.2)"></span>
                <?php echo esc_html($label); ?>
            </label>
            <?php endforeach; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_colour_preset" value="custom"
                       <?php checked(!array_key_exists($cur_colour,$presets)||$cur_colour==='custom',true); ?>
                       onchange="document.getElementById('lgw-colour-custom-row').style.display='flex'">
                <span>Custom…</span>
            </label>
            </div>
            <div id="lgw-colour-custom-row" style="display:<?php echo array_key_exists($cur_colour,$presets)&&$cur_colour!=='custom'?'none':'flex'; ?>;align-items:center;gap:8px;margin-bottom:8px">
                <input type="color" id="lgw_gchamp_colour" value="<?php echo esc_attr($cur_colour); ?>"
                       oninput="document.getElementById('lgw_gchamp_colour_hidden').value=this.value;document.getElementById('lgw-colour-preview').style.background=this.value">
                <span id="lgw-colour-preview"
                      style="display:inline-block;width:80px;height:28px;border-radius:4px;background:<?php echo esc_attr($cur_colour); ?>;border:1px solid rgba(0,0,0,.2)"></span>
            </div>
            <input type="hidden" id="lgw_gchamp_colour_hidden" name="lgw_gchamp_colour" value="<?php echo esc_attr($cur_colour); ?>">
            <p class="description">Sets the header bar and interactive element colour on the front end.</p>
        </td></tr>
        <tr><th>Tab underline colour</th>
        <td>
            <?php
            $cur_tab_colour = $champ['tab_colour'] ?? '#e8b400';
            $tab_presets = array(
                '#e8b400' => 'Gold',
                '#c0202a' => 'Red',
                '#ffffff' => 'White',
                '#22c55e' => 'Bright Green',
                '#60a5fa' => 'Sky Blue',
                '#f97316' => 'Amber',
                'custom'  => 'Custom…',
            );
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
            <?php foreach($tab_presets as $hex=>$label): if($hex==='custom') continue; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_tab_preset" value="<?php echo esc_attr($hex); ?>"
                       <?php checked($cur_tab_colour,$hex); ?>
                       onchange="document.getElementById('lgw_gchamp_tab_colour_hidden').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw-tab-colour-preview').style.background='<?php echo esc_js($hex); ?>'">
                <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo esc_attr($hex); ?>;border:1px solid rgba(0,0,0,.2)"></span>
                <?php echo esc_html($label); ?>
            </label>
            <?php endforeach; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_tab_preset" value="custom"
                       <?php checked(!array_key_exists($cur_tab_colour,$tab_presets)||$cur_tab_colour==='custom',true); ?>
                       onchange="document.getElementById('lgw-tab-colour-custom-row').style.display='flex'">
                <span>Custom…</span>
            </label>
            </div>
            <div id="lgw-tab-colour-custom-row" style="display:<?php echo array_key_exists($cur_tab_colour,$tab_presets)&&$cur_tab_colour!=='custom'?'none':'flex';?>;align-items:center;gap:8px;margin-bottom:8px">
                <input type="color" id="lgw_gchamp_tab_colour" value="<?php echo esc_attr($cur_tab_colour); ?>"
                       oninput="document.getElementById('lgw_gchamp_tab_colour_hidden').value=this.value;document.getElementById('lgw-tab-colour-preview').style.background=this.value">
                <span id="lgw-tab-colour-preview" style="display:inline-block;width:80px;height:28px;border-radius:4px;background:<?php echo esc_attr($cur_tab_colour); ?>;border:1px solid rgba(0,0,0,.2)"></span>
            </div>
            <input type="hidden" id="lgw_gchamp_tab_colour_hidden" name="lgw_gchamp_tab_colour" value="<?php echo esc_attr($cur_tab_colour); ?>">
            <p class="description">The underline colour on active day tabs. Default is gold to match the PGL badge border.</p>
        </td></tr>
        <tr><th>Optional knockout bracket</th>
        <td><label>
            <input type="checkbox" name="lgw_gchamp_has_ko" value="1" <?php checked($has_ko,true);?> id="lgw_gchamp_has_ko">
            Include an internal knockout bracket after the group stage
        </label>
        <p class="description">Leave unchecked if Finals Week is run separately outside this system.</p>
        </td></tr>
    </table>

    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">📅 Competition Days</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        Each day is an independent section with its own groups and qualifiers.
        The system auto-calculates the number of groups from the target group size.
    </p>
    <input type="hidden" name="lgw_gchamp_num_days" id="lgw_gchamp_num_days" value="<?php echo $num_days; ?>">
    <div style="max-width:960px;overflow-x:auto">
    <table class="widefat" id="lgw-gchamp-days-table" style="min-width:800px">
        <thead><tr>
            <th style="width:32px">#</th>
            <th style="min-width:120px">Day name</th>
            <th style="width:105px">Play date</th>
            <th style="width:80px">Target<br>group size</th>
            <th style="width:75px">Winners/<br>group</th>
            <th style="width:75px">Best<br>runners-up</th>
            <th style="min-width:200px">Auto-calculated</th>
            <th style="width:32px"></th>
        </tr></thead>
        <tbody id="lgw-gchamp-days-tbody">
        <?php foreach ( $days_config as $di => $day ):
            $tgs = intval($day['target_group_size']??4);
            $wpg = intval($day['winners_per_group'] ??1);
            $bru = intval($day['best_runners_up']    ??0);
        ?>
        <tr class="lgw-gchamp-day-row" data-day="<?php echo $di; ?>">
            <td style="color:#888;font-weight:700;text-align:center"><?php echo $di+1; ?></td>
            <td><input type="text" name="lgw_gchamp_day_names[<?php echo $di;?>]"
                       value="<?php echo esc_attr($day['name']??('Day '.($di+1))); ?>"
                       class="regular-text" style="width:100%" <?php echo $drawn?'readonly':''; ?>></td>
            <td><input type="text" name="lgw_gchamp_day_dates[<?php echo $di;?>]"
                       value="<?php echo esc_attr($day['date']??''); ?>"
                       placeholder="dd/mm/yyyy" class="small-text" style="width:96px"></td>
            <td><input type="number" name="lgw_gchamp_day_tgs[<?php echo $di;?>]"
                       value="<?php echo $tgs; ?>" min="2" max="20" step="1"
                       class="small-text lgw-gchamp-day-tgs" style="width:58px" <?php echo $drawn?'readonly':''; ?>></td>
            <td><input type="number" name="lgw_gchamp_day_wpg[<?php echo $di;?>]"
                       value="<?php echo $wpg; ?>" min="1" step="1"
                       class="small-text lgw-gchamp-day-wpg" style="width:52px"></td>
            <td><input type="number" name="lgw_gchamp_day_bru[<?php echo $di;?>]"
                       value="<?php echo $bru; ?>" min="0" step="1"
                       class="small-text lgw-gchamp-day-bru" style="width:52px"></td>
            <td class="lgw-gchamp-day-calc" style="font-size:12px;color:#555">—</td>
            <td><?php if(!$drawn):?><button type="button" class="button button-small lgw-gchamp-day-remove" style="color:#c00;padding:2px 6px" title="Remove">✕</button><?php endif;?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if(!$drawn):?><p style="margin-top:8px"><button type="button" id="lgw-gchamp-add-day" class="button button-secondary">+ Add Day</button></p><?php endif;?>

    <?php
    // ── Date preference panel ─────────────────────────────────────────────────
    $all_day_dates  = array_values( array_filter( array_column( $days_config, 'date' ) ) );
    $unique_dates   = array_values( array_unique( $all_day_dates ) );
    $cur_entries    = array_values( array_filter( array_map( 'trim', explode( "\n", $entries_str ) ) ) );
    $exist_prefs    = $champ['entry_preferences'] ?? array();
    if ( ! empty($cur_entries) && ! empty($unique_dates) ):
    ?>
    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🗓 Date Preferences</h2>
    <details <?php echo !empty($exist_prefs)?'open':'';?>>
        <summary style="cursor:pointer;font-weight:600;color:#072a82;margin-bottom:8px;user-select:none">
            <?php
            $sc=0; foreach($cur_entries as $e){if(!empty($exist_prefs[$e]))$sc++;}
            echo $sc>0?esc_html($sc).' of '.count($cur_entries).' preferences set — click to edit':'Set date preferences (optional)';
            ?>
        </summary>
        <div style="margin-top:8px">
            <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button type="button" id="lgw-gchamp-prefs-clear-all" class="button button-small">Clear all</button>
                <?php foreach($unique_dates as $ud):?>
                <button type="button" class="button button-small lgw-gchamp-prefs-bulk" data-date="<?php echo esc_attr($ud);?>">All → <?php echo esc_html($ud);?></button>
                <?php endforeach;?>
            </div>
            <div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;border-radius:4px">
            <table class="widefat" style="font-size:13px">
                <thead><tr><th style="width:36px">#</th><th>Entry</th><th style="width:165px">Preferred day</th></tr></thead>
                <tbody>
                <?php foreach($cur_entries as $idx=>$entry):
                    $pv=($exist_prefs[$entry]??''); $key=md5($entry);?>
                <tr>
                    <td style="color:#999"><?php echo $idx+1;?></td>
                    <td><?php echo esc_html($entry);?></td>
                    <td><select name="lgw_gchamp_entry_prefs[<?php echo esc_attr($key);?>]"
                                class="lgw-gchamp-pref-select" style="width:150px;font-size:13px">
                        <option value="">— no preference —</option>
                        <?php foreach($unique_dates as $ud):?>
                        <option value="<?php echo esc_attr($ud);?>" <?php selected($pv,$ud);?>><?php echo esc_html($ud);?></option>
                        <?php endforeach;?>
                    </select></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            </div>
            <p class="description" style="margin-top:6px"><span id="lgw-gchamp-prefs-summary"></span></p>
        </div>
    </details>
    <?php endif;?>

    <?php if($has_ko):?>
    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🏆 Knockout Stage</h2>
    <table class="form-table" style="max-width:780px">
        <tr><th><label>KO round dates</label></th>
        <td><textarea name="lgw_gchamp_dates" rows="5" style="width:200px;font-family:monospace;font-size:13px"
                      placeholder="dd/mm/yyyy"><?php echo esc_textarea($dates_str);?></textarea>
            <p class="description">One date per line in round order.</p></td></tr>
        <tr><th><label>Bracket size</label></th>
        <td><input type="number" name="lgw_gchamp_ko_bracket_size" value="<?php echo esc_attr($ko_bracket_size);?>" min="2" step="1" class="small-text">
            <p class="description">Must be a power of 2 (4, 8, 16…).</p></td></tr>
    </table>
    <?php endif;?>

    <hr style="margin:24px 0">
    <?php submit_button($is_new?'Create Championship':'Save Changes','primary','lgw_gchamp_submit',false);?>
    &nbsp;<a href="<?php echo esc_url(admin_url('admin.php?page=lgw-champs'));?>" class="button">Cancel</a>
    </form>

    <?php /* ── Run Draw ── */ ?>
    <?php if(!$is_new && !empty($champ['entries'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🎲 Group Draw</h2>
    <?php if(!empty($champ['draw_warnings'])):?>
    <div class="notice notice-warning is-dismissible" style="max-width:700px">
        <p><strong>⚠ Draw warnings:</strong></p>
        <ul style="margin:0;padding-left:20px">
            <?php foreach($champ['draw_warnings'] as $w):?><li><?php echo esc_html($w);?></li><?php endforeach;?>
        </ul>
    </div>
    <?php endif;?>

    <?php if(!empty($champ['draw_complete'])):?>
    <div class="notice notice-info" style="max-width:700px;margin-bottom:12px">
        <p>✅ <strong>Draw complete.</strong>
        <?php
        $tfx=0;
        foreach($champ['days']??array() as $day) foreach($day['groups']??array() as $g) $tfx+=count($g['fixtures']??array());
        $nd=count($champ['days']??array());
        echo $nd.' day'.($nd!==1?'s':'').', '.$tfx.' fixtures.';
        if($has_scores) echo ' <span style="color:#c0202a;font-weight:600">Scores entered — re-draw will wipe them.</span>';
        ?>
        </p>
    </div>
    <?php foreach($champ['days']??array() as $day):?>
    <div style="display:inline-block;vertical-align:top;margin:0 12px 12px 0;border:1px solid #d0d5e8;border-radius:6px;padding:12px 16px;min-width:220px;background:#f8faff">
        <strong style="color:#072a82"><?php echo esc_html($day['name']??'');?></strong>
        <?php if(!empty($day['date'])):?><span style="margin-left:6px;font-size:12px;color:#888"><?php echo esc_html($day['date']);?></span><?php endif;?>
        <?php foreach($day['groups']??array() as $g):?>
        <div style="margin-top:6px;padding:6px 8px;background:#fff;border:1px solid #e0e4f0;border-radius:4px;font-size:12px">
            <strong style="color:#1a2e5a"><?php echo esc_html($g['name']??'');?></strong>
            <ul style="margin:4px 0 0;padding-left:14px">
                <?php foreach($g['entries']??array() as $e):?><li><?php echo esc_html($e);?></li><?php endforeach;?>
            </ul>
            <p style="margin:4px 0 0;color:#888"><?php echo count($g['entries']??array());?> entries · <?php echo count($g['fixtures']??array());?> fixtures</p>
        </div>
        <?php endforeach;?>
    </div>
    <?php endforeach;?>
    <?php endif;?>

    <div style="clear:both;margin-top:12px">
        <button type="button" id="lgw-gchamp-run-draw-btn" class="button button-primary button-large">
            <?php echo $drawn?'🔄 Re-run Draw':'🎲 Run Draw';?>
        </button>
        <span id="lgw-gchamp-draw-spinner" class="spinner" style="float:none;margin-left:8px;vertical-align:middle"></span>
        <p id="lgw-gchamp-draw-msg" style="margin-top:8px;font-size:13px"></p>
    </div>
    <script>
    (function(){
        var btn=document.getElementById('lgw-gchamp-run-draw-btn');
        var spinner=document.getElementById('lgw-gchamp-draw-spinner');
        var msg=document.getElementById('lgw-gchamp-draw-msg');
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_draw'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        var hasScores=<?php echo lgw_gchamp_has_any_scores($champ)?'true':'false';?>;
        if(!btn)return;
        btn.addEventListener('click',function(){
            if(hasScores&&!confirm('WARNING: Scores have been entered. Re-drawing will permanently delete all scores, fixtures, and player appearance records.\n\nAre you sure?'))return;
            runDraw(hasScores);
        });
        function runDraw(confirmed){
            btn.disabled=true;spinner.style.display='inline-block';
            msg.style.color='#555';msg.textContent='Running draw\u2026';
            var fd=new FormData();
            fd.append('action','lgw_gchamp_run_draw');fd.append('nonce',nonce);fd.append('champ_id',champId);
            if(confirmed)fd.append('confirmed','1');
            fetch('<?php echo esc_js(admin_url('admin-ajax.php'));?>',{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    spinner.style.display='none';btn.disabled=false;
                    if(!data.success){
                        if(data.data&&data.data.code==='scores_exist'){if(confirm('WARNING: '+data.data.message+'\n\nContinue?'))runDraw(true);return;}
                        msg.style.color='#c0202a';msg.textContent='Error: '+(data.data||'Unknown');return;
                    }
                    var warns=data.data.warnings||[];
                    var wh=warns.length?'<br><ul style="margin:4px 0;padding-left:18px">'+warns.map(function(w){return'<li>'+w+'</li>';}).join('')+'</ul>':'';
                    msg.style.color='#138211';msg.innerHTML='\u2705 Draw complete.'+wh;
                    setTimeout(function(){window.location.reload();},1800);
                })
                .catch(function(err){spinner.style.display='none';btn.disabled=false;msg.style.color='#c0202a';msg.textContent='Failed: '+err.message;});
        }
    })();
    </script>
    <?php endif;?>

    <?php /* ── KO Seeding ── */ ?>
    <?php if(!$is_new&&$has_ko&&!empty($champ['group_stage_complete'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🏆 Knockout Bracket</h2>
    <?php if(!empty($champ['ko_bracket'])):?>
    <div class="notice notice-success" style="max-width:700px;margin-bottom:8px">
        <p>✅ <strong>Seeded.</strong> <?php echo count($champ['qualifiers']??array());?> qualifiers.
        <a href="#" id="lgw-gchamp-reseed-link" style="margin-left:8px;color:#c0202a">Re-seed</a></p>
    </div>
    <div style="margin-bottom:12px">
        <?php foreach($champ['qualifiers']??array() as $idx=>$q):?>
        <span style="display:inline-block;padding:3px 10px;margin:2px;background:#e8f0fe;border-radius:12px;font-size:12px;color:#072a82;font-weight:600">
            <?php echo $idx+1;?>. <?php echo esc_html(lgw_gchamp_short_name($q));?>
        </span>
        <?php endforeach;?>
    </div>
    <?php endif;?>
    <button type="button" id="lgw-gchamp-seed-btn" class="button button-primary button-large">
        <?php echo empty($champ['ko_bracket'])?'🏆 Seed Knockout Bracket':'🔄 Re-seed';?>
    </button>
    <span id="lgw-gchamp-seed-spinner" class="spinner" style="float:none;margin-left:8px;vertical-align:middle"></span>
    <p id="lgw-gchamp-seed-msg" style="margin-top:8px;font-size:13px"></p>
    <script>
    (function(){
        var btn=document.getElementById('lgw-gchamp-seed-btn');
        var reseed=document.getElementById('lgw-gchamp-reseed-link');
        var spinner=document.getElementById('lgw-gchamp-seed-spinner');
        var msg=document.getElementById('lgw-gchamp-seed-msg');
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_draw'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        var hasKo=<?php echo !empty($champ['ko_bracket'])?'true':'false';?>;
        function doSeed(){
            if(btn)btn.disabled=true;spinner.style.display='inline-block';
            msg.style.color='#555';msg.textContent='Seeding\u2026';
            var fd=new FormData();fd.append('action','lgw_gchamp_seed_knockout');fd.append('nonce',nonce);fd.append('champ_id',champId);
            fetch('<?php echo esc_js(admin_url('admin-ajax.php'));?>',{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    spinner.style.display='none';if(btn)btn.disabled=false;
                    if(!data.success){msg.style.color='#c0202a';msg.textContent='Error: '+(data.data||'Unknown');return;}
                    msg.style.color='#138211';msg.textContent='\u2705 Seeded \u2014 '+(data.data.qualifiers||[]).length+' qualifiers.';
                    setTimeout(function(){window.location.reload();},1600);
                })
                .catch(function(err){spinner.style.display='none';if(btn)btn.disabled=false;msg.style.color='#c0202a';msg.textContent='Failed: '+err.message;});
        }
        if(btn)btn.addEventListener('click',function(){if(hasKo&&!confirm('Re-seeding will clear the existing bracket.\n\nContinue?'))return;doSeed();});
        if(reseed)reseed.addEventListener('click',function(e){e.preventDefault();if(!confirm('Re-seeding will clear the existing bracket.\n\nContinue?'))return;doSeed();});
    })();
    </script>
    <?php endif;?>

    <?php /* ── Group Scores ── */ ?>
    <?php if(!$is_new&&$drawn&&!empty($champ['days'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">📋 Group Scores</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        <?php if($gs_complete):?><span style="color:#138211;font-weight:600">✅ All fixtures complete.</span>
        <?php else:?>Enter scores for each group fixture. Standings recalculate immediately.<?php endif;?>
    </p>
    <div class="lgw-gchamp-admin-scores-wrap">
    <?php foreach($champ['days'] as $day_idx=>$day):
        foreach($day['groups']??array() as $gi=>$group):
            $g_fx=$group['fixtures']??array(); $g_entries=$group['entries']??array();
            $by_round=array(); foreach($g_fx as $fx) $by_round[$fx['round']][]=$fx; ksort($by_round);
    ?>
    <div class="lgw-gchamp-admin-group-block">
        <div class="lgw-gchamp-admin-group-hdr">
            <?php echo esc_html($day['name']??'');?> — <?php echo esc_html($group['name']??'');?>
            <?php if(!empty($day['date'])):?><span class="lgw-gchamp-admin-group-date">📅 <?php echo esc_html($day['date']);?></span><?php endif;?>
            <span style="margin-left:auto;font-size:12px;font-weight:400;opacity:.8"><?php echo lgw_gchamp_count_played($g_fx);?>/<?php echo count($g_fx);?> played</span>
        </div>
        <table class="lgw-gchamp-admin-fx-table">
            <thead><tr><th style="width:36px">Rnd</th><th>Home</th><th style="width:128px;text-align:center">Score</th><th>Away</th><th style="width:140px"></th></tr></thead>
            <tbody>
            <?php foreach($by_round as $rn=>$rfxs): foreach($rfxs as $fx):
                $hs=$fx['home_score']; $as_v=$fx['away_score']; $scored=($hs!==null&&$as_v!==null);
                $pk=esc_attr($fx['pos_key']??'');
            ?>
            <tr class="lgw-gchamp-admin-fx-row" data-pos-key="<?php echo $pk;?>" data-day-id="<?php echo intval($day_idx);?>" data-group-id="<?php echo intval($gi);?>">
                <td style="color:#888;font-weight:700;text-align:center">R<?php echo $rn+1;?></td>
                <td><?php echo esc_html(lgw_gchamp_short_name($fx['home']));?></td>
                <td style="text-align:center">
                    <div style="display:flex;align-items:center;justify-content:center;gap:4px">
                        <input type="number" class="lgw-gchamp-admin-score-input lgw-gchamp-admin-score-h" value="<?php echo $scored?intval($hs):'';?>" min="0" step="1" placeholder="–">
                        <span style="font-weight:700;color:#888">–</span>
                        <input type="number" class="lgw-gchamp-admin-score-input lgw-gchamp-admin-score-a" value="<?php echo $scored?intval($as_v):'';?>" min="0" step="1" placeholder="–">
                    </div>
                </td>
                <td><?php echo esc_html(lgw_gchamp_short_name($fx['away']));?></td>
                <td>
                    <button type="button" class="lgw-gchamp-admin-save-btn">Save</button>
                    <?php if($scored):?><button type="button" class="lgw-gchamp-admin-clear-btn">Clear</button><?php endif;?>
                    <span class="lgw-gchamp-admin-score-status"></span>
                </td>
            </tr>
            <?php endforeach; endforeach;?>
            </tbody>
        </table>
    </div>
    <?php endforeach; endforeach;?>
    </div>
    <script>
    (function(){
        var ajaxUrl='<?php echo esc_js(admin_url('admin-ajax.php'));?>';
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_score'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        function ss(el,msg,cls){if(!el)return;el.textContent=msg;el.className='lgw-gchamp-admin-score-status'+(cls?' '+cls:'');}
        function saveScore(row,clear){
            var pk=row.getAttribute('data-pos-key'),dayId=row.getAttribute('data-day-id'),gid=row.getAttribute('data-group-id');
            var hi=row.querySelector('.lgw-gchamp-admin-score-h'),ai=row.querySelector('.lgw-gchamp-admin-score-a');
            var st=row.querySelector('.lgw-gchamp-admin-score-status');
            if(!clear&&(hi.value.trim()===''||ai.value.trim()==='')){ss(st,'Enter both scores','err');return;}
            ss(st,'Saving\u2026','');
            var fd=new FormData();
            fd.append('action','lgw_gchamp_save_score');fd.append('nonce',nonce);fd.append('champ_id',champId);
            fd.append('day_id',dayId);fd.append('group_id',gid);fd.append('pos_key',pk);
            if(clear)fd.append('clear','1');else{fd.append('home_score',hi.value);fd.append('away_score',ai.value);}
            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if(!data.success){ss(st,data.data||'Error','err');return;}
                if(clear){hi.value='';ai.value='';var cb=row.querySelector('.lgw-gchamp-admin-clear-btn');if(cb)cb.remove();}
                else if(!row.querySelector('.lgw-gchamp-admin-clear-btn')){
                    var sb=row.querySelector('.lgw-gchamp-admin-save-btn');
                    if(sb){var cb2=document.createElement('button');cb2.type='button';cb2.className='lgw-gchamp-admin-clear-btn';cb2.textContent='Clear';sb.parentNode.insertBefore(cb2,sb.nextSibling);}
                }
                ss(st,clear?'Cleared':'Saved \u2713','ok');setTimeout(function(){ss(st,'','');},2500);
                if(data.data&&data.data.group_stage_complete)location.reload();
            }).catch(function(err){ss(st,'Failed: '+err.message,'err');});
        }
        document.querySelectorAll('.lgw-gchamp-admin-save-btn').forEach(function(b){b.addEventListener('click',function(){saveScore(b.closest('.lgw-gchamp-admin-fx-row'),false);});});
        document.addEventListener('click',function(e){var cb=e.target.closest('.lgw-gchamp-admin-clear-btn');if(!cb)return;if(confirm('Clear this score?'))saveScore(cb.closest('.lgw-gchamp-admin-fx-row'),true);});
        document.querySelectorAll('.lgw-gchamp-admin-score-input').forEach(function(i){i.addEventListener('keydown',function(e){if(e.key!=='Enter')return;e.preventDefault();saveScore(i.closest('.lgw-gchamp-admin-fx-row'),false);});});
    })();
    </script>
    <?php endif;?>

    </div><!-- .wrap -->
    <script>
    (function(){
        var entriesTA=document.getElementById('lgw_gchamp_entries');
        var numDaysInp=document.getElementById('lgw_gchamp_num_days');
        var tbody=document.getElementById('lgw-gchamp-days-tbody');
        var entryCount=document.getElementById('lgw-gchamp-entry-count');
        var distSpan=document.getElementById('lgw-gchamp-distribution');
        var addDayBtn=document.getElementById('lgw-gchamp-add-day');
        var dayIndex=<?php echo $num_days;?>;

        function countEntries(){return entriesTA?entriesTA.value.split('\n').filter(function(l){return l.trim()!=='';}).length:0;}

        function calcDay(row,dayEntries){
            var tgsEl=row.querySelector('.lgw-gchamp-day-tgs');
            var wpgEl=row.querySelector('.lgw-gchamp-day-wpg');
            var bruEl=row.querySelector('.lgw-gchamp-day-bru');
            var calc=row.querySelector('.lgw-gchamp-day-calc');
            if(!tgsEl||!wpgEl||!bruEl||!calc)return;
            var tgs=Math.max(2,parseInt(tgsEl.value)||4);
            var wpg=Math.max(1,parseInt(wpgEl.value)||1);
            var bru=Math.max(0,parseInt(bruEl.value)||0);
            var ng=Math.ceil(dayEntries/tgs);
            var rem=dayEntries%tgs;
            var totalQ=(ng*wpg)+bru;
            var sz=rem===0?ng+' groups of '+tgs:(ng-1)+' groups of '+tgs+', 1 of '+(dayEntries-tgs*(ng-1));
            calc.innerHTML='<strong>'+ng+' group'+(ng!==1?'s':'')+'</strong><br><span style="color:#555">'+sz+'</span><br><span style="color:#138211;font-weight:600">'+totalQ+' qualifier'+(totalQ!==1?'s':'')+'</span>';
        }

        function updateAll(){
            var n=countEntries();
            if(entryCount)entryCount.textContent=n;
            var rows=tbody?tbody.querySelectorAll('.lgw-gchamp-day-row'):[];
            var nd=rows.length;
            if(nd===0)return;
            var base=Math.floor(n/nd),rem=n%nd;
            rows.forEach(function(row,i){calcDay(row,base+(i<rem?1:0));});
            if(distSpan)distSpan.textContent=n===0?'':(nd===1?'':(rem?(' — ~'+base+'/~'+(base+1)+' per day'):(' — '+base+' per day')));
            if(numDaysInp)numDaysInp.value=nd;
        }

        function bindRow(row){row.querySelectorAll('input').forEach(function(i){i.addEventListener('input',updateAll);});}

        if(addDayBtn){
            addDayBtn.addEventListener('click',function(){
                var i=dayIndex++;
                var tr=document.createElement('tr');tr.className='lgw-gchamp-day-row';tr.setAttribute('data-day',i);
                tr.innerHTML='<td style="color:#888;font-weight:700;text-align:center">'+(i+1)+'</td>'+
                    '<td><input type="text" name="lgw_gchamp_day_names['+i+']" value="Day '+(i+1)+'" class="regular-text" style="width:100%"></td>'+
                    '<td><input type="text" name="lgw_gchamp_day_dates['+i+']" placeholder="dd/mm/yyyy" class="small-text" style="width:96px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_tgs['+i+']" value="4" min="2" max="20" step="1" class="small-text lgw-gchamp-day-tgs" style="width:58px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_wpg['+i+']" value="1" min="1" step="1" class="small-text lgw-gchamp-day-wpg" style="width:52px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_bru['+i+']" value="0" min="0" step="1" class="small-text lgw-gchamp-day-bru" style="width:52px"></td>'+
                    '<td class="lgw-gchamp-day-calc" style="font-size:12px;color:#555">\u2014</td>'+
                    '<td><button type="button" class="button button-small lgw-gchamp-day-remove" style="color:#c00;padding:2px 6px">\u2715</button></td>';
                tbody.appendChild(tr);bindRow(tr);updateAll();
            });
        }

        if(tbody){
            tbody.addEventListener('click',function(e){
                var btn=e.target.closest('.lgw-gchamp-day-remove');if(!btn)return;
                var rows=tbody.querySelectorAll('.lgw-gchamp-day-row');if(rows.length<=1)return;
                btn.closest('tr').remove();
                tbody.querySelectorAll('.lgw-gchamp-day-row').forEach(function(r,idx){
                    var nc=r.querySelector('td:first-child');if(nc)nc.textContent=idx+1;
                    r.querySelectorAll('input').forEach(function(inp){inp.name=inp.name.replace(/\[\d+\]/,'['+idx+']');});
                });
                updateAll();
            });
            tbody.querySelectorAll('.lgw-gchamp-day-row').forEach(bindRow);
        }
        if(entriesTA)entriesTA.addEventListener('input',updateAll);

        // Prefs panel
        function updatePrefSummary(){
            var s=document.getElementById('lgw-gchamp-prefs-summary');if(!s)return;
            var sel=document.querySelectorAll('.lgw-gchamp-pref-select'),counts={},total=0;
            sel.forEach(function(x){if(x.value){counts[x.value]=(counts[x.value]||0)+1;total++;}});
            if(!total){s.textContent='No preferences set.';return;}
            var parts=[];Object.keys(counts).sort().forEach(function(d){parts.push(counts[d]+' \u2192 '+d);});
            s.textContent=total+' preference'+(total!==1?'s':'')+' set: '+parts.join(', ');
        }
        document.querySelectorAll('.lgw-gchamp-pref-select').forEach(function(s){s.addEventListener('change',updatePrefSummary);});
        document.querySelectorAll('.lgw-gchamp-prefs-bulk').forEach(function(b){b.addEventListener('click',function(){var d=b.getAttribute('data-date');document.querySelectorAll('.lgw-gchamp-pref-select').forEach(function(s){s.value=d;});updatePrefSummary();});});
        var ca=document.getElementById('lgw-gchamp-prefs-clear-all');if(ca)ca.addEventListener('click',function(){document.querySelectorAll('.lgw-gchamp-pref-select').forEach(function(s){s.value='';});updatePrefSummary();});

        updateAll();updatePrefSummary();
    })();
    </script>
    <?php
}


// ── AJAX: run the group draw ───────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_run_draw', 'lgw_ajax_gchamp_run_draw' );
function lgw_ajax_gchamp_run_draw() {
    check_ajax_referer( 'lgw_gchamp_draw', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    if ( ! $champ_id ) wp_send_json_error( 'Missing championship ID' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found' );
    $confirmed = ! empty( $_POST['confirmed'] );
    if ( lgw_gchamp_has_any_scores( $champ ) && ! $confirmed ) {
        wp_send_json_error( array( 'code'=>'scores_exist', 'message'=>'Scores have already been entered. Confirming will permanently delete all scores, fixtures, and player appearance records.' ) );
    }
    $entries     = $champ['entries']           ?? array();
    $days_config = $champ['days_config']       ?? array();
    $entry_prefs = $champ['entry_preferences'] ?? array();
    if ( empty( $entries ) )     wp_send_json_error( 'No entries configured' );
    if ( empty( $days_config ) ) wp_send_json_error( 'No days configured' );
    if ( ! empty( $champ['draw_complete'] ) && function_exists( 'lgw_clear_all_champ_appearances' ) ) {
        lgw_clear_all_champ_appearances( $champ_id );
    }
    $result = lgw_gchamp_run_draw( $entries, $days_config, $entry_prefs );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
    $champ['days']                 = $result['days'];
    $champ['draw_complete']        = true;
    $champ['group_stage_complete'] = false;
    $champ['ko_bracket']           = null;
    $champ['qualifiers']           = array();
    $champ['draw_warnings']        = $result['warnings'];
    if ( strlen( serialize( $champ ) ) > 800000 ) wp_send_json_error( 'Draw data exceeds safe storage limit.' );
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'days'=>$result['days'], 'warnings'=>$result['warnings'] ) );
}

// ── AJAX: save a group score ──────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_save_score',        'lgw_ajax_gchamp_save_score' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_save_score', 'lgw_ajax_gchamp_save_score' );
function lgw_ajax_gchamp_save_score() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
    $champ_id = sanitize_key( $_POST['champ_id']  ?? '' );
    $day_id   = intval( $_POST['day_id']           ?? -1 );
    $group_id = intval( $_POST['group_id']         ?? -1 );
    $pos_key  = sanitize_text_field( $_POST['pos_key'] ?? '' );
    $clear    = ! empty( $_POST['clear'] );
    $context   = sanitize_key( $_POST['context'] ?? 'group' ); // 'group' or 'ko'
    $ko_round  = intval( $_POST['ko_round'] ?? -1 );
    $ko_match  = intval( $_POST['ko_match'] ?? -1 );
    // For group context, pos_key and group_id are required; for ko context they are not
    if ( $context !== 'ko' && ( $group_id < 0 || ! $pos_key ) ) wp_send_json_error( 'Missing parameters.' );
    if ( ! $champ_id || $day_id < 0 ) wp_send_json_error( 'Missing parameters.' );
    if ( ! $clear ) {
        $hs = $_POST['home_score'] ?? ''; $as = $_POST['away_score'] ?? '';
        if ( ! is_numeric($hs)||!is_numeric($as)||intval($hs)<0||intval($as)<0 ) wp_send_json_error( 'Scores must be non-negative whole numbers.' );
        $hs = intval($hs); $as = intval($as);
    }
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) )                    wp_send_json_error('Championship not found.');
    if ( empty($champ['draw_complete']) )   wp_send_json_error('Draw not yet performed.');
    if ( ! isset($champ['days'][$day_id]) ) wp_send_json_error('Day not found.');
    $standings = array();

    if ( $context === 'ko' ) {
        // ── Knockout fixture save ──────────────────────────────────────────────
        if ( ! isset( $champ['days'][$day_id]['ko_bracket'] ) || empty( $champ['days'][$day_id]['ko_bracket'] ) ) {
            wp_send_json_error('KO bracket not yet seeded for this day.');
        }
        $found = false;
        foreach ( $champ['days'][$day_id]['ko_bracket']['rounds'] as &$round ) {
            if ( $round['round'] !== $ko_round ) continue;
            foreach ( $round['matches'] as &$match ) {
                if ( $match['match'] !== $ko_match ) continue;
                $match['home_score'] = $clear ? null : $hs;
                $match['away_score'] = $clear ? null : $as;
                // Advance winner to next round
                if ( ! $clear && $hs !== $as ) {
                    $winner = $hs > $as ? $match['home'] : $match['away'];
                    lgw_gchamp_advance_ko_winner( $champ['days'][$day_id]['ko_bracket'], $ko_round, $ko_match, $winner );
                }
                $found = true; break 2;
            }
            unset($match);
        }
        unset($round);
        if ( ! $found ) wp_send_json_error('KO fixture not found.');

        // Check ko_complete and set Finals Week qualifiers
        $num_days_total = count( $champ['days'] );
        $ko_complete    = lgw_gchamp_ko_all_played( $champ['days'][$day_id]['ko_bracket'] );
        if ( $ko_complete && ! $clear ) {
            $champ['days'][$day_id]['ko_complete']   = true;
            $champ['days'][$day_id]['ko_qualifiers'] = lgw_gchamp_compute_ko_qualifiers(
                $champ['days'][$day_id]['ko_bracket'], $num_days_total
            );
        } elseif ( $clear ) {
            $champ['days'][$day_id]['ko_complete']   = false;
            $champ['days'][$day_id]['ko_qualifiers'] = array();
        }

        $all_complete = true;
        foreach ( $champ['days'] as $d ) { if ( empty($d['day_complete']) ) { $all_complete=false; break; } }
        $champ['group_stage_complete'] = $all_complete;
        update_option( 'lgw_gchamp_' . $champ_id, $champ );
        wp_send_json_success( array(
            'context'        => 'ko',
            'day_id'         => $day_id,
            'ko_bracket'     => $champ['days'][$day_id]['ko_bracket'],
            'ko_complete'    => $ko_complete,
            'ko_qualifiers'  => $champ['days'][$day_id]['ko_qualifiers'] ?? array(),
        ) );

    } else {
        // ── Group fixture save ────────────────────────────────────────────────
        if ( $group_id < 0 || ! $pos_key ) wp_send_json_error('Missing group parameters.');
        if ( ! isset($champ['days'][$day_id]['groups'][$group_id]) ) wp_send_json_error('Group not found.');

        // Block edits if KO bracket has any scores entered
        $day_ko = $champ['days'][$day_id]['ko_bracket'] ?? null;
        $ko_has_scores_check = false;
        if ( $day_ko ) {
            foreach ( ($day_ko['rounds'] ?? array()) as $_rnd ) {
                foreach ( ($_rnd['matches'] ?? array()) as $_mch ) {
                    if ( $_mch['home_score'] !== null || $_mch['away_score'] !== null ) {
                        $ko_has_scores_check = true; break 2;
                    }
                }
            }
        }
        if ( $ko_has_scores_check ) {
            wp_send_json_error( 'Cannot edit group scores — the knockout bracket for this day has results entered. Clear the knockout scores first.' );
        }

        $found = false;
        foreach ( $champ['days'][$day_id]['groups'][$group_id]['fixtures'] as &$fx ) {
            if ( ($fx['pos_key']??'') === $pos_key ) {
                $fx['home_score'] = $clear ? null : $hs;
                $fx['away_score'] = $clear ? null : $as;
                $found = true; break;
            }
        }
        unset($fx);
        if ( ! $found ) wp_send_json_error('Fixture not found.');
        $g_entries = $champ['days'][$day_id]['groups'][$group_id]['entries']  ?? array();
        $g_fixtures= $champ['days'][$day_id]['groups'][$group_id]['fixtures'] ?? array();
        $standings  = lgw_gchamp_compute_standings( $g_entries, $g_fixtures );

        $was_complete = ! empty( $champ['days'][$day_id]['day_complete'] );
        $day_complete = lgw_gchamp_day_fixtures_all_played( $champ['days'][$day_id] );

        if ( $day_complete && ! $clear ) {
            $champ['days'][$day_id]['day_complete'] = true;
            $champ['days'][$day_id]['qualifiers']   = lgw_gchamp_compute_day_qualifiers( $champ['days'][$day_id] );
            // Seed/reseed KO bracket when day is complete and no KO scores exist
            if ( empty( $champ['days'][$day_id]['ko_bracket'] ) || ! $ko_has_scores_check ) {
                lgw_gchamp_auto_seed_day_ko( $champ, $day_id );
            }
        } elseif ( $clear ) {
            $champ['days'][$day_id]['day_complete'] = false;
            $champ['days'][$day_id]['qualifiers']   = array();
        }

        $all_complete = true;
        foreach ( $champ['days'] as $d ) { if ( empty($d['day_complete']) ) { $all_complete=false; break; } }
        $champ['group_stage_complete'] = $all_complete;
        update_option( 'lgw_gchamp_' . $champ_id, $champ );
        wp_send_json_success( array(
            'context'              => 'group',
            'standings'            => $standings,
            'day_id'               => $day_id,
            'group_id'             => $group_id,
            'day_complete'         => $day_complete,
            'group_stage_complete' => $all_complete,
            'day_qualifiers'       => $champ['days'][$day_id]['qualifiers']   ?? array(),
            'ko_bracket'           => $champ['days'][$day_id]['ko_bracket']   ?? null,
            'ko_seeded'            => ! empty( $champ['days'][$day_id]['ko_bracket'] ),
        ) );
    }
}

// ── AJAX: get standings (polling) ─────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_get_standings',        'lgw_ajax_gchamp_get_standings' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_get_standings', 'lgw_ajax_gchamp_get_standings' );
function lgw_ajax_gchamp_get_standings() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    if ( ! $champ_id ) wp_send_json_error('Missing championship ID.');
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) ) wp_send_json_error('Championship not found.');
    $result = array();
    foreach ( ($champ['days']??array()) as $di=>$day ) {
        $ddata = array('day_id'=>$di,'day_complete'=>!empty($day['day_complete']),'qualifiers'=>$day['qualifiers']??array(),'groups'=>array());
        foreach ( ($day['groups']??array()) as $gi=>$group ) {
            $ddata['groups'][] = array('group_id'=>$gi,'standings'=>lgw_gchamp_compute_standings($group['entries']??array(),$group['fixtures']??array()),'played'=>lgw_gchamp_count_played($group['fixtures']??array()),'total'=>count($group['fixtures']??array()));
        }
        $result[] = $ddata;
    }
    wp_send_json_success( array('days'=>$result,'group_stage_complete'=>!empty($champ['group_stage_complete'])) );
}

// ── AJAX: seed knockout bracket ───────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_seed_knockout', 'lgw_ajax_gchamp_seed_knockout' );
function lgw_ajax_gchamp_seed_knockout() {
    check_ajax_referer( 'lgw_gchamp_draw', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorised');
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ    = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) )                         wp_send_json_error('Championship not found');
    if ( empty($champ['draw_complete']) )         wp_send_json_error('Draw not yet performed');
    if ( empty($champ['group_stage_complete']) )  wp_send_json_error('Group stage not yet complete');
    if ( empty($champ['has_ko_bracket']) )        wp_send_json_error('This championship does not use an internal knockout bracket');
    $result = lgw_gchamp_build_knockout( $champ_id, $champ );
    if ( is_wp_error($result) ) wp_send_json_error( $result->get_error_message() );
    wp_send_json_success( array('qualifiers'=>$champ['qualifiers'],'warnings'=>$result['warnings']??array()) );
}

// ── Per-day KO bracket helpers ───────────────────────────────────────────────

/**
 * Auto-seed the KO bracket for a single day from its group qualifiers.
 * Modifies $champ in place (does NOT call update_option — caller must save).
 */
function lgw_gchamp_auto_seed_day_ko( array &$champ, int $day_id ): void {
    $day      = &$champ['days'][ $day_id ];
    $qs       = $day['qualifiers'] ?? array();
    $total_q  = count( $qs );
    if ( $total_q < 2 ) return;

    // Determine bracket size: use configured value, or auto next-power-of-two
    $cfg_size = intval( $day['ko_bracket_size'] ?? 0 );
    if ( $cfg_size < $total_q || ! lgw_gchamp_is_power_of_two( $cfg_size ) ) {
        $cfg_size = lgw_gchamp_next_power_of_two( $total_q );
    }
    $byes = $cfg_size - $total_q;

    $numbered = array();
    foreach ( $qs as $i => $q ) $numbered[] = array( 'name' => $q, 'draw_num' => $i + 1 );
    for ( $b = 0; $b < $byes; $b++ ) $numbered[] = array( 'name' => null, 'draw_num' => $total_q + $b + 1 );

    // Build bracket rounds manually (simple seeding: 1v(n), 2v(n-1)…)
    $rounds = lgw_gchamp_build_simple_bracket( $numbered, $day['date'] ?? '' );
    $day['ko_bracket'] = array(
        'title'  => ( $champ['title'] ?? 'Championship' ) . ' — ' . ( $day['name'] ?? 'Day' ) . ' Knockout',
        'rounds' => $rounds,
        'size'   => $cfg_size,
    );
    $day['ko_qualifiers'] = array();
    $day['ko_complete']   = false;
}

/**
 * Build a simple single-elimination bracket from numbered entries.
 * Returns rounds array: [ ['round'=>0,'label'=>'QF','matches'=>[...]], ... ]
 * Matches: ['match'=>int,'round'=>int,'home'=>?string,'away'=>?string,'home_score'=>null,'away_score'=>null]
 */
function lgw_gchamp_build_simple_bracket( array $numbered, string $date = '' ): array {
    $n      = count( $numbered );
    $rounds = array();
    $size   = $n; // must be power of two

    // Standard seeding: 1 vs size, 2 vs size-1, …
    $pairs = array();
    for ( $i = 0; $i < $size / 2; $i++ ) {
        $a = $numbered[ $i ] ?? array( 'name' => null );
        $b = $numbered[ $size - 1 - $i ] ?? array( 'name' => null );
        $pairs[] = array( $a['name'], $b['name'] );
    }

    $round_labels = array( 2=>'Final', 4=>'Semi-final', 8=>'Quarter-final', 16=>'Round of 16', 32=>'Round of 32' );
    $num_rounds   = intval( log( $size, 2 ) );
    $match_idx    = 0;

    for ( $r = 0; $r < $num_rounds; $r++ ) {
        $matches_in_round = $size >> ( $r + 1 ); // size/2, size/4, …
        $label = $round_labels[ $matches_in_round * 2 ] ?? ( 'Round ' . ( $r + 1 ) );
        $matches = array();
        for ( $m = 0; $m < $matches_in_round; $m++ ) {
            $home = ( $r === 0 ) ? ( $pairs[ $m ][0] ?? null ) : null;
            $away = ( $r === 0 ) ? ( $pairs[ $m ][1] ?? null ) : null;
            // Advance byes immediately in round 0
            if ( $r === 0 && $home !== null && $away === null ) {
                // bye — home advances automatically; mark as played
                $matches[] = array(
                    'match' => $m, 'round' => $r, 'home' => $home, 'away' => null,
                    'home_score' => null, 'away_score' => null, 'bye' => true,
                );
            } else {
                $matches[] = array(
                    'match' => $m, 'round' => $r, 'home' => $home, 'away' => $away,
                    'home_score' => null, 'away_score' => null, 'bye' => false,
                );
            }
        }
        $rounds[] = array( 'round' => $r, 'label' => $label, 'date' => $date, 'matches' => $matches );
    }

    // Process first-round byes: advance winners to round 1
    foreach ( $rounds[0]['matches'] as $idx => $match ) {
        if ( ! empty( $match['bye'] ) && $match['home'] !== null ) {
            lgw_gchamp_advance_ko_winner_rounds( $rounds, 0, $match['match'], $match['home'] );
        }
    }

    return $rounds;
}

/**
 * Advance a KO winner from round $r, match $m to the next round.
 * Works on the flat rounds array passed by reference.
 */
function lgw_gchamp_advance_ko_winner_rounds( array &$rounds, int $r, int $m, string $winner ): void {
    $next_r    = $r + 1;
    $next_m    = intval( floor( $m / 2 ) );
    $is_home   = ( $m % 2 === 0 );
    foreach ( $rounds as &$round ) {
        if ( $round['round'] !== $next_r ) continue;
        foreach ( $round['matches'] as &$match ) {
            if ( $match['match'] !== $next_m ) continue;
            if ( $is_home ) $match['home'] = $winner;
            else            $match['away'] = $winner;
            return;
        }
        unset( $match );
    }
    unset( $round );
}

/**
 * Advance winner into the bracket stored inside $bracket (array with 'rounds' key).
 */
function lgw_gchamp_advance_ko_winner( array &$bracket, int $r, int $m, string $winner ): void {
    lgw_gchamp_advance_ko_winner_rounds( $bracket['rounds'], $r, $m, $winner );
}

/**
 * Return true if all non-bye KO matches have scores.
 */
function lgw_gchamp_ko_all_played( array $bracket ): bool {
    foreach ( $bracket['rounds'] as $round ) {
        foreach ( $round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home'] === null || $match['away'] === null ) continue; // TBD slot
            if ( $match['home_score'] === null || $match['away_score'] === null ) return false;
        }
    }
    return true;
}

/**
 * Compute Finals Week qualifiers from a completed KO bracket.
 * Rule: 1 day = SF (4), 2 days = F (2 each), 4 days = W (1 each).
 * Returns qualifiers in finish order.
 */
function lgw_gchamp_compute_ko_qualifiers( array $bracket, int $num_days ): array {
    // Determine how many qualifiers per day
    $per_day = 1;
    if ( $num_days === 1 )      $per_day = 4;
    elseif ( $num_days === 2 )  $per_day = 2;
    elseif ( $num_days >= 4 )   $per_day = 1;
    else                        $per_day = max( 1, intval( ceil( 4 / $num_days ) ) );

    // Work back from the final round
    $rounds     = $bracket['rounds'];
    $num_rounds = count( $rounds );
    $qualifiers = array();

    // Final round (last round) winner = champion
    $final_round = $rounds[ $num_rounds - 1 ] ?? null;
    if ( ! $final_round ) return array();

    foreach ( $final_round['matches'] as $match ) {
        if ( $match['home_score'] === null || $match['away_score'] === null ) continue;
        $hs = intval( $match['home_score'] );
        $as = intval( $match['away_score'] );
        $winner = $hs >= $as ? $match['home'] : $match['away'];
        $loser  = $hs >= $as ? $match['away'] : $match['home'];
        if ( $per_day >= 1 && $winner ) $qualifiers[] = $winner;
        if ( $per_day >= 2 && $loser  ) $qualifiers[] = $loser;
    }

    // Semi-final round losers (if per_day >= 4)
    if ( $per_day >= 4 && $num_rounds >= 2 ) {
        $sf_round = $rounds[ $num_rounds - 2 ];
        foreach ( $sf_round['matches'] as $match ) {
            if ( $match['home_score'] === null || $match['away_score'] === null ) continue;
            $hs    = intval( $match['home_score'] );
            $as    = intval( $match['away_score'] );
            $loser = $hs >= $as ? $match['away'] : $match['home'];
            if ( $loser ) $qualifiers[] = $loser;
        }
    }

    return array_values( array_filter( array_unique( $qualifiers ) ) );
}


// ── AJAX: toggle group lock ───────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_toggle_group_lock', 'lgw_ajax_gchamp_toggle_group_lock' );
function lgw_ajax_gchamp_toggle_group_lock() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id']   ?? -1 );
    $group_id = intval( $_POST['group_id'] ?? -1 );
    $lock     = $_POST['lock'] === '1';

    if ( ! $champ_id || $day_id < 0 || $group_id < 0 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['days'][$day_id]['groups'][$group_id] ) ) wp_send_json_error( 'Group not found.' );

    // Block unlock if KO bracket has any scores
    if ( ! $lock ) {
        $ko = $champ['days'][$day_id]['ko_bracket'] ?? null;
        if ( $ko ) {
            foreach ( $ko['rounds'] as $round ) {
                foreach ( $round['matches'] as $match ) {
                    if ( $match['home_score'] !== null || $match['away_score'] !== null ) {
                        wp_send_json_error( 'Cannot unlock: KO bracket has scores entered. Clear KO scores first.' );
                    }
                }
            }
        }
    }

    $champ['days'][$day_id]['groups'][$group_id]['locked'] = $lock;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'locked' => $lock ) );
}


// ── Finals Week — AJAX handlers ──────────────────────────────────────────────

add_action( 'wp_ajax_lgw_gchamp_seed_finals',        'lgw_ajax_gchamp_seed_finals' );
function lgw_ajax_gchamp_seed_finals() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ    = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found.' );

    $matches = lgw_gchamp_build_finals_matches( $champ );
    if ( empty( $matches ) ) wp_send_json_error( 'No qualifiers available yet.' );

    $champ['finals_matches'] = $matches;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'count' => count( $matches ) ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_datetime', 'lgw_ajax_gchamp_finals_save_datetime' );
function lgw_ajax_gchamp_finals_save_datetime() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id  = sanitize_key( $_POST['champ_id'] ?? '' );
    $match_idx = intval( $_POST['match_idx'] ?? -1 );
    $datetime  = sanitize_text_field( $_POST['datetime'] ?? '' );
    $rink      = sanitize_text_field( $_POST['rink']     ?? '' );
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    if ( $datetime && ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $datetime ) )
        wp_send_json_error( 'Invalid datetime format — use YYYY-MM-DD HH:MM.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $champ['finals_matches'][$match_idx]['finals_datetime'] = $datetime;
    $champ['finals_matches'][$match_idx]['finals_rink']     = $rink;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'formatted' => lgw_finals_format_datetime( $datetime ),
        'raw'       => $datetime,
        'rink'      => $rink,
    ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_end', 'lgw_ajax_gchamp_finals_save_end' );
function lgw_ajax_gchamp_finals_save_end() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id   = sanitize_key( $_POST['champ_id']  ?? '' );
    $match_idx  = intval( $_POST['match_idx']        ?? -1 );
    $action     = sanitize_key( $_POST['end_action'] ?? 'add' );
    $home_end   = intval( $_POST['home_end']         ?? 0 );
    $away_end   = intval( $_POST['away_end']         ?? 0 );
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $ends = $champ['finals_matches'][$match_idx]['ends'] ?? array();
    if ( $action === 'delete_last' ) { if ( ! empty( $ends ) ) array_pop( $ends ); }
    else $ends[] = array( max(0,$home_end), max(0,$away_end) );
    $champ['finals_matches'][$match_idx]['ends'] = $ends;
    $ht = 0; $at = 0;
    foreach ( $ends as $e ) { $ht += $e[0]; $at += $e[1]; }
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'ends'=>$ends, 'homeTotal'=>$ht, 'awayTotal'=>$at, 'endCount'=>count($ends) ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_score', 'lgw_ajax_gchamp_finals_save_score' );
function lgw_ajax_gchamp_finals_save_score() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id   = sanitize_key( $_POST['champ_id']  ?? '' );
    $match_idx  = intval( $_POST['match_idx']        ?? -1 );
    $hs         = $_POST['home_score'] !== '' ? intval( $_POST['home_score'] ) : null;
    $as         = $_POST['away_score'] !== '' ? intval( $_POST['away_score'] ) : null;
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $champ['finals_matches'][$match_idx]['home_score'] = $hs;
    $champ['finals_matches'][$match_idx]['away_score'] = $as;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'homeScore'=>$hs, 'awayScore'=>$as ) );
}

// ── Build Finals Week match list from ko_qualifiers across all days ────────────
function lgw_gchamp_build_finals_matches( array $champ ): array {
    // Collect all confirmed ko_qualifiers in day order
    $all_qs = array();
    foreach ( $champ['days'] ?? array() as $day ) {
        foreach ( $day['ko_qualifiers'] ?? array() as $q ) {
            $all_qs[] = $q;
        }
    }
    if ( count( $all_qs ) < 2 ) return array();

    $n        = count( $all_qs );
    $num_days = count( $champ['days'] ?? array() );

    // Determine round structure from number of qualifiers
    // 4 qualifiers → SF + Final; 2 → Final only; other → round of n
    $matches = array();

    if ( $n === 4 ) {
        // Semi-finals: 1v4, 2v3 → Final
        $matches[] = array( 'round'=>'Semi-final', 'match_num'=>1, 'home'=>$all_qs[0], 'away'=>$all_qs[3], 'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'' );
        $matches[] = array( 'round'=>'Semi-final', 'match_num'=>2, 'home'=>$all_qs[1], 'away'=>$all_qs[2], 'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'' );
        $matches[] = array( 'round'=>'Final',      'match_num'=>1, 'home'=>null,        'away'=>null,        'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'', 'prev_sf'=>[0,1] );
    } elseif ( $n === 2 ) {
        $matches[] = array( 'round'=>'Final', 'match_num'=>1, 'home'=>$all_qs[0], 'away'=>$all_qs[1], 'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'' );
    } else {
        // Generic: pair them up sequentially, last match is the final
        $round_name = $n <= 4 ? 'Semi-final' : 'Quarter-final';
        for ( $i = 0; $i < $n - 1; $i += 2 ) {
            $matches[] = array( 'round'=>$round_name, 'match_num'=>intval($i/2)+1, 'home'=>$all_qs[$i], 'away'=>$all_qs[$i+1]??null, 'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'' );
        }
        $matches[] = array( 'round'=>'Final', 'match_num'=>1, 'home'=>null, 'away'=>null, 'home_score'=>null, 'away_score'=>null, 'ends'=>array(), 'finals_datetime'=>'', 'finals_rink'=>'' );
    }

    return $matches;
}

// ── Propagate SF winners into the Final slot ──────────────────────────────────
function lgw_gchamp_finals_propagate( array &$champ ): void {
    $matches = &$champ['finals_matches'];
    if ( empty( $matches ) ) return;
    // Find the Final match (last one or round=='Final')
    $final_idx = null;
    foreach ( $matches as $i => $m ) {
        if ( $m['round'] === 'Final' ) $final_idx = $i;
    }
    if ( $final_idx === null ) return;
    // Find SF winners
    $sf_winners = array();
    foreach ( $matches as $i => $m ) {
        if ( $m['round'] !== 'Semi-final' ) continue;
        $hs = $m['home_score']; $as = $m['away_score'];
        if ( $hs !== null && $as !== null && $hs !== $as ) {
            $sf_winners[] = $hs > $as ? $m['home'] : $m['away'];
        }
    }
    if ( isset( $sf_winners[0] ) ) $matches[$final_idx]['home'] = $sf_winners[0];
    if ( isset( $sf_winners[1] ) ) $matches[$final_idx]['away'] = $sf_winners[1];
}


// ── AJAX: seed a single day's KO bracket ─────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_seed_day_ko',        'lgw_ajax_gchamp_seed_day_ko' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_seed_day_ko', 'lgw_ajax_gchamp_seed_day_ko' );
function lgw_ajax_gchamp_seed_day_ko() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id'] ?? -1 );
    if ( ! $champ_id || $day_id < 0 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                           wp_send_json_error( 'Championship not found.' );
    if ( ! isset( $champ['days'][$day_id] ) )        wp_send_json_error( 'Day not found.' );
    if ( empty( $champ['days'][$day_id]['day_complete'] ) ) wp_send_json_error( 'Day group stage not yet complete.' );

    // Force re-compute qualifiers then seed
    $champ['days'][$day_id]['qualifiers'] = lgw_gchamp_compute_day_qualifiers( $champ['days'][$day_id] );
    lgw_gchamp_auto_seed_day_ko( $champ, $day_id );

    if ( empty( $champ['days'][$day_id]['ko_bracket'] ) ) {
        wp_send_json_error( 'Could not seed bracket — check that qualifiers exist for this day.' );
    }

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'day_id'     => $day_id,
        'qualifiers' => $champ['days'][$day_id]['qualifiers'],
    ) );
}


// ── Draw algorithm ────────────────────────────────────────────────────────────

/**
 * Main draw: distribute entries across days (respecting preferences),
 * then within each day distribute into groups (club cap 50%).
 * Number of groups per day = ceil(day_entries / target_group_size).
 */
function lgw_gchamp_run_draw( array $entries, array $days_config, array $entry_prefs ) {
    $n        = count($entries);
    $num_days = count($days_config);
    $warnings = array();
    if ( $n < $num_days ) return new WP_Error('too_few','Fewer entries ('.$n.') than days ('.$num_days.').');

    // Step 1: target entries per day (even split)
    $day_sizes = lgw_gchamp_compute_group_sizes( $n, $num_days );

    // Step 2: map dates to day indices, compute date capacity
    $date_to_days = array();
    foreach ( $days_config as $di=>$day ) {
        $d = $day['date']??'';
        if ( $d!=='' ) $date_to_days[$d][] = $di;
    }
    $date_capacity = array();
    foreach ( $date_to_days as $d=>$didxs ) {
        $cap=0; foreach($didxs as $di) $cap+=$day_sizes[$di];
        $date_capacity[$d]=$cap;
    }

    // Step 3: allocate entries to dates (preference-aware)
    $pool = $entries; shuffle($pool);
    $date_buckets=array(); $unplaced=array();
    foreach ($pool as $entry) {
        $pref = $entry_prefs[$entry]??'';
        if ($pref!==''&&isset($date_capacity[$pref])) $date_buckets[$pref][]=$entry;
        else $unplaced[]=$entry;
    }
    $date_placed=array();
    foreach ($date_buckets as $d=>$preferred) {
        $cap=$date_capacity[$d]; shuffle($preferred);
        if (count($preferred)<=$cap) { $date_placed[$d]=$preferred; }
        else {
            $over=count($preferred)-$cap;
            $warnings[]='Day '.$d.' oversubscribed ('.count($preferred).' preferences, '.$cap.' slots) — '.$over.' entr'.($over===1?'y':'ies').' placed randomly.';
            $date_placed[$d]=array_slice($preferred,0,$cap);
            $unplaced=array_merge($unplaced,array_slice($preferred,$cap));
        }
    }
    shuffle($unplaced);
    foreach ($date_to_days as $d=>$didxs) {
        $already=count($date_placed[$d]??array()); $need=($date_capacity[$d]??0)-$already;
        if ($need>0&&!empty($unplaced)) { $take=array_splice($unplaced,0,$need); $date_placed[$d]=array_merge($date_placed[$d]??array(),$take); }
    }
    if (!empty($unplaced)) {
        $warnings[]=count($unplaced).' entr'.(count($unplaced)===1?'y':'ies').' could not be matched to a dated day and were placed randomly.';
        foreach ($unplaced as $idx=>$entry) {
            $di=$idx%$num_days; $d=$days_config[$di]['date']??'__none__'; $date_placed[$d][]=$entry;
        }
    }

    // Distribute entries to each day using the same distribute helper
    $day_entries_pool=array_fill(0,$num_days,array());
    foreach ($date_to_days as $d=>$didxs) {
        $pfd=$date_placed[$d]??array(); shuffle($pfd);
        lgw_gchamp_distribute_to_groups($pfd,$didxs,$day_sizes,$day_entries_pool,$warnings);
    }
    if (!empty($date_placed['__none__'])) {
        lgw_gchamp_distribute_to_groups($date_placed['__none__'],range(0,$num_days-1),$day_sizes,$day_entries_pool,$warnings);
    }

    // Step 4: within each day split into groups
    $days=array();
    foreach ($days_config as $di=>$day_cfg) {
        $day_pool=$day_entries_pool[$di]; shuffle($day_pool);
        $tgs=max(2,intval($day_cfg['target_group_size']??4));
        $n_day=count($day_pool);
        $num_groups=max(1,(int)ceil($n_day/$tgs));
        $group_sizes=lgw_gchamp_compute_group_sizes($n_day,$num_groups);
        $group_entries_arr=array_fill(0,$num_groups,array());
        lgw_gchamp_distribute_to_groups($day_pool,range(0,$num_groups-1),$group_sizes,$group_entries_arr,$warnings);
        $groups=array();
        foreach ($group_entries_arr as $gi=>$g_entries) {
            $g_name=($day_cfg['name']??('Day '.($di+1))).' — Group '.chr(65+$gi);
            $fixtures=lgw_gchamp_generate_fixtures($di*100+$gi,$g_entries,1);
            $groups[]=array('id'=>$gi,'name'=>$g_name,'entries'=>$g_entries,'fixtures'=>$fixtures);
        }
        $days[]=array(
            'id'                => $di,
            'name'              => $day_cfg['name']              ??('Day '.($di+1)),
            'date'              => $day_cfg['date']              ??'',
            'target_group_size' => $tgs,
            'winners_per_group' => intval($day_cfg['winners_per_group']??1),
            'best_runners_up'   => intval($day_cfg['best_runners_up']  ??0),
            'ko_bracket_size'   => intval($day_cfg['ko_bracket_size']  ??0),
            'entries'           => $day_pool,
            'groups'            => $groups,
            'qualifiers'        => array(),
            'day_complete'      => false,
            'ko_bracket'        => null,
            'ko_qualifiers'     => array(),
            'ko_complete'       => false,
        );
    }
    return array('days'=>$days,'warnings'=>$warnings);
}

// ── Per-day qualification ─────────────────────────────────────────────────────

function lgw_gchamp_compute_day_qualifiers( array $day ): array {
    $wpg=$intval=intval($day['winners_per_group']??1);
    $bru=intval($day['best_runners_up']??0);
    $auto=array(); $pool=array();
    foreach ($day['groups']??array() as $gi=>$group) {
        $standings=lgw_gchamp_compute_standings($group['entries']??array(),$group['fixtures']??array());
        foreach ($standings as $pos=>$row) {
            $aug=array_merge($row,array('group_id'=>$gi,'diff'=>$row['sf']-$row['sa']));
            if ($pos<$wpg) $auto[]=$aug; else $pool[]=$aug;
        }
    }
    usort($auto,'lgw_gchamp_sort_qualifier');
    $best=array();
    if ($bru>0&&!empty($pool)) {
        usort($pool,'lgw_gchamp_sort_qualifier');
        $best=array_slice($pool,0,min($bru,count($pool)));
    }
    return array_map(fn($q)=>$q['entry'],array_merge($auto,$best));
}

function lgw_gchamp_day_fixtures_all_played( array $day ): bool {
    foreach ($day['groups']??array() as $group) {
        foreach ($group['fixtures']??array() as $fx) {
            if ($fx['home_score']===null||$fx['away_score']===null) return false;
        }
    }
    return true;
}

// ── Knockout bracket seeding ──────────────────────────────────────────────────

function lgw_gchamp_build_knockout( string $champ_id, array &$champ ) {
    $ko_size=$intval=intval($champ['ko_bracket_size']??0);
    $dates=$champ['dates']??array(); $warnings=array();
    $all_qualifiers=array();
    foreach ($champ['days']??array() as $day) foreach ($day['qualifiers']??array() as $q) $all_qualifiers[]=$q;
    $total_q=count($all_qualifiers);
    if ($total_q<2) return new WP_Error('too_few','Fewer than 2 qualifiers across all days.');
    if ($ko_size<$total_q||!lgw_gchamp_is_power_of_two($ko_size)) {
        $ko_size=lgw_gchamp_next_power_of_two($total_q);
        $warnings[]='Bracket size set to '.$ko_size.' to fit '.$total_q.' qualifiers.';
    }
    $byes=$ko_size-$total_q;
    $numbered=array();
    foreach ($all_qualifiers as $i=>$q) $numbered[]=array('name'=>$q,'draw_num'=>$i+1);
    for ($b=0;$b<$byes;$b++) $numbered[]=array('name'=>null,'draw_num'=>$total_q+$b+1);
    if (!function_exists('lgw_draw_build_bracket')) return new WP_Error('missing_dep','lgw_draw_build_bracket() not available.');
    $result=lgw_draw_build_bracket($numbered,array(
        'get_club'=>'lgw_gchamp_entry_club','home_at_limit'=>function($c,$cnt){return false;},
        'separate_prelim'=>'lgw_champ_separate_clubs','separate_r2'=>'lgw_champ_separate_r2_slots',
        'stored_rounds'=>lgw_draw_default_rounds($ko_size),'dates'=>$dates,'r2_label'=>'Knockout Draw','game_nums'=>true,
    ));
    if (!$result) return new WP_Error('bracket_fail','Bracket build failed.');
    $champ['ko_bracket']=array('title'=>($champ['title']??'Championship').' — Knockout Stage','rounds'=>$result['rounds'],'dates'=>$dates,'matches'=>$result['matches']);
    $champ['qualifiers']=$all_qualifiers;
    if (strlen(serialize($champ))>800000) return new WP_Error('too_large','Bracket data exceeds safe storage limit.');
    update_option('lgw_gchamp_'.$champ_id,$champ);
    return array('warnings'=>$warnings);
}


// ── Standings computation ──────────────────────────────────────────────────────

/**
 * Compute group standings from entries and fixtures.
 * Returns array sorted by: pts desc, diff desc, sf desc, entry name asc.
 * Head-to-head tiebreak (spec §5.2) is applied when pts AND diff are equal.
 *
 * Each row: [ entry, p, w, d, l, sf, sa, pts ]
 */
function lgw_gchamp_compute_standings( array $entries, array $fixtures ): array {
    // Initialise
    $rows = array();
    foreach ( $entries as $e ) {
        $rows[ $e ] = array( 'entry' => $e, 'p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'sf' => 0, 'sa' => 0, 'pts' => 0 );
    }

    // Accumulate results
    foreach ( $fixtures as $fx ) {
        $hs = $fx['home_score'];
        $as = $fx['away_score'];
        if ( $hs === null || $as === null ) continue;
        $hs = intval( $hs );
        $as = intval( $as );
        $h  = $fx['home'];
        $a  = $fx['away'];
        if ( ! isset( $rows[ $h ] ) || ! isset( $rows[ $a ] ) ) continue;

        $rows[ $h ]['p']++;
        $rows[ $a ]['p']++;
        $rows[ $h ]['sf'] += $hs; $rows[ $h ]['sa'] += $as;
        $rows[ $a ]['sf'] += $as; $rows[ $a ]['sa'] += $hs;

        if ( $hs > $as ) {
            $rows[ $h ]['w']++;   $rows[ $h ]['pts'] += 2;
            $rows[ $a ]['l']++;
        } elseif ( $as > $hs ) {
            $rows[ $a ]['w']++;   $rows[ $a ]['pts'] += 2;
            $rows[ $h ]['l']++;
        } else {
            $rows[ $h ]['d']++;   $rows[ $h ]['pts']++;
            $rows[ $a ]['d']++;   $rows[ $a ]['pts']++;
        }
    }

    $sorted = array_values( $rows );

    // Sort: pts desc → diff desc → h2h → sf desc → name asc
    usort( $sorted, function( $a, $b ) use ( $fixtures ) {
        if ( $b['pts'] !== $a['pts'] ) return $b['pts'] - $a['pts'];
        $diff_a = $a['sf'] - $a['sa'];
        $diff_b = $b['sf'] - $b['sa'];
        if ( $diff_b !== $diff_a ) return $diff_b - $diff_a;
        // Head-to-head
        $h2h = lgw_gchamp_h2h_compare( $a['entry'], $b['entry'], $fixtures );
        if ( $h2h !== 0 ) return $h2h;
        if ( $b['sf'] !== $a['sf'] ) return $b['sf'] - $a['sf'];
        return strcmp( $a['entry'], $b['entry'] );
    } );

    return $sorted;
}

/**
 * Compare two entries by their head-to-head record in the given fixture list.
 * Returns -1 if $entry_a is ahead, +1 if $entry_b is ahead, 0 if equal/not played.
 */
function lgw_gchamp_h2h_compare( string $entry_a, string $entry_b, array $fixtures ): int {
    $pts_a = 0;
    $pts_b = 0;
    foreach ( $fixtures as $fx ) {
        $hs = $fx['home_score'];
        $as = $fx['away_score'];
        if ( $hs === null || $as === null ) continue;
        $hs = intval( $hs );
        $as = intval( $as );
        if ( $fx['home'] === $entry_a && $fx['away'] === $entry_b ) {
            if ( $hs > $as ) $pts_a += 2;
            elseif ( $as > $hs ) $pts_b += 2;
            else { $pts_a++; $pts_b++; }
        } elseif ( $fx['home'] === $entry_b && $fx['away'] === $entry_a ) {
            if ( $hs > $as ) $pts_b += 2;
            elseif ( $as > $hs ) $pts_a += 2;
            else { $pts_a++; $pts_b++; }
        }
    }
    if ( $pts_a > $pts_b ) return -1;
    if ( $pts_b > $pts_a ) return 1;
    return 0;
}

/**
 * Count the number of played fixtures (both scores set).
 */
function lgw_gchamp_count_played( array $fixtures ): int {
    $count = 0;
    foreach ( $fixtures as $fx ) {
        if ( $fx['home_score'] !== null && $fx['away_score'] !== null ) $count++;
    }
    return $count;
}

/**
 * Return true if every fixture in every group has both scores set.
 */
function lgw_gchamp_all_fixtures_played( array $groups ): bool {
    foreach ( $groups as $group ) {
        foreach ( ( $group['fixtures'] ?? array() ) as $fx ) {
            if ( $fx['home_score'] === null || $fx['away_score'] === null ) return false;
        }
    }
    return true;
}

/**
 * Return the player-name part of an entry string (before the last comma).
 * Used for compact display in the standings and fixture rows.
 */
function lgw_gchamp_short_name( string $entry ): string {
    $parts = array_map( 'trim', explode( ',', $entry, 2 ) );
    return $parts[0] ?? $entry;
}


// ── Pure helper functions ────────────────────────────────────────────────────────

function lgw_gchamp_count_club_in_group( string $club, array $group_entries ): int {
    $count = 0;
    foreach ( $group_entries as $e ) {
        if ( lgw_gchamp_entry_club( $e ) === $club ) $count++;
    }
    return $count;
}

function lgw_gchamp_entry_club( string $entry ): string {
    $parts = array_map( 'trim', explode( ',', $entry, 2 ) );
    return strtolower( $parts[1] ?? '' );
}


function lgw_gchamp_sort_qualifier( array $a, array $b ): int {
    if ( $b['pts']  !== $a['pts']  ) return $b['pts']  - $a['pts'];
    if ( $b['diff'] !== $a['diff'] ) return $b['diff'] - $a['diff'];
    if ( $b['sf']   !== $a['sf']   ) return $b['sf']   - $a['sf'];
    return strcmp( $a['entry'], $b['entry'] );
}

function lgw_gchamp_distribute_to_groups(
    array  $pool,
    array  $group_indices,
    array  $sizes,
    array &$group_entries,
    array &$warnings
) {
    $max_passes = count( $pool ) * 2 + 2;
    $unplaced   = $pool;

    for ( $pass = 0; $pass < $max_passes && ! empty( $unplaced ); $pass++ ) {
        $still_unplaced = array();

        foreach ( $unplaced as $entry ) {
            $club   = lgw_gchamp_entry_club( $entry );
            $placed = false;
            $order  = $group_indices;
            shuffle( $order );

            // Try clean placement: capacity + club cap both respected
            foreach ( $order as $gi ) {
                if ( count( $group_entries[ $gi ] ) >= $sizes[ $gi ] ) continue;
                $club_count = lgw_gchamp_count_club_in_group( $club, $group_entries[ $gi ] );
                $cap_limit  = max( 1, (int) ceil( $sizes[ $gi ] * 0.5 ) );
                if ( $club_count < $cap_limit ) {
                    $group_entries[ $gi ][] = $entry;
                    $placed = true;
                    break;
                }
            }

            if ( ! $placed ) {
                $still_unplaced[] = $entry;
            }
        }

        // No progress this pass — fall back to capacity-only
        if ( count( $still_unplaced ) === count( $unplaced ) ) {
            $cap_violated = false;
            foreach ( $unplaced as $entry ) {
                $placed = false;
                $order  = $group_indices;
                shuffle( $order );
                foreach ( $order as $gi ) {
                    if ( count( $group_entries[ $gi ] ) < $sizes[ $gi ] ) {
                        $group_entries[ $gi ][] = $entry;
                        $placed = true;
                        $cap_violated = true;
                        break;
                    }
                }
                if ( ! $placed ) {
                    $warnings[] = 'Could not place entry "' . $entry . '" — check group configuration.';
                }
            }
            if ( $cap_violated ) {
                $warnings[] = 'Club cap (50%) could not be fully respected for all groups — some entries were placed by capacity only.';
            }
            break;
        }

        $unplaced = $still_unplaced;
    }
}

function lgw_gchamp_generate_fixtures( int $group_id, array $entries, int $games_per_opp = 1 ): array {
    $n        = count( $entries );
    $fixtures = array();
    if ( $n < 2 ) return $fixtures;

    $has_bye = ( $n % 2 !== 0 );
    $wheel   = $entries;
    if ( $has_bye ) $wheel[] = '__BYE__';
    $m = count( $wheel );

    $num_rounds = $m - 1;
    $fixed      = $wheel[0];
    $fix_idx    = 0;

    for ( $rep = 0; $rep < $games_per_opp; $rep++ ) {
        $rotating = array_slice( $wheel, 1 );

        for ( $round = 0; $round < $num_rounds; $round++ ) {
            $current_wheel = array_merge( array( $fixed ), $rotating );

            for ( $slot = 0; $slot < $m / 2; $slot++ ) {
                $home_entry = $current_wheel[ $slot ];
                $away_entry = $current_wheel[ $m - 1 - $slot ];

                if ( $home_entry === '__BYE__' || $away_entry === '__BYE__' ) continue;

                // Reverse home/away on even-numbered repetitions
                if ( $rep % 2 === 1 ) {
                    list( $home_entry, $away_entry ) = array( $away_entry, $home_entry );
                }

                $global_round = $rep * $num_rounds + $round;
                $pos_key      = 'g' . $group_id . ':r' . $global_round . ':f' . $fix_idx;

                $fixtures[] = array(
                    'round'      => $global_round,
                    'home'       => $home_entry,
                    'away'       => $away_entry,
                    'home_score' => null,
                    'away_score' => null,
                    'pos_key'    => $pos_key,
                );
                $fix_idx++;
            }

            // Rotate: move last element of rotating to front
            array_unshift( $rotating, array_pop( $rotating ) );
        }
    }

    return $fixtures;
}

function lgw_gchamp_has_any_scores( array $champ ): bool {
    foreach ( ( $champ['days'] ?? array() ) as $day ) {
        foreach ( ( $day['groups'] ?? array() ) as $group ) {
            foreach ( ( $group['fixtures'] ?? array() ) as $fixture ) {
                if ( $fixture['home_score'] !== null || $fixture['away_score'] !== null ) {
                    return true;
                }
            }
        }
    }
    return false;
}

function lgw_gchamp_is_power_of_two( int $n ): bool {
    return $n > 0 && ( $n & ( $n - 1 ) ) === 0;
}

function lgw_gchamp_next_power_of_two( int $n ): int {
    if ( $n <= 1 ) return 2;
    $p = 1;
    while ( $p < $n ) $p <<= 1;
    return $p;
}

function lgw_gchamp_compute_group_sizes( int $total_entries, int $num_groups ): array {
    if ( $num_groups <= 0 ) return array();
    $base      = intdiv( $total_entries, $num_groups );
    $remainder = $total_entries % $num_groups;
    $sizes     = array();
    for ( $i = 0; $i < $num_groups; $i++ ) {
        $sizes[] = $base + ( $i < $remainder ? 1 : 0 );
    }
    return $sizes;
}

// ── Asset enqueue ─────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'lgw_gchamp_enqueue' );
function lgw_gchamp_enqueue() {
    global $post;
    if ( ! is_singular() || ! is_a( $post, 'WP_Post' ) ) return;
    $content = $post->post_content . ' ' . get_the_content( null, false, $post );
    if ( ! has_shortcode( $content, 'lgw_gchamp' ) ) return;

    wp_enqueue_style( 'lgw-saira',   'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null );
    wp_enqueue_style( 'lgw-widget',  plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-widget.css',  array( 'lgw-saira' ),   LGW_VERSION );
    wp_enqueue_style( 'lgw-champ',   plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-champ.css',   array( 'lgw-widget' ),  LGW_VERSION );
    wp_enqueue_style( 'lgw-gchamp',  plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-gchamp.css',  array( 'lgw-champ' ),   LGW_VERSION );

    if ( ! wp_script_is( 'lgw-scorecard', 'registered' ) ) {
        wp_register_script( 'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.js', array(), LGW_VERSION, true );
        wp_register_style(  'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.css', array(), LGW_VERSION );
    }
    if ( ! wp_script_is( 'lgw-scorecard', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-scorecard' );
        wp_enqueue_style(  'lgw-scorecard' );
    }

    // lgw-champ.js handles the knockout bracket rendering (reuses lgw-champ-wrap)
    if ( ! wp_script_is( 'lgw-champ', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-champ', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-champ.js', array( 'lgw-scorecard' ), LGW_VERSION, true );
        if ( ! wp_script_is( 'lgw-widget', 'enqueued' ) ) {
            wp_localize_script( 'lgw-champ', 'lgwChampData', array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'isAdmin'    => current_user_can( 'manage_options' ) ? 1 : 0,
                'scoreNonce' => wp_create_nonce( 'lgw_champ_score' ),
                'champNonce' => wp_create_nonce( 'lgw_champ_nonce' ),
                'badges'     => get_option( 'lgw_badges',      array() ),
                'clubBadges' => get_option( 'lgw_club_badges', array() ),
            ) );
        }
    }
    wp_enqueue_style(  'lgw-finals', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-finals.css', array( 'lgw-widget' ), LGW_VERSION );
    if ( ! wp_script_is( 'lgw-finals', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-finals', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-finals.js', array(), LGW_VERSION, true );
    }
    wp_enqueue_script( 'lgw-gchamp', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-gchamp.js', array( 'lgw-champ', 'lgw-finals' ), LGW_VERSION, true );

    // Provide badge + AJAX data to front-end JS
    if ( ! wp_script_is( 'lgw-widget', 'enqueued' ) ) {
        wp_localize_script( 'lgw-gchamp', 'lgwData', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'badges'     => get_option( 'lgw_badges',      array() ),
            'clubBadges' => get_option( 'lgw_club_badges', array() ),
        ) );
    }
    wp_localize_script( 'lgw-gchamp', 'lgwGchampData', array(
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'isAdmin'    => current_user_can( 'manage_options' ) ? 1 : 0,
        'canScore'   => ( function_exists( 'lgw_user_can_manage_scores' ) && lgw_user_can_manage_scores() ) ? 1 : 0,
        'nonce'      => wp_create_nonce( 'lgw_gchamp_score' ),
        'badges'     => get_option( 'lgw_badges',      array() ),
        'clubBadges' => get_option( 'lgw_club_badges', array() ),
    ) );
}

// ── Shortcode ─────────────────────────────────────────────────────────────────
add_shortcode( 'lgw_gchamp', 'lgw_gchamp_shortcode' );
function lgw_gchamp_shortcode( $atts ) {
    $atts      = shortcode_atts( array( 'id' => '' ), $atts );
    $gchamp_id = sanitize_key( $atts['id'] );
    if ( ! $gchamp_id ) return '<p><em>[lgw_gchamp]: no id specified.</em></p>';

    $champ = get_option( 'lgw_gchamp_' . $gchamp_id, array() );
    if ( empty( $champ ) ) return '<p><em>[lgw_gchamp]: championship "' . esc_html( $gchamp_id ) . '" not found.</em></p>';

    $title       = $champ['title']         ?? $gchamp_id;
    $drawn       = ! empty( $champ['draw_complete'] );
    $gs_complete = ! empty( $champ['group_stage_complete'] );
    $warnings    = $champ['draw_warnings'] ?? array();
    $num_entries = count( $champ['entries'] ?? array() );
    $days_config = $champ['days_config']   ?? array();
    $days        = $champ['days']          ?? array();
    $can_score   = current_user_can( 'edit_posts' );
    $num_days    = count( $days );

    ob_start();
    ?>
    <?php
    $colour     = $champ['colour_scheme'] ?? '#c0202a';
    $tab_colour = $champ['tab_colour']    ?? '#e8b400';
    ?>
    <div class="lgw-gchamp-wrap" data-gchamp-id="<?php echo esc_attr( $gchamp_id ); ?>"
         style="--lgw-accent:<?php echo esc_attr($colour); ?>;--lgw-header-bg:<?php echo esc_attr($colour); ?>;--lgw-gold:<?php echo esc_attr($tab_colour); ?>">

        <div class="lgw-gchamp-header">
            <div>
                <div class="lgw-gchamp-title"><?php echo esc_html( $title ); ?></div>
                <div class="lgw-gchamp-subtitle">
                    <?php echo esc_html( $num_entries ); ?> entries &middot;
                    <?php echo count( $days_config ); ?> day<?php echo count( $days_config ) !== 1 ? 's' : ''; ?>
                </div>
            </div>
            <span class="lgw-gchamp-header-badge">Group + KO</span>
        </div>

        <?php if ( ! empty( $warnings ) ): ?>
        <div class="lgw-gchamp-warnings">
            &#x26A0; <strong>Draw notes:</strong>
            <ul><?php foreach ( $warnings as $w ): ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if ( ! $drawn ): ?>
        <div class="lgw-gchamp-empty">
            <div class="lgw-gchamp-empty-icon">&#x1F3B2;</div>
            <p>The group draw has not taken place yet. Check back soon!</p>
        </div>
        <?php else: ?>

        <?php /* ── Top-level day tabs ── */ ?>
        <?php
        // Show finals tab as soon as any day's KO is complete
        $has_any_ko_complete = false;
        foreach ( $days as $d ) { if ( ! empty( $d['ko_complete'] ) ) { $has_any_ko_complete = true; break; } }
        $finals_matches = $champ['finals_matches'] ?? array();
        $all_ko_complete = true;
        foreach ( $days as $d ) { if ( empty( $d['ko_complete'] ) ) { $all_ko_complete = false; break; } }
        ?>
        <div class="lgw-gchamp-day-tabs" role="tablist">
            <?php foreach ( $days as $day_idx => $day ): ?>
            <button class="lgw-gchamp-day-tab<?php echo $day_idx === 0 ? ' active' : ''; ?>"
                    data-day-tab="<?php echo $day_idx; ?>"
                    role="tab"
                    aria-selected="<?php echo $day_idx === 0 ? 'true' : 'false'; ?>">
                <?php echo esc_html( $day['name'] ); ?>
                <?php if ( ! empty( $day['ko_complete'] ) ): ?>
                    <span class="lgw-gchamp-day-tab-badge">&#x2713;</span>
                <?php elseif ( ! empty( $day['day_complete'] ) ): ?>
                    <span class="lgw-gchamp-day-tab-badge lgw-gchamp-day-tab-badge-groups">G</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
            <?php if ( $has_any_ko_complete ): ?>
            <button class="lgw-gchamp-day-tab<?php echo $all_ko_complete && !empty($finals_matches) ? '' : ''; ?>"
                    data-day-tab="finals"
                    role="tab"
                    aria-selected="false">
                &#x1F3C6; Finals Week
                <?php if ( $all_ko_complete ): ?>
                    <span class="lgw-gchamp-day-tab-badge">&#x2713;</span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
        </div>

        <?php foreach ( $days as $day_idx => $day ):
            $wpg          = intval( $day['winners_per_group'] ?? 1 );
            $bru          = intval( $day['best_runners_up']   ?? 0 );
            $day_complete = ! empty( $day['day_complete'] );
            $ko_bracket   = $day['ko_bracket']   ?? null;
            $ko_complete  = ! empty( $day['ko_complete'] );
            $ko_quals     = $day['ko_qualifiers'] ?? array();
        ?>
        <div class="lgw-gchamp-day-pane<?php echo $day_idx === 0 ? ' active' : ''; ?>"
             data-day-pane="<?php echo $day_idx; ?>"
             data-seed-needed="<?php echo ($day_complete && empty($day['ko_bracket'])) ? '1' : '0'; ?>">

            <?php /* ── Sub-tabs: Groups | Knockout ── */ ?>
            <div class="lgw-gchamp-sub-tabs">
                <button class="lgw-gchamp-sub-tab active" data-sub-pane="groups">
                    &#x26BD; Groups
                </button>
                <button class="lgw-gchamp-sub-tab<?php echo $day_complete ? '' : ' locked'; ?>"
                        data-sub-pane="knockout">
                    &#x1F3C6; Knockout
                    <?php if ( $ko_complete ): ?><span class="lgw-gchamp-sub-tab-done">&#x2713;</span><?php endif; ?>
                </button>
            </div>

            <?php /* ── Groups sub-pane ── */ ?>
            <div class="lgw-gchamp-sub-pane active" data-sub-pane="groups">

                <?php if ( ! empty( $day['date'] ) ): ?>
                <div class="lgw-gchamp-day-date-bar">&#x1F4C5; <?php echo esc_html( $day['date'] ); ?></div>
                <?php endif; ?>

                <div class="lgw-gchamp-groups-grid">
                <?php foreach ( ( $day['groups'] ?? array() ) as $group ):
                    $g_entries  = $group['entries']  ?? array();
                    $g_fixtures = $group['fixtures']  ?? array();
                    $g_name     = $group['name']      ?? '';
                    $standings  = lgw_gchamp_compute_standings( $g_entries, $g_fixtures );
                    $n_played   = lgw_gchamp_count_played( $g_fixtures );
                    $n_total    = count( $g_fixtures );
                    $g_parts    = explode( ' — ', $g_name, 2 );
                    $g_label    = count( $g_parts ) > 1 ? $g_parts[1] : $g_name;
                ?>
                <div class="lgw-gchamp-group-card">
                    <?php
                    $group_locked = ! empty( $group['locked'] );
                    // KO has scores = can't unlock
                    $ko_has_scores = false;
                    if ( ! empty( $day['ko_bracket'] ) ) {
                        foreach ( ($day['ko_bracket']['rounds'] ?? array()) as $rnd ) {
                            foreach ( ($rnd['matches'] ?? array()) as $mch ) {
                                if ( $mch['home_score'] !== null || $mch['away_score'] !== null ) { $ko_has_scores = true; break 2; }
                            }
                        }
                    }
                    ?>
                    <div class="lgw-gchamp-group-header">
                        <span class="lgw-gchamp-group-name"><?php echo esc_html( $g_label ); ?></span>
                        <span class="lgw-gchamp-group-progress"><?php echo $n_played; ?>/<?php echo $n_total; ?></span>
                        <?php if ( current_user_can('manage_options') && $day_complete ): ?>
                        <button type="button"
                                class="lgw-gchamp-group-lock-btn"
                                data-day-id="<?php echo $day_idx; ?>"
                                data-group-id="<?php echo $group['id'] ?? $gi; ?>"
                                data-locked="<?php echo $group_locked ? '1' : '0'; ?>"
                                data-ko-has-scores="<?php echo $ko_has_scores ? '1' : '0'; ?>"
                                title="<?php echo $ko_has_scores ? 'Cannot unlock: KO bracket has scores' : ($group_locked ? 'Unlock group for editing' : 'Lock group scores'); ?>"
                                <?php echo $ko_has_scores ? 'disabled' : ''; ?>>
                            <?php echo $group_locked ? '🔒' : '🔓'; ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <table class="lgw-gchamp-standings">
                        <thead><tr>
                            <th class="lgw-gs-pos">#</th>
                            <th class="lgw-gs-col-name">Entry</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Played">P</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Won">W</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Drawn">D</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Lost">L</th>
                            <th class="lgw-gs-num" title="Shots For">SF</th>
                            <th class="lgw-gs-num" title="Shots Against">SA</th>
                            <th class="lgw-gs-diff" title="Difference">+/-</th>
                            <th class="lgw-gs-pts" title="Points">Pts</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $standings as $pos => $row ):
                            $is_q  = ( $pos < $wpg );
                            $is_r  = ( ! $is_q && $bru > 0 && $pos < $wpg + $bru );
                            $rc    = $is_q ? 'lgw-gs-row-qualify' : ( $is_r ? 'lgw-gs-row-runners' : '' );
                            $diff  = $row['sf'] - $row['sa'];
                            $dc    = $diff > 0 ? 'lgw-gs-diff-pos' : ( $diff < 0 ? 'lgw-gs-diff-neg' : 'lgw-gs-diff-zero' );
                        ?>
                        <tr class="<?php echo $rc; ?>">
                            <td class="lgw-gs-pos"><?php echo $pos + 1; ?></td>
                            <td class="lgw-gs-name"><?php echo esc_html( lgw_gchamp_short_name( $row['entry'] ) ); ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['p']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['w']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['d']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['l']; ?></td>
                            <td class="lgw-gs-num"><?php echo $row['sf']; ?></td>
                            <td class="lgw-gs-num"><?php echo $row['sa']; ?></td>
                            <td class="lgw-gs-diff <?php echo $dc; ?>"><?php echo ( $diff > 0 ? '+' : '' ) . $diff; ?></td>
                            <td class="lgw-gs-pts"><?php echo $row['pts']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="lgw-gchamp-qualify-legend">
                        <span class="lgw-gchamp-qualify-legend-item">
                            <span class="lgw-gchamp-qualify-dot lgw-gchamp-qualify-dot-q"></span>
                            Top <?php echo $wpg; ?> qualify
                        </span>
                        <?php if ( $bru > 0 ): ?>
                        <span class="lgw-gchamp-qualify-legend-item">
                            <span class="lgw-gchamp-qualify-dot lgw-gchamp-qualify-dot-r"></span>
                            Best runners-up
                        </span>
                        <?php endif; ?>
                    </div>
                    <button class="lgw-gchamp-fixtures-toggle" type="button">
                        Fixtures (<?php echo count( $g_fixtures ); ?>)
                        <span class="lgw-gchamp-fixtures-toggle-arrow">&#x25BC;</span>
                    </button>
                    <div class="lgw-gchamp-fixtures-body">
                    <?php
                    $by_round = array();
                    foreach ( $g_fixtures as $fx ) $by_round[ $fx['round'] ][] = $fx;
                    ksort( $by_round );
                    foreach ( $by_round as $rn => $rfxs ):
                    foreach ( $rfxs as $fx ):
                        $hs     = $fx['home_score'];
                        $as_v   = $fx['away_score'];
                        $scored = ( $hs !== null && $as_v !== null );
                        $hw     = $scored && intval($hs) > intval($as_v);
                        $aw     = $scored && intval($as_v) > intval($hs);
                        $rc     = $scored ? ( $hw ? ' lgw-gf-home-win' : ( $aw ? ' lgw-gf-away-win' : '' ) ) : '';
                        $pk     = esc_attr( $fx['pos_key'] ?? '' );
                        $raw_g  = intval( substr( explode( ':', $fx['pos_key'] ?? 'g0:' )[0], 1 ) );
                        $fk_day = intval( $raw_g / 100 );
                        $fk_grp = $raw_g % 100;
                    ?>
                    <div class="lgw-gchamp-fixture-row<?php echo $rc; ?>"
                         data-pos-key="<?php echo $pk; ?>"
                         data-day-id="<?php echo $fk_day; ?>"
                         data-group-id="<?php echo $fk_grp; ?>"
                         data-context="group">
                        <span class="lgw-gchamp-fixture-round">R<?php echo $rn + 1; ?></span>
                        <span class="lgw-gchamp-fixture-teams">
                            <span class="lgw-gchamp-fixture-team"><?php echo esc_html( lgw_gchamp_short_name( $fx['home'] ) ); ?></span>
                            <span class="lgw-gchamp-fixture-vs">v</span>
                            <span class="lgw-gchamp-fixture-team"><?php echo esc_html( lgw_gchamp_short_name( $fx['away'] ) ); ?></span>
                        </span>
                        <?php
                    $group_locked = ! empty( $group['locked'] );
                    $score_open   = $can_score && ( ! $day_complete || ( current_user_can('manage_options') && ! $group_locked ) );
                    ?>
                    <?php if ( $score_open ): ?>
                        <span class="lgw-gchamp-score-entry">
                            <?php if ( $scored ): ?>
                            <span class="lgw-gchamp-fixture-score lgw-gchamp-score-display"><?php echo intval($hs); ?>&ndash;<?php echo intval($as_v); ?></span>
                            <button class="lgw-gchamp-score-edit-btn" type="button">&#x270F;</button>
                            <?php else: ?>
                            <button class="lgw-gchamp-score-add-btn" type="button">+ Score</button>
                            <?php endif; ?>
                            <span class="lgw-gchamp-score-form" style="display:none">
                                <input type="number" class="lgw-gchamp-score-h" min="0" step="1" value="<?php echo $scored ? intval($hs) : ''; ?>" placeholder="0">
                                <span class="lgw-gchamp-score-sep">&ndash;</span>
                                <input type="number" class="lgw-gchamp-score-a" min="0" step="1" value="<?php echo $scored ? intval($as_v) : ''; ?>" placeholder="0">
                                <button type="button" class="lgw-gchamp-score-save-btn">&#x2713;</button>
                                <?php if ( $scored ): ?><button type="button" class="lgw-gchamp-score-clear-btn">&#x2715;</button><?php endif; ?>
                                <button type="button" class="lgw-gchamp-score-cancel-btn">Cancel</button>
                            </span>
                            <span class="lgw-gchamp-score-saving" style="display:none">Saving&hellip;</span>
                        </span>
                        <?php elseif ( $scored ): ?>
                        <span class="lgw-gchamp-fixture-score"><?php echo intval($hs); ?>&ndash;<?php echo intval($as_v); ?></span>
                        <?php else: ?>
                        <span class="lgw-gchamp-fixture-score lgw-gchamp-fixture-score-tbd">TBD</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; endforeach; ?>
                    </div>
                </div><!-- group-card -->
                <?php endforeach; // groups ?>
                </div><!-- groups-grid -->

                <?php if ( $day_complete && ! empty( $day['qualifiers'] ) ): ?>
                <div class="lgw-gchamp-qualifiers-strip">
                    <span class="lgw-gchamp-qualifiers-label">&#x2705; KO bracket seeded from <?php echo count( $day['qualifiers'] ); ?> qualifiers</span>
                    <?php foreach ( $day['qualifiers'] as $idx => $q ): ?>
                    <span class="lgw-gchamp-qualifier-badge"><?php echo $idx + 1; ?>. <?php echo esc_html( lgw_gchamp_short_name( $q ) ); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- groups sub-pane -->

            <?php /* ── Knockout sub-pane ── */ ?>
            <div class="lgw-gchamp-sub-pane" data-sub-pane="knockout">
            <?php if ( $ko_bracket ):
                // Render KO bracket inline (no lgw-champ.js dependency)
                $rounds     = $ko_bracket['rounds'] ?? array();
                $num_rounds = count( $rounds );
                // Determine Finals Week qualifier threshold for display
                $per_day_q  = 1;
                if ( $num_days === 1 )     $per_day_q = 4;
                elseif ( $num_days === 2 ) $per_day_q = 2;
                else                       $per_day_q = max(1, intval(ceil(4/$num_days)));
                $total_matches = 0;
                $played_matches = 0;
                foreach ($rounds as $round) { foreach ($round['matches'] as $m) { if (empty($m['bye'])) { $total_matches++; if ($m['home_score']!==null&&$m['away_score']!==null) $played_matches++; } } }
            ?>
                <div class="lgw-gchamp-ko-wrap" data-day-id="<?php echo $day_idx; ?>">
                    <div class="lgw-gchamp-ko-header">
                        <span class="lgw-gchamp-ko-title"><?php echo esc_html( $day['name'] ); ?> — Knockout</span>
                        <span class="lgw-gchamp-ko-progress"><?php echo $played_matches; ?>/<?php echo $total_matches; ?> played</span>
                    </div>
                    <div class="lgw-gchamp-ko-bracket-scroll">
                    <div class="lgw-gchamp-ko-bracket">
                    <?php foreach ( $rounds as $round ):
                        $is_final = ( $round['round'] === $num_rounds - 1 );
                        $is_sf    = ( $round['round'] === $num_rounds - 2 );
                        $qualifies_at_final = ( $per_day_q >= 1 );
                        $qualifies_at_sf    = ( $per_day_q >= 4 );
                        $qualifies_at_f_loser = ( $per_day_q >= 2 );
                    ?>
                    <div class="lgw-gchamp-ko-round">
                        <div class="lgw-gchamp-ko-round-label">
                            <?php echo esc_html( $round['label'] ); ?>
                            <?php if ( $is_final && $qualifies_at_final ): ?>
                                <span class="lgw-gchamp-ko-qualifies-note">&#x1F3C6; Finals Week</span>
                            <?php elseif ( $is_sf && $qualifies_at_sf ): ?>
                                <span class="lgw-gchamp-ko-qualifies-note">&#x1F3C6; Finals Week</span>
                            <?php endif; ?>
                        </div>
                        <?php foreach ( $round['matches'] as $match ):
                            $scored = ( $match['home_score'] !== null && $match['away_score'] !== null );
                            $is_bye = ! empty( $match['bye'] );
                            $hw     = $scored && intval($match['home_score']) > intval($match['away_score']);
                            $aw     = $scored && intval($match['away_score']) > intval($match['home_score']);
                        ?>
                        <div class="lgw-gchamp-ko-match<?php echo $is_bye ? ' lgw-gchamp-ko-match-bye' : ''; echo ($is_final&&$scored) ? ' lgw-gchamp-ko-match-final' : ''; ?>"
                             data-round="<?php echo intval($round['round']); ?>"
                             data-match="<?php echo intval($match['match']); ?>"
                             data-day-id="<?php echo $day_idx; ?>"
                             data-context="ko">
                            <div class="lgw-gchamp-ko-team<?php echo $hw ? ' lgw-gchamp-ko-team-win' : ($aw ? ' lgw-gchamp-ko-team-loss' : ''); ?>">
                                <span class="lgw-gchamp-ko-team-name"><?php echo $match['home'] ? esc_html(lgw_gchamp_short_name($match['home'])) : '<span class="lgw-gchamp-ko-tbd">TBD</span>'; ?></span>
                                <?php if ( $scored ): ?><span class="lgw-gchamp-ko-score<?php echo $hw?' lgw-gchamp-ko-score-win':''; ?>"><?php echo intval($match['home_score']); ?></span><?php endif; ?>
                            </div>
                            <div class="lgw-gchamp-ko-team<?php echo $aw ? ' lgw-gchamp-ko-team-win' : ($hw ? ' lgw-gchamp-ko-team-loss' : ''); ?>">
                                <span class="lgw-gchamp-ko-team-name"><?php echo $match['away'] ? esc_html(lgw_gchamp_short_name($match['away'])) : '<span class="lgw-gchamp-ko-tbd">TBD</span>'; ?></span>
                                <?php if ( $scored ): ?><span class="lgw-gchamp-ko-score<?php echo $aw?' lgw-gchamp-ko-score-win':''; ?>"><?php echo intval($match['away_score']); ?></span><?php endif; ?>
                            </div>
                            <?php if ( $can_score && ! $is_bye && ! $ko_complete && $match['home'] && $match['away'] ): ?>
                            <div class="lgw-gchamp-ko-score-entry">
                                <?php if ( ! $scored ): ?>
                                <button type="button" class="lgw-gchamp-ko-score-btn">+ Score</button>
                                <?php else: ?>
                                <button type="button" class="lgw-gchamp-ko-score-btn lgw-gchamp-ko-score-edit-btn">&#x270F;</button>
                                <?php endif; ?>
                                <span class="lgw-gchamp-ko-score-form" style="display:none">
                                    <input type="number" class="lgw-gchamp-ko-score-h" min="0" step="1" value="<?php echo $scored?intval($match['home_score']):''; ?>" placeholder="0">
                                    <span class="lgw-gchamp-score-sep">&ndash;</span>
                                    <input type="number" class="lgw-gchamp-ko-score-a" min="0" step="1" value="<?php echo $scored?intval($match['away_score']):''; ?>" placeholder="0">
                                    <button type="button" class="lgw-gchamp-ko-save-btn">&#x2713;</button>
                                    <?php if ($scored): ?><button type="button" class="lgw-gchamp-ko-clear-btn">&#x2715;</button><?php endif; ?>
                                    <button type="button" class="lgw-gchamp-ko-cancel-btn">Cancel</button>
                                </span>
                                <span class="lgw-gchamp-ko-saving" style="display:none">Saving&hellip;</span>
                            </div>
                            <?php endif; ?>
                        </div><!-- ko-match -->
                        <?php endforeach; ?>
                    </div><!-- ko-round -->
                    <?php endforeach; ?>
                    </div><!-- ko-bracket -->
                    </div><!-- scroll -->

                    <?php if ( $ko_complete && ! empty( $ko_quals ) ): ?>
                    <div class="lgw-gchamp-ko-finals-strip">
                        <span class="lgw-gchamp-ko-finals-label">&#x1F3C6; Finals Week qualifiers from <?php echo esc_html($day['name']); ?>:</span>
                        <?php foreach ( $ko_quals as $idx => $q ): ?>
                        <span class="lgw-gchamp-ko-finals-badge"><?php echo $idx+1; ?>. <?php echo esc_html(lgw_gchamp_short_name($q)); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ( $day_complete && ! $ko_complete ): ?>
                    <div class="lgw-gchamp-ko-finals-strip lgw-gchamp-ko-finals-strip-pending">
                        <?php
                        $qstage = '';
                        if ($num_days===1)     $qstage = 'Semi-finalists (4)';
                        elseif ($num_days===2) $qstage = 'Finalists (2)';
                        else                   $qstage = 'Winner (1)';
                        ?>
                        &#x1F3C6; <strong>Finals Week qualifiers:</strong> <?php echo esc_html($qstage); ?> — complete the knockout to confirm
                    </div>
                    <?php endif; ?>
                </div><!-- ko-wrap -->
            <?php else: ?>
                <div class="lgw-gchamp-empty" style="padding:32px 20px">
                    <div class="lgw-gchamp-empty-icon">&#x1F3C6;</div>
                    <p>Knockout bracket will be seeded automatically when all group fixtures for <?php echo esc_html($day['name']); ?> are complete.</p>
                </div>
            <?php endif; ?>
            </div><!-- knockout sub-pane -->

        </div><!-- day-pane -->
        <?php endforeach; // days ?>

        <?php endif; // drawn ?>

        <?php /* ── Finals Week pane ── */ ?>
        <?php if ( $drawn && $has_any_ko_complete ): ?>
        <div class="lgw-gchamp-day-pane" data-day-tab="finals" data-day-pane="finals">
            <div class="lgw-gchamp-finals-pane">

                <?php
                // Auto-build finals matches if not yet seeded
                if ( empty( $finals_matches ) ) {
                    $built = lgw_gchamp_build_finals_matches( $champ );
                    if ( ! empty( $built ) ) {
                        $champ['finals_matches'] = $built;
                        $finals_matches          = $built;
                        update_option( 'lgw_gchamp_' . $gchamp_id, $champ );
                    }
                } else {
                    // Propagate SF winners into Final slot
                    lgw_gchamp_finals_propagate( $champ );
                    $finals_matches = $champ['finals_matches'];
                }

                $club_badges = get_option( 'lgw_club_badges', array() );
                $team_badges = get_option( 'lgw_badges',      array() );
                $nonce_finals = wp_create_nonce( 'lgw_gchamp_score' );

                // Group by round
                $by_round = array();
                foreach ( $finals_matches as $mi => $match ) {
                    $by_round[ $match['round'] ][] = array_merge( $match, array( '_idx' => $mi ) );
                }
                ?>

                <div class="lgw-gchamp-finals-header">
                    <span class="lgw-gchamp-finals-title">&#x1F3C6; Finals Week</span>
                    <span class="lgw-gchamp-finals-subtitle">
                        <?php
                        $confirmed = array_filter( array_map( fn($d) => $d['ko_qualifiers'] ?? array(), $days ) );
                        $total_q   = array_sum( array_map( 'count', $confirmed ) );
                        $total_days= count( $days );
                        $done_days = count( array_filter( $days, fn($d) => ! empty( $d['ko_complete'] ) ) );
                        echo esc_html( $done_days . ' of ' . $total_days . ' section' . ( $total_days !== 1 ? 's' : '' ) . ' confirmed · ' . $total_q . ' qualifier' . ( $total_q !== 1 ? 's' : '' ) );
                        ?>
                    </span>
                </div>

                <?php foreach ( $by_round as $round_name => $round_matches ): ?>
                <div class="lgw-gchamp-finals-round">
                    <div class="lgw-gchamp-finals-round-label"><?php echo esc_html( $round_name ); ?></div>
                    <?php foreach ( $round_matches as $match ):
                        $mi        = $match['_idx'];
                        $home      = $match['home']  ?? null;
                        $away      = $match['away']  ?? null;
                        $hs        = $match['home_score'] ?? null;
                        $as_v      = $match['away_score'] ?? null;
                        $has_score = $hs !== null && $as_v !== null;
                        $ends      = $match['ends']  ?? array();
                        $dt        = $match['finals_datetime'] ?? '';
                        $rink      = $match['finals_rink']     ?? '';
                        $pending   = ! $home || ! $away;
                        $mid       = 'gf-' . $gchamp_id . '-' . $mi;
                        $status_cls = $pending   ? 'lgw-finals-match--pending'
                                    : ($has_score ? 'lgw-finals-match--complete' : 'lgw-finals-match--upcoming');
                        // Running ends total
                        $ht = 0; $at = 0;
                        foreach ( $ends as $end ) { $ht += intval($end[0]??0); $at += intval($end[1]??0); }
                        // Badges
                        $home_badge = $away_badge = '';
                        foreach ( $team_badges as $t => $url ) {
                            if ( $home && stripos($home,$t)!==false ) $home_badge=$url;
                            if ( $away && stripos($away,$t)!==false ) $away_badge=$url;
                        }
                        if (!$home_badge && $home) { $hc=strtolower(trim(explode(',',$home,2)[1]??'')); $home_badge=$club_badges[$hc]??''; }
                        if (!$away_badge && $away) { $ac=strtolower(trim(explode(',',$away,2)[1]??'')); $away_badge=$club_badges[$ac]??''; }
                    ?>
                    <div class="lgw-finals-match <?php echo $status_cls; ?>"
                         id="lgw-fm-<?php echo esc_attr($mid); ?>"
                         data-mid="<?php echo esc_attr($mid); ?>"
                         data-match-idx="<?php echo $mi; ?>"
                         data-champ-id="<?php echo esc_attr($gchamp_id); ?>">

                        <?php if ( $dt || $rink || $can_score ): ?>
                        <div class="lgw-finals-datetime<?php echo (!$dt && !$rink) ? ' lgw-finals-datetime--unset' : ''; ?>">
                            <?php if ( $dt ): ?>
                            <span class="lgw-finals-datetime-val"><?php echo esc_html( function_exists('lgw_finals_format_datetime') ? lgw_finals_format_datetime($dt) : $dt ); ?></span>
                            <?php endif; ?>
                            <?php if ( $rink ): ?>
                            <span class="lgw-finals-rink-val">Rink <?php echo esc_html($rink); ?></span>
                            <?php endif; ?>
                            <?php if ( $can_score ): ?>
                            <button class="lgw-finals-edit-dt" data-mid="<?php echo esc_attr($mid); ?>" title="Set date, time &amp; rink">
                                <?php echo ($dt||$rink) ? '&#x270F;&#xFE0F;' : '&#x1F4C5; Set date, time &amp; rink'; ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $pending ): ?>
                        <div class="lgw-finals-teams lgw-finals-teams--pending">
                            <span class="lgw-finals-tbd">TBD</span>
                            <span class="lgw-finals-vs">v</span>
                            <span class="lgw-finals-tbd">TBD</span>
                        </div>
                        <?php else: ?>
                        <div class="lgw-finals-teams">
                            <div class="lgw-finals-team lgw-finals-team--home">
                                <?php if ($home_badge): ?><img src="<?php echo esc_url($home_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                                <div class="lgw-finals-team-info">
                                    <span class="lgw-finals-team-name"><?php echo esc_html( lgw_gchamp_short_name($home) ); ?></span>
                                    <?php $hclub = trim(explode(',',$home,2)[1]??''); if ($hclub): ?>
                                    <span class="lgw-finals-team-club"><?php echo esc_html($hclub); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="lgw-finals-score-block">
                                <?php if ($has_score): ?>
                                    <span class="lgw-finals-score lgw-finals-score--home<?php echo intval($hs)>intval($as_v)?' lgw-finals-score--win':''; ?>"><?php echo intval($hs); ?></span>
                                    <span class="lgw-finals-score-sep">&#x2013;</span>
                                    <span class="lgw-finals-score lgw-finals-score--away<?php echo intval($as_v)>intval($hs)?' lgw-finals-score--win':''; ?>"><?php echo intval($as_v); ?></span>
                                <?php elseif (!empty($ends)): ?>
                                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $ht; ?></span>
                                    <span class="lgw-finals-score-sep">&#x2013;</span>
                                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $at; ?></span>
                                    <span class="lgw-finals-live-badge">LIVE</span>
                                <?php else: ?>
                                    <span class="lgw-finals-score-placeholder">v</span>
                                <?php endif; ?>
                                <?php if ( $can_score && !$pending ): ?>
                                <button class="lgw-finals-edit-score" data-mid="<?php echo esc_attr($mid); ?>" title="Enter score">&#x270F;&#xFE0F;</button>
                                <?php endif; ?>
                            </div>
                            <div class="lgw-finals-team lgw-finals-team--away">
                                <div class="lgw-finals-team-info">
                                    <span class="lgw-finals-team-name"><?php echo esc_html( lgw_gchamp_short_name($away) ); ?></span>
                                    <?php $aclub = trim(explode(',',$away,2)[1]??''); if ($aclub): ?>
                                    <span class="lgw-finals-team-club"><?php echo esc_html($aclub); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($away_badge): ?><img src="<?php echo esc_url($away_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($ends)): ?>
                        <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                            <?php
                            if (function_exists('lgw_finals_render_ends_table')) {
                                echo lgw_finals_render_ends_table($ends, $home, $away, $can_score, $mid, true);
                            }
                            ?>
                        </div>
                        <?php elseif ($can_score && !$has_score): ?>
                        <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                            <div class="lgw-finals-ends-empty">
                                <button class="lgw-finals-add-end-btn" data-mid="<?php echo esc_attr($mid); ?>">+ Start live scoring</button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; // !pending ?>

                    </div><!-- .lgw-finals-match -->
                    <?php endforeach; ?>
                </div><!-- .lgw-gchamp-finals-round -->
                <?php endforeach; ?>

            </div><!-- .lgw-gchamp-finals-pane -->
        </div><!-- finals day-pane -->

        <script>
        (function(){
            if(typeof lgwFinalsData==='undefined') window.lgwFinalsData={};
            var matches={};
            <?php foreach ($finals_matches as $mi => $match):
                $mid = 'gf-' . $gchamp_id . '-' . $mi; ?>
            matches[<?php echo wp_json_encode($mid); ?>]={
                champId:   <?php echo wp_json_encode($gchamp_id); ?>,
                matchIdx:  <?php echo $mi; ?>,
                home:      <?php echo wp_json_encode($match['home']??null); ?>,
                away:      <?php echo wp_json_encode($match['away']??null); ?>,
                homeScore: <?php echo wp_json_encode($match['home_score']??null); ?>,
                awayScore: <?php echo wp_json_encode($match['away_score']??null); ?>,
                ends:      <?php echo wp_json_encode($match['ends']??array()); ?>,
                datetime:  <?php echo wp_json_encode($match['finals_datetime']??''); ?>,
                rink:      <?php echo wp_json_encode($match['finals_rink']??''); ?>,
                nonce:     <?php echo wp_json_encode(wp_create_nonce('lgw_gchamp_score')); ?>,
                isGchamp:  true,
            };
            <?php endforeach; ?>
            lgwFinalsData.matches = Object.assign(lgwFinalsData.matches||{}, matches);
            lgwFinalsData.nonce   = <?php echo wp_json_encode(wp_create_nonce('lgw_gchamp_score')); ?>;
            lgwFinalsData.isAdmin = <?php echo $can_score ? '1' : '0'; ?>;
        })();
        </script>
        <?php endif; ?>

        <div class="lgw-gchamp-status">
            <span class="lgw-gchamp-status-dot<?php echo ($drawn && !$gs_complete) ? ' live' : ''; ?>"></span>
            <span class="lgw-gchamp-status-text">
            <?php
            if (!$drawn) { echo 'Awaiting draw&hellip;'; }
            elseif ($gs_complete) { echo 'Group stage complete'; }
            else {
                $tfx=0; $played=0;
                foreach ($days as $day) foreach ($day['groups']??array() as $g) {
                    $tfx   += count($g['fixtures']??array());
                    $played += lgw_gchamp_count_played($g['fixtures']??array());
                }
                echo esc_html($played.' of '.$tfx.' group fixtures played');
            }
            ?>
            </span>
        </div>

    </div><!-- .lgw-gchamp-wrap -->
    <?php
    return ob_get_clean();
}
