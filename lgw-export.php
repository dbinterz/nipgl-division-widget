<?php
/**
 * LGW Export — Download draw brackets as Excel (.xlsx) files.
 * Pure PHP, ZipArchive only. No external dependencies.
 *
 * Correctly handles both plain brackets (no prelim) and brackets with a
 * preliminary round (N teams where N is not a power of two), matching the
 * visual layout of the reference spreadsheets exactly.
 *
 * Style:
 *   Row 1: Red title banner  (CF2E2E, white 18pt bold, full-width merge)
 *   Row 2: Round names       (073763, white bold)
 *   Row 3: Dates             (FFFF00, black bold, DD/MM/YYYY)
 *   Data : Two rows per main-bracket slot. Prelim teams shown in their own
 *          column where applicable. Winners span increasing merged row blocks.
 *          CCCCCC→E2EFDA→DAE3F3→B7B7B7→FFFFFF shading per round depth.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_lgw_export_cup',   'lgw_ajax_export_cup' );
add_action( 'wp_ajax_lgw_export_champ', 'lgw_ajax_export_champ' );

// ── Cup export ────────────────────────────────────────────────────────────────
function lgw_ajax_export_cup() {
    check_ajax_referer( 'lgw_export_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

    $cup_id = sanitize_key( $_POST['cup_id'] ?? '' );
    if ( ! $cup_id ) wp_die( 'Missing cup_id', 400 );
    $cup = get_option( 'lgw_cup_' . $cup_id, array() );
    if ( empty( $cup ) )            wp_die( 'Cup not found', 404 );
    if ( empty( $cup['bracket'] ) ) wp_die( 'No draw performed yet', 400 );

    $sheets = array( array(
        'name'         => lgw_xlsx_sheet_name( $cup['title'] ?? $cup_id ),
        'title'        => $cup['title'] ?? $cup_id,
        'rounds'       => $cup['bracket']['rounds']  ?? array(),
        'matches'      => $cup['bracket']['matches'] ?? array(),
        'dates'        => array_values( array_filter( array_map( 'trim', $cup['dates'] ?? array() ) ) ),
        'has_draw_num' => true,
    ) );
    lgw_xlsx_send( $sheets, sanitize_file_name( str_replace( ' ', '_', $cup['title'] ?? $cup_id ) ) . '.xlsx' );
}

// ── Championship export ───────────────────────────────────────────────────────
function lgw_ajax_export_champ() {
    check_ajax_referer( 'lgw_export_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    if ( ! $champ_id ) wp_die( 'Missing champ_id', 400 );
    $champ = get_option( 'lgw_champ_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_die( 'Championship not found', 404 );

    $sheets      = array();
    $num_sections = count( $champ['sections'] ?? array() );

    foreach ( ( $champ['sections'] ?? array() ) as $idx => $sec ) {
        $bk = 'section_' . $idx . '_bracket';
        if ( empty( $champ[ $bk ] ) ) continue;
        $dk    = 'section_' . $idx . '_dates';
        $dates = ! empty( $champ[ $dk ] )
               ? array_values( array_filter( array_map( 'trim', explode( "\n", $champ[ $dk ] ) ) ) )
               : array_values( array_filter( array_map( 'trim', $champ['dates'] ?? array() ) ) );
        $label = $sec['label'] ?? chr( 65 + $idx );
        $disc  = strtoupper( $champ['discipline'] ?? 'SINGLES' );
        $sheets[] = array(
            'name'         => lgw_xlsx_sheet_name( $num_sections === 1 ? $disc : 'SECTION ' . strtoupper( $label ) ),
            'title'        => ( $champ['title'] ?? $champ_id ) . ( $num_sections > 1 ? ' - SECTION ' . strtoupper( $label ) : '' ),
            'rounds'       => $champ[ $bk ]['rounds']  ?? array(),
            'matches'      => $champ[ $bk ]['matches'] ?? array(),
            'dates'        => $dates,
            'has_draw_num' => false,
        );
    }
    if ( ! empty( $champ['final_bracket'] ) ) {
        $sheets[] = array(
            'name'         => 'FINAL STAGE',
            'title'        => ( $champ['title'] ?? $champ_id ) . ' - FINAL STAGE',
            'rounds'       => $champ['final_bracket']['rounds']  ?? array(),
            'matches'      => $champ['final_bracket']['matches'] ?? array(),
            'dates'        => array_values( array_filter( array_map( 'trim', $champ['dates'] ?? array() ) ) ),
            'has_draw_num' => false,
        );
    }
    if ( empty( $sheets ) ) wp_die( 'No draws have been performed yet.', 400 );
    lgw_xlsx_send( $sheets, sanitize_file_name( str_replace( ' ', '_', $champ['title'] ?? $champ_id ) ) . '.xlsx' );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  BRACKET ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Analyse the matches array and return a descriptor:
 *
 *   [
 *     'has_prelim'   => bool,
 *     'prelim_matches' => array,   // matches[0] when prelim exists, else []
 *     'main_matches'   => array[], // matches[1..] or matches[0..] (without prelim)
 *     'main_rounds'    => string[], // round names aligned with main_matches
 *     'prelim_round'   => string,  // name of prelim round (or '')
 *     'half'           => int,     // number of main-R1 slots = len(main_matches[0])*2
 *     // Prelim-slot map: for each main R1 slot index (0..half-1), either:
 *     //   null  = real bye team (home/away in main_matches[0])
 *     //   int   = index into prelim_matches[] (0-based)
 *     'prelim_slot_map' => array,
 *   ]
 */
function lgw_xlsx_analyse( $matches, $rounds ) {
    $nr = count( $matches );
    if ( $nr === 0 ) {
        return array( 'has_prelim' => false, 'prelim_matches' => array(),
                      'main_matches' => array(), 'main_rounds' => array(),
                      'prelim_round' => '', 'half' => 0, 'prelim_slot_map' => array() );
    }

    // Detect prelim: if matches[1] exists and any of its slots have home===null or away===null
    $has_prelim = false;
    if ( $nr >= 2 ) {
        foreach ( $matches[1] as $m ) {
            if ( $m['home'] === null || $m['away'] === null ) { $has_prelim = true; break; }
        }
    }

    if ( ! $has_prelim ) {
        // No prelim: matches[0] is the first real round
        $half = count( $matches[0] ) * 2;
        return array(
            'has_prelim'      => false,
            'prelim_matches'  => array(),
            'main_matches'    => $matches,
            'main_rounds'     => $rounds,
            'prelim_round'    => '',
            'half'            => $half,
            'prelim_slot_map' => array_fill( 0, $half, null ),
        );
    }

    // Has prelim: matches[0] = prelim, matches[1..] = main rounds
    $prelim_matches = $matches[0];
    $main_matches   = array_slice( $matches, 1 );
    $main_rounds    = array_slice( $rounds,  1 );
    $prelim_round   = $rounds[0] ?? 'Preliminary Round';
    $prelim_count   = count( $prelim_matches );
    $half           = count( $main_matches[0] ) * 2; // total main-R1 slots

    // Build prelim_slot_map: for each main R1 slot (0..half-1), which prelim match feeds it?
    // Use prev_game_home/prev_game_away if available (champ draws with game_nums=true).
    // Fall back to the positional formula from lgw-draw.php (cup draws with game_nums=false).
    $prelim_slot_map = array_fill( 0, $half, null ); // null = real team

    // Try prev_game annotations first
    $used_prev_game = false;
    foreach ( $main_matches[0] as $mi => $m ) {
        $base_slot = $mi * 2;
        $phg = $m['prev_game_home'] ?? null;
        $pag = $m['prev_game_away'] ?? null;
        if ( $phg !== null ) {
            // prev_game is 1-based game_num; prelim games are numbered 1..prelim_count
            $pidx = (int)$phg - 1;
            if ( $pidx >= 0 && $pidx < $prelim_count ) {
                $prelim_slot_map[ $base_slot ] = $pidx;
                $used_prev_game = true;
            }
        }
        if ( $pag !== null ) {
            $pidx = (int)$pag - 1;
            if ( $pidx >= 0 && $pidx < $prelim_count ) {
                $prelim_slot_map[ $base_slot + 1 ] = $pidx;
                $used_prev_game = true;
            }
        }
    }

    if ( ! $used_prev_game ) {
        // Positional formula from lgw-draw.php:
        // step = half / prelim_count
        // winner_positions[w] = round(w * step)  for w in 0..prelim_count-1
        // Each winner_position maps to a pair of consecutive R1 SLOTS (not matches)
        // The prelim match occupies both rows of the slot it feeds
        $step = (float)$half / $prelim_count;
        for ( $w = 0; $w < $prelim_count; $w++ ) {
            $pos = (int) round( $w * $step ); // R1 slot index where this prelim feeds
            // The prelim match occupies the home row of this slot
            // In the reference: prelim match home=row_s, away=row_s+1
            // Both rows of this slot belong to the same prelim match
            $prelim_slot_map[ $pos ] = $w;
        }
    }

    return array(
        'has_prelim'      => true,
        'prelim_matches'  => $prelim_matches,
        'main_matches'    => $main_matches,
        'main_rounds'     => $main_rounds,
        'prelim_round'    => $prelim_round,
        'half'            => $half,
        'prelim_slot_map' => $prelim_slot_map,
    );
}

/**
 * For each main-R1 slot (0..half-1) build propagated round names.
 * Returns array indexed by slot_idx => [round_idx => winner_name_or_null]
 * round_idx 0 = main R1 (the first non-prelim round).
 */
function lgw_xlsx_propagate( $main_matches ) {
    $nm = count( $main_matches );
    if ( $nm === 0 ) return array();

    $ns = count( $main_matches[0] ) * 2; // number of main-R1 slots
    $rn = array_fill( 0, $ns, array() );

    // R0: names from main_matches[0]
    foreach ( $main_matches[0] as $mi => $m ) {
        $rn[ $mi * 2     ][0] = $m['home'];
        $rn[ $mi * 2 + 1 ][0] = $m['away'];
    }

    // Build lookup by name for propagation
    $lk = array();
    for ( $si = 0; $si < $ns; $si++ ) {
        $name = $rn[$si][0];
        if ( $name ) $lk[ strtolower( trim( $name ) ) ] = $si;
    }

    // Propagate winners from each subsequent round
    for ( $ri = 0; $ri < $nm - 1; $ri++ ) {
        foreach ( ( $main_matches[ $ri + 1 ] ?? array() ) as $nm_m ) {
            foreach ( array( 'home', 'away' ) as $side ) {
                $w = $nm_m[ $side ] ?? null;
                if ( ! $w ) continue;
                $k = strtolower( trim( $w ) );
                if ( isset( $lk[$k] ) ) $rn[ $lk[$k] ][ $ri + 1 ] = $w;
            }
        }
    }

    return $rn;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  XLSX BUILDER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Style indices (must match lgw_xlsx_styles() cellXfs order):
 *  0 default   1 title    2 round-name  3 date-text  4 date-num
 *  5 draw-num  6 slot-R1  7 slot-R2     8 slot-R3/QF 9 slot-SF
 * 10 slot-Final 11 empty-R1-filler  12 red-filler  13 prelim-team
 */
function lgw_xlsx_slot_style( $ri, $nr ) {
    if ( $ri === 0 )       return 6;   // R1
    if ( $ri === $nr - 1 ) return 10;  // Final
    if ( $ri === $nr - 2 ) return 9;   // SF
    if ( $ri === 1 )       return 7;   // R2
    return 8;                          // R3/QF
}

/** DD/MM/YYYY → Excel date serial float, or original string. */
function lgw_xlsx_date( $s ) {
    $s = trim( $s );
    if ( preg_match( '|^(\d{1,2})/(\d{1,2})/(\d{2,4})$|', $s, $m ) ) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ( $y < 100 ) $y += 2000;
        $u = mktime( 0, 0, 0, $mo, $d, $y );
        $e = mktime( 0, 0, 0, 12, 30, 1899 );
        if ( $u && $e ) return (float) round( ( $u - $e ) / 86400 );
    }
    return $s;
}

/** 0-based column index → Excel letter(s). */
function lgw_xlsx_col( $i ) {
    $l = ''; $n = $i;
    do { $l = chr( 65 + $n % 26 ) . $l; $n = intval( $n / 26 ) - 1; } while ( $n >= 0 );
    return $l;
}

/** Sanitise sheet name (max 31 chars). */
function lgw_xlsx_sheet_name( $s ) {
    return mb_substr( preg_replace( '/[\/\\\?\*\[\]:]/', '', $s ), 0, 31 );
}

/**
 * Build one worksheet XML.
 * Accumulates shared strings into $sst / $sst_idx (passed by reference).
 */
function lgw_xlsx_sheet( $sheet, &$sst, &$sst_idx ) {
    $si = function( $v ) use ( &$sst, &$sst_idx ) {
        $k = (string) $v;
        if ( ! isset( $sst_idx[$k] ) ) { $sst_idx[$k] = count($sst); $sst[] = $k; }
        return $sst_idx[$k];
    };

    $rounds  = $sheet['rounds'];
    $matches = $sheet['matches'];
    $dates   = $sheet['dates'];
    $title   = $sheet['title'];
    $hdnum   = $sheet['has_draw_num'];

    // ── Analyse bracket structure ─────────────────────────────────────────
    $info = lgw_xlsx_analyse( $matches, $rounds );
    if ( $info['half'] === 0 ) {
        return lgw_xlsx_empty_sheet( $title );
    }

    $has_prelim    = $info['has_prelim'];
    $prelim_ms     = $info['prelim_matches'];
    $main_ms       = $info['main_matches'];
    $main_rounds   = $info['main_rounds'];
    $prelim_round  = $info['prelim_round'];
    $pmap          = $info['prelim_slot_map'];
    $half          = $info['half'];
    $nm            = count( $main_ms );  // number of main rounds

    // Propagate winners through main rounds
    $prop = lgw_xlsx_propagate( $main_ms );

    // ── Column layout ─────────────────────────────────────────────────────
    // col 0 (1-based: 1): draw number  (if has_draw_num)
    // col 1 (1-based: 2): prelim round  (if has_prelim)
    // col 2..nm+1 (1-based: 3..nm+2): main R1 through Final
    //
    // When !has_draw_num, everything shifts left by 1.
    // When !has_prelim,  prelim col is omitted.
    $col_draw   = $hdnum    ? 0 : -1; // 0-based; -1 = absent
    $col_prelim = $has_prelim ? ( $hdnum ? 1 : 0 ) : -1;
    $col_main0  = ( $hdnum ? 1 : 0 ) + ( $has_prelim ? 1 : 0 ); // 0-based index of main R1 col
    $ncols      = $col_main0 + $nm; // total columns

    // All row/col indices below are 1-based Excel values
    $rows   = array();
    $merges = array();

    $set = function( $r, $c, $t, $v, $s ) use ( &$rows ) {
        $rows[$r][$c] = array( 't' => $t, 'v' => $v, 's' => $s );
    };
    $mrg = function( $r1, $c1, $r2, $c2 ) use ( &$merges ) {
        if ( $r1 === $r2 && $c1 === $c2 ) return;
        $merges[] = lgw_xlsx_col($c1-1).$r1.':'.lgw_xlsx_col($c2-1).$r2;
    };

    // ── Row 1: Title ──────────────────────────────────────────────────────
    $mrg( 1, 1, 1, $ncols );
    $set( 1, 1, 's', $si( strtoupper($title) ), 1 );
    for ( $c = 2; $c <= $ncols; $c++ ) $set( 1, $c, '', null, 12 );

    // ── Row 2: Round names ────────────────────────────────────────────────
    if ( $hdnum )     $set( 2, $col_draw   + 1, 's', $si('Draw No.'),  2 );
    if ( $has_prelim) $set( 2, $col_prelim + 1, 's', $si( strtoupper($prelim_round) ), 2 );
    foreach ( $main_rounds as $ri => $rn ) {
        $set( 2, $col_main0 + $ri + 1, 's', $si( strtoupper($rn ?: 'ROUND '.($ri+1)) ), 2 );
    }

    // ── Row 3: Dates ──────────────────────────────────────────────────────
    // Dates array is aligned with $rounds (including prelim if present)
    $all_round_cols = array();
    if ( $has_prelim ) $all_round_cols[] = $col_prelim + 1;
    for ( $ri = 0; $ri < $nm; $ri++ ) $all_round_cols[] = $col_main0 + $ri + 1;
    if ( $hdnum ) $set( 3, $col_draw + 1, '', null, 3 );
    foreach ( $all_round_cols as $di => $col1 ) {
        $dv = isset( $dates[$di] ) ? lgw_xlsx_date( $dates[$di] ) : null;
        if ( $dv === null )                     $set( 3, $col1, '', null, 3 );
        elseif ( is_float($dv)||is_int($dv) )   $set( 3, $col1, 'n', $dv,  4 );
        else                                    $set( 3, $col1, 's', $si($dv), 3 );
    }

    // ── Data rows ─────────────────────────────────────────────────────────
    // 2 rows per main R1 slot; data starts at Excel row 4.
    $ds = 3; // 0-based offset; Excel row = ds + slot_idx*2 + 1

    // Build a reverse map: prelim_match_idx → list of slot_indices it feeds
    // (usually 1 slot per prelim match, but handle edge cases)
    $prelim_to_slots = array();
    for ( $si_idx = 0; $si_idx < $half; $si_idx++ ) {
        $pidx = $pmap[$si_idx];
        if ( $pidx !== null ) {
            $prelim_to_slots[$pidx][] = $si_idx;
        }
    }

    for ( $si_idx = 0; $si_idx < $half; $si_idx++ ) {
        $br  = $ds + $si_idx * 2 + 1;  // Excel base row (1-based)
        $er  = $br + 1;

        $pidx = $pmap[$si_idx];         // prelim match index, or null

        // ── Draw number column ─────────────────────────────────────────
        if ( $hdnum ) {
            $dc = $col_draw + 1;
            if ( $pidx !== null ) {
                // Two prelim teams: show each draw number on its own row (no merge)
                $pm   = $prelim_ms[$pidx] ?? null;
                $hdnum_val = $pm ? $pm['draw_num_home'] : null;
                $adnum_val = $pm ? $pm['draw_num_away'] : null;
                if ( $hdnum_val !== null ) $set( $br, $dc, 'n', (int)$hdnum_val, 5 );
                else                      $set( $br, $dc, '', null, 5 );
                if ( $adnum_val !== null ) $set( $er, $dc, 'n', (int)$adnum_val, 5 );
                else                      $set( $er, $dc, '', null, 5 );
            } else {
                // Single bye team: merge draw number across 2 rows
                $m0   = $main_ms[0][$si_idx >> 1] ?? null;
                $side = ($si_idx % 2 === 0) ? 'home' : 'away';
                $dnum = $m0 ? ( $m0[ ($side==='home'?'draw_num_home':'draw_num_away') ] ?? null ) : null;
                $mrg( $br, $dc, $er, $dc );
                if ( $dnum !== null ) $set( $br, $dc, 'n', (int)$dnum, 5 );
                else                  $set( $br, $dc, '', null, 5 );
            }
        }

        // ── Prelim column ──────────────────────────────────────────────
        if ( $has_prelim ) {
            $pc = $col_prelim + 1;
            if ( $pidx !== null ) {
                // Show the two prelim teams (home on top row, away on bottom)
                $pm    = $prelim_ms[$pidx] ?? null;
                $hname = $pm ? ($pm['home'] ?? null) : null;
                $aname = $pm ? ($pm['away'] ?? null) : null;
                if ( $hname ) $set( $br, $pc, 's', $si($hname), 13 );
                else          $set( $br, $pc, '', null, 11 );
                if ( $aname ) $set( $er, $pc, 's', $si($aname), 13 );
                else          $set( $er, $pc, '', null, 11 );
            } else {
                // Bye slot: prelim col is empty grey filler on both rows
                $set( $br, $pc, '', null, 11 );
                $set( $er, $pc, '', null, 11 );
            }
        }

        // ── Main round columns (R1 through Final) ──────────────────────
        for ( $ri = 0; $ri < $nm; $ri++ ) {
            $name = $prop[$si_idx][$ri] ?? null;

            // Skip if no name here
            if ( $name === null ) {
                // Fill R1 column with grey background on both rows of this slot
                if ( $ri === 0 ) {
                    $mc = $col_main0 + $ri + 1;
                    $set( $br, $mc, '', null, 11 );
                    $set( $er, $mc, '', null, 11 );
                }
                continue;
            }

            // Only write once per block (at the top of the merged region)
            $block = (int) pow( 2, $ri );      // number of slots per block at this round
            if ( $si_idx % $block !== 0 ) continue;

            $span   = 2 * $block;              // Excel rows spanned by this cell
            $row_s  = $ds + $si_idx * 2 + 1;
            $row_e  = $row_s + $span - 1;
            $col1   = $col_main0 + $ri + 1;

            $mrg( $row_s, $col1, $row_e, $col1 );
            $set( $row_s, $col1, 's', $si($name), lgw_xlsx_slot_style($ri, $nm) );
        }

        // Ensure R1 col has grey bg on both rows for null slots (not already set above)
        $r1col = $col_main0 + 1;
        if ( ! isset($rows[$br][$r1col]) ) $set( $br, $r1col, '', null, 11 );
        if ( ! isset($rows[$er][$r1col]) ) $set( $er, $r1col, '', null, 11 );
    }

    // ── Assemble XML ──────────────────────────────────────────────────────
    $NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $x .= '<worksheet xmlns="'.$NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $x .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
    $x .= '<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>';
    $x .= '</sheetView></sheetViews>';
    $x .= '<sheetFormatPr baseColWidth="8" defaultRowHeight="16"/>';

    // Column widths
    $x .= '<cols>';
    if ( $hdnum )     $x .= '<col min="'.($col_draw+1).'"   max="'.($col_draw+1).'"   width="8"  customWidth="1"/>';
    if ( $has_prelim) $x .= '<col min="'.($col_prelim+1).'" max="'.($col_prelim+1).'" width="22" customWidth="1"/>';
    $x .= '<col min="'.($col_main0+1).'" max="'.$ncols.'" width="22" customWidth="1"/>';
    $x .= '</cols>';

    // sheetData
    $x .= '<sheetData>';
    $max_row = $ds + $half * 2;
    for ( $r = 1; $r <= $max_row; $r++ ) {
        $ht = $r === 1 ? ' ht="36" customHeight="1"' : ( $r <= 3 ? ' ht="18" customHeight="1"' : ' ht="16" customHeight="1"' );
        $x .= '<row r="'.$r.'"'.$ht.'>';
        for ( $c = 1; $c <= $ncols; $c++ ) {
            $ref  = lgw_xlsx_col($c-1).$r;
            $cell = $rows[$r][$c] ?? null;
            if ( $cell === null ) { $x .= '<c r="'.$ref.'"/>'; continue; }
            $t = $cell['t']; $v = $cell['v']; $s = $cell['s'];
            if      ( $t === 's' ) $x .= '<c r="'.$ref.'" t="s" s="'.$s.'"><v>'.$v.'</v></c>';
            elseif  ( $t === 'n' ) $x .= '<c r="'.$ref.'" s="'.$s.'"><v>'.$v.'</v></c>';
            else                   $x .= '<c r="'.$ref.'" s="'.$s.'"/>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';

    if ( ! empty($merges) ) {
        $x .= '<mergeCells count="'.count($merges).'">';
        foreach ( $merges as $m ) $x .= '<mergeCell ref="'.$m.'"/>';
        $x .= '</mergeCells>';
    }
    $x .= '<pageMargins left="0.75" right="0.75" top="1" bottom="1" header="0.5" footer="0.5"/>';
    $x .= '</worksheet>';
    return $x;
}

function lgw_xlsx_empty_sheet( $title ) {
    $NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="'.$NS.'"><sheetData>'
         . '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
         . '</sheetData></worksheet>';
}

// ── styles.xml ────────────────────────────────────────────────────────────────
function lgw_xlsx_styles() {
    $NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $tb = '<border><left style="thin"><color rgb="FFB0B0B0"/></left><right style="thin"><color rgb="FFB0B0B0"/></right><top style="thin"><color rgb="FFB0B0B0"/></top><bottom style="thin"><color rgb="FFB0B0B0"/></bottom><diagonal/></border>';
    $nb = '<border><left/><right/><top/><bottom/><diagonal/></border>';
    $ca = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    $ce = '<alignment horizontal="center" vertical="center"/>';
    $ra = '<alignment horizontal="right"  vertical="center"/>';
    $la = '<alignment horizontal="left"   vertical="center" wrapText="1"/>';

    $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $x .= '<styleSheet xmlns="'.$NS.'">';
    $x .= '<numFmts count="1"><numFmt numFmtId="164" formatCode="DD/MM/YYYY"/></numFmts>';
    $x .= '<fonts count="7">'
        . '<font><sz val="11"/><name val="Arial"/></font>'                                 // 0 default
        . '<font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'      // 1 title
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'      // 2 header
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Arial"/></font>'      // 3 date
        . '<font><b/><sz val="11"/><color rgb="FF38761D"/><name val="Arial"/></font>'      // 4 draw num
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Arial"/></font>'      // 5 slot text
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Arial"/></font>'          // 6 prelim team
        . '</fonts>';
    $x .= '<fills count="10">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFCF2E2E"/></patternFill></fill>'  // 2 red
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF073763"/></patternFill></fill>'  // 3 dark-blue
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/></patternFill></fill>'  // 4 yellow
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFCCCCCC"/></patternFill></fill>'  // 5 R1 grey
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>'  // 6 R2 lt-green
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDAE3F3"/></patternFill></fill>'  // 7 R3 lt-blue
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFB7B7B7"/></patternFill></fill>'  // 8 SF grey
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'  // 9 Final white
        . '</fills>';
    $x .= '<borders count="2">'.$nb.$tb.'</borders>';
    $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $x .= '<cellXfs count="14">'
        . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                     // 0 default
        . '<xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1">'.$ca.'</xf>'                              // 1 title
        . '<xf numFmtId="0"   fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1">'.$ce.'</xf>'                              // 2 round name
        . '<xf numFmtId="0"   fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1">'.$ce.'</xf>'                              // 3 date text
        . '<xf numFmtId="164" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1">'.$ce.'</xf>'        // 4 date number
        . '<xf numFmtId="0"   fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1">'.$ra.'</xf>'                                            // 5 draw num
        . '<xf numFmtId="0"   fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$ca.'</xf>'              // 6 slot R1
        . '<xf numFmtId="0"   fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$ca.'</xf>'              // 7 slot R2
        . '<xf numFmtId="0"   fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$ca.'</xf>'              // 8 slot R3/QF
        . '<xf numFmtId="0"   fontId="5" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$ca.'</xf>'              // 9 slot SF
        . '<xf numFmtId="0"   fontId="5" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$ca.'</xf>'              // 10 slot Final
        . '<xf numFmtId="0"   fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                                       // 11 empty R1 filler
        . '<xf numFmtId="0"   fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'                                                       // 12 red filler
        . '<xf numFmtId="0"   fontId="6" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'.$la.'</xf>'              // 13 prelim team name
        . '</cellXfs>';
    $x .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $x .= '</styleSheet>';
    return $x;
}

// ── Assemble and send xlsx ─────────────────────────────────────────────────────
function lgw_xlsx_send( $sheets, $filename ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_die( 'PHP ZipArchive extension is required for export.', 500 );
    }

    $sst     = array();
    $sst_idx = array();
    $ws_xmls = array();
    foreach ( $sheets as $sheet ) {
        $ws_xmls[] = lgw_xlsx_sheet( $sheet, $sst, $sst_idx );
    }

    $NS_SS  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $NS_CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';

    $cnt = count( $sst );
    $sst_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<sst xmlns="'.$NS_SS.'" count="'.$cnt.'" uniqueCount="'.$cnt.'">';
    foreach ( $sst as $sv ) {
        $sst_xml .= '<si><t xml:space="preserve">'.htmlspecialchars($sv,ENT_XML1,'UTF-8').'</t></si>';
    }
    $sst_xml .= '</sst>';

    $wb_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wb_xml .= '<workbook xmlns="'.$NS_SS.'" xmlns:r="'.$NS_REL.'">';
    $wb_xml .= '<bookViews><workbookView activeTab="0"/></bookViews><sheets>';
    foreach ( $sheets as $i => $sh ) {
        $wb_xml .= '<sheet name="'.htmlspecialchars($sh['name'],ENT_XML1,'UTF-8').'" sheetId="'.($i+1).'" r:id="rId'.($i+1).'"/>';
    }
    $wb_xml .= '</sheets><calcPr calcId="124519" fullCalcOnLoad="1"/></workbook>';

    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="'.$NS_PKG.'">';
    foreach ( $sheets as $i => $_ ) {
        $wb_rels .= '<Relationship Id="rId'.($i+1).'" Type="'.$NS_REL.'/worksheet" Target="worksheets/sheet'.($i+1).'.xml"/>';
    }
    $wb_rels .= '<Relationship Id="rIdSST" Type="'.$NS_REL.'/sharedStrings" Target="sharedStrings.xml"/>'
              . '<Relationship Id="rIdSty" Type="'.$NS_REL.'/styles" Target="styles.xml"/>'
              . '</Relationships>';

    $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<Types xmlns="'.$NS_CT.'">'
         . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
         . '<Default Extension="xml"  ContentType="application/xml"/>'
         . '<Override PartName="/xl/workbook.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
         . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
         . '<Override PartName="/xl/styles.xml"        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach ( $sheets as $i => $_ ) {
        $ct .= '<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';

    $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Relationships xmlns="'.$NS_PKG.'">'
               . '<Relationship Id="rId1" Type="'.$NS_REL.'/officeDocument" Target="xl/workbook.xml"/>'
               . '</Relationships>';

    $tmp = tempnam( sys_get_temp_dir(), 'lgw_xlsx_' );
    $zip = new ZipArchive();
    if ( $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        @unlink($tmp); wp_die('Could not create xlsx file.', 500);
    }
    $zip->addFromString( '[Content_Types].xml',        $ct );
    $zip->addFromString( '_rels/.rels',                $root_rels );
    $zip->addFromString( 'xl/workbook.xml',            $wb_xml );
    $zip->addFromString( 'xl/_rels/workbook.xml.rels', $wb_rels );
    $zip->addFromString( 'xl/sharedStrings.xml',       $sst_xml );
    $zip->addFromString( 'xl/styles.xml',              lgw_xlsx_styles() );
    foreach ( $ws_xmls as $i => $xml ) {
        $zip->addFromString( 'xl/worksheets/sheet'.($i+1).'.xml', $xml );
    }
    $zip->close();

    $bytes = file_get_contents( $tmp );
    @unlink( $tmp );

    if ( ! $bytes || strlen($bytes) < 500 ) wp_die('Export failed: empty file.', 500);

    while ( ob_get_level() ) ob_end_clean();
    header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
    header( 'Content-Disposition: attachment; filename="'.$filename.'"' );
    header( 'Content-Length: '.strlen($bytes) );
    header( 'Cache-Control: no-cache, must-revalidate' );
    header( 'Pragma: no-cache' );
    echo $bytes;
    exit;
}
