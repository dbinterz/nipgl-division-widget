<?php
/**
 * LGW Shared Draw Library - v6.4.25
 *
 * Shared bracket-building logic used by both the Cup and National Championships.
 * Callers supply entry data and behaviour callbacks; this file owns the bracket
 * geometry, animation-pair construction, and skeleton-round assembly.
 *
 * Public API:
 *   lgw_draw_build_bracket( array $numbered_entries, array $options ) : array|null
 *   lgw_draw_default_rounds( int $n ) : string[]
 *   lgw_draw_cup_club( string $team_name ) : string
 *
 * $numbered_entries — flat array of ['name' => string, 'draw_num' => int]
 *   Must have at least 2 elements.
 *
 * $options keys:
 *   'get_club'        callable(string $name): string
 *                       Extract a comparable club key from an entry name.
 *                       Cup: strips team suffix ("Belmont A" -> "belmont").
 *                       Champ: reads club after comma ("Player, Club" -> "club").
 *
 *   'home_at_limit'   callable(string $club, array $home_counts): bool
 *                       Return true if $club has already reached its home limit
 *                       for the round. Cup: limit is 1. Champ: limit is 6 (or
 *                       multi-green override). $home_counts is read-only here;
 *                       the builder increments it after the swap decision.
 *
 *   'separate_prelim' callable(array &$entries): void   [optional]
 *                       In-place same-club separation pass over prelim teams.
 *
 *   'separate_r2'     callable(array &$r2_slots, array &$from_game): void   [optional]
 *                       In-place same-club separation pass over R2 bye slots.
 *
 *   'stored_rounds'   string[]   Admin-configured round names; used when count matches.
 *   'dates'           string[]   Round dates to embed in bracket.
 *   'r2_label'        string     Animation section-header label inserted before R2 pairs
 *                                when prelims exist. Defaults to 'Round 1 Draw'.
 *   'game_nums'       bool       Annotate matches with game_num / prev_game_* fields.
 *                                Required for champ winner propagation; not used by cup.
 *                                Default false.
 *
 * Returns:
 *   [
 *     'matches' => array[][]   indexed [round][match], each match is an assoc array,
 *     'pairs'   => array[]     animation sequence (home/away/bye + optional type/label),
 *     'rounds'  => string[]    round names in chronological order,
 *   ]
 *   or null if fewer than 2 entries supplied.
 */

// ── Public helpers ─────────────────────────────────────────────────────────────

/**
 * Extract the club prefix from a cup team name for home-conflict detection.
 * Strips a trailing single-letter or roman-numeral team suffix.
 * "Belmont A" -> "belmont", "Forth River B" -> "forth river", "Ards" -> "ards"
 */
function lgw_draw_cup_club( $team_name ) {
    $name     = trim( $team_name );
    $stripped = preg_replace( '/\s+([A-C]|[1-3]|II?I?)$/i', '', $name );
    return strtolower( trim( $stripped ?: $name ) );
}

/**
 * Generate sensible default round names for N entries.
 * Returns names in chronological order (earliest round first, Final last).
 */
function lgw_draw_default_rounds( $n ) {
    $bracket_size = 1;
    while ( $bracket_size < $n ) $bracket_size *= 2;
    $rounds_needed = intval( log( $bracket_size, 2 ) );

    $named = array( 'Final', 'Semi-Final', 'Quarter Final', 'Round of 16', 'Round of 32', 'Round of 64' );
    $names = array();
    for ( $i = 0; $i < $rounds_needed; $i++ ) {
        $from_end = $rounds_needed - 1 - $i;
        $names[]  = $named[ $from_end ] ?? ( 'Round ' . ( $i + 1 ) );
    }
    return $names;
}

// ── Core bracket builder ───────────────────────────────────────────────────────

/**
 * Build a complete single-elimination bracket from a flat numbered entry list.
 * See file-level docblock for full parameter and return-value documentation.
 *
 * @param array $numbered  Array of ['name' => string, 'draw_num' => int].
 * @param array $opts      Options -- see file-level docblock.
 * @return array|null
 */
function lgw_draw_build_bracket( array $numbered, array $opts ) {
    $n = count( $numbered );
    if ( $n < 2 ) return null;

    // Resolve options
    $get_club        = $opts['get_club']        ?? 'lgw_draw_cup_club';
    $home_at_limit   = $opts['home_at_limit']   ?? null;
    $separate_prelim = $opts['separate_prelim'] ?? null;
    $separate_r2     = $opts['separate_r2']     ?? null;
    $stored_rounds   = $opts['stored_rounds']   ?? array();
    $dates           = $opts['dates']           ?? array();
    $r2_label        = $opts['r2_label']        ?? 'Round 1 Draw';
    $use_game_nums   = $opts['game_nums']        ?? false;

    // ── Bracket geometry ───────────────────────────────────────────────────────
    $bracket_size = 1;
    while ( $bracket_size < $n ) $bracket_size *= 2;
    $half         = $bracket_size / 2;
    $prelim_count = $n - $half;

    $prelim_teams = array_slice( $numbered, 0, $prelim_count * 2 );
    $bye_teams    = array_slice( $numbered, $prelim_count * 2 );

    if ( $separate_prelim ) {
        call_user_func_array( $separate_prelim, array( &$prelim_teams ) );
    }

    // ── Round 1: prelim matches ────────────────────────────────────────────────
    $round1_matches = array();
    $pairs_for_anim = array();
    $home_counts_r1 = array();
    $game_counter   = 1; // only relevant when $use_game_nums

    for ( $i = 0; $i < count( $prelim_teams ); $i += 2 ) {
        $a = $prelim_teams[ $i ];
        $b = $prelim_teams[ $i + 1 ];

        if ( $home_at_limit ) {
            $ca = call_user_func( $get_club, $a['name'] );
            $cb = call_user_func( $get_club, $b['name'] );
            // Swap home/away if $a's club is at its limit and $b's is not
            if (
                call_user_func( $home_at_limit, $ca, $home_counts_r1 ) &&
                ! call_user_func( $home_at_limit, $cb, $home_counts_r1 ) &&
                $ca !== $cb
            ) {
                list( $a, $b )   = array( $b, $a );
                list( $ca, $cb ) = array( $cb, $ca );
            }
            $home_counts_r1[ $ca ] = ( $home_counts_r1[ $ca ] ?? 0 ) + 1;
        }

        $match = array(
            'home'          => $a['name'],
            'away'          => $b['name'],
            'home_score'    => null,
            'away_score'    => null,
            'draw_num_home' => $a['draw_num'],
            'draw_num_away' => $b['draw_num'],
            'bye'           => false,
        );
        if ( $use_game_nums ) {
            $match['game_num'] = $game_counter;
        }
        $round1_matches[] = $match;
        $pairs_for_anim[] = array( 'home' => $a['name'], 'away' => $b['name'], 'bye' => false );
        $game_counter++;
    }

    // ── R2 slot assembly: interleave prelim winner slots with bye entries ──────
    $r2_slots          = array_fill( 0, $half, null );
    $r2_slot_from_game = array_fill( 0, $half, null );
    $bye_cursor        = 0;

    if ( $prelim_count > 0 ) {
        $step             = $half / $prelim_count;
        $winner_positions = array();
        for ( $w = 0; $w < $prelim_count; $w++ ) {
            $winner_positions[] = (int) round( $w * $step );
        }
        $prelim_game_idx = 0;
        for ( $s = 0; $s < $half; $s++ ) {
            if ( in_array( $s, $winner_positions ) ) {
                if ( $use_game_nums ) {
                    // Prelim games are numbered 1..prelim_count
                    $r2_slot_from_game[ $s ] = 1 + $prelim_game_idx;
                }
                $prelim_game_idx++;
            } else {
                $r2_slots[ $s ] = $bye_teams[ $bye_cursor++ ];
            }
        }
    } else {
        for ( $s = 0; $s < $half; $s++ ) {
            $r2_slots[ $s ] = $bye_teams[ $bye_cursor++ ];
        }
    }

    if ( $separate_r2 ) {
        call_user_func_array( $separate_r2, array( &$r2_slots, &$r2_slot_from_game ) );
    }

    // ── Round 2 matches ────────────────────────────────────────────────────────
    $round2_matches = array();
    $home_counts_r2 = array();
    $r2_game_start  = $prelim_count + 1;

    for ( $i = 0; $i < $half; $i += 2 ) {
        $a = $r2_slots[ $i ];
        $b = $r2_slots[ $i + 1 ];

        if ( $a && $b && $home_at_limit ) {
            $ca = call_user_func( $get_club, $a['name'] );
            $cb = call_user_func( $get_club, $b['name'] );
            if (
                call_user_func( $home_at_limit, $ca, $home_counts_r2 ) &&
                ! call_user_func( $home_at_limit, $cb, $home_counts_r2 ) &&
                $ca !== $cb
            ) {
                list( $a, $b )   = array( $b, $a );
                list( $ca, $cb ) = array( $cb, $ca );
                if ( $use_game_nums ) {
                    // Keep from_game annotations aligned with the swapped slots
                    list( $r2_slot_from_game[ $i ], $r2_slot_from_game[ $i + 1 ] ) =
                        array( $r2_slot_from_game[ $i + 1 ], $r2_slot_from_game[ $i ] );
                }
            }
            $home_counts_r2[ $ca ] = ( $home_counts_r2[ $ca ] ?? 0 ) + 1;
        }

        $match = array(
            'home'          => $a ? $a['name'] : null,
            'away'          => $b ? $b['name'] : null,
            'home_score'    => null,
            'away_score'    => null,
            'draw_num_home' => $a ? $a['draw_num'] : null,
            'draw_num_away' => $b ? $b['draw_num'] : null,
            'bye'           => false,
        );
        if ( $use_game_nums ) {
            $match['game_num']       = $r2_game_start + intval( $i / 2 );
            $match['prev_game_home'] = ( $a === null ) ? $r2_slot_from_game[ $i ]     : null;
            $match['prev_game_away'] = ( $b === null ) ? $r2_slot_from_game[ $i + 1 ] : null;
        }
        $round2_matches[] = $match;
    }

    // ── Animation pairs for R2 ─────────────────────────────────────────────────
    $r2_has_drawn = false;
    foreach ( $round2_matches as $rm ) {
        if ( $rm['home'] || $rm['away'] ) { $r2_has_drawn = true; break; }
    }
    if ( $r2_has_drawn ) {
        if ( $prelim_count > 0 ) {
            $pairs_for_anim[] = array( 'type' => 'header', 'label' => $r2_label );
        }
        foreach ( $round2_matches as $rm ) {
            $pairs_for_anim[] = array(
                'home' => $rm['home'] ?? 'Prelim Winner',
                'away' => $rm['away'] ?? 'Prelim Winner',
                'bye'  => false,
            );
        }
    }

    // ── Round names ────────────────────────────────────────────────────────────
    if ( $prelim_count > 0 ) {
        $main_rounds = lgw_draw_default_rounds( $half );
        $total_r     = 1 + count( $main_rounds );
        $rounds      = ( ! empty( $stored_rounds ) && count( $stored_rounds ) === $total_r )
            ? $stored_rounds
            : array_merge( array( 'Preliminary Round' ), $main_rounds );
    } else {
        $total_r = intval( log( $bracket_size, 2 ) );
        $rounds  = ( ! empty( $stored_rounds ) && count( $stored_rounds ) === $total_r )
            ? $stored_rounds
            : lgw_draw_default_rounds( $n );
    }

    // ── Skeleton rounds (QF, SF, Final ...) ────────────────────────────────────
    $all_matches  = $prelim_count > 0 ? array( $round1_matches, $round2_matches ) : array( $round2_matches );
    $prev_count   = count( $round2_matches );
    $start_r      = $prelim_count > 0 ? 2 : 1;
    $prev_matches = $round2_matches;
    $next_game    = $use_game_nums ? ( $r2_game_start + $prev_count ) : 0;

    for ( $r = $start_r; $r < count( $rounds ); $r++ ) {
        $prev_count = intval( ceil( $prev_count / 2 ) );
        $rm_round   = array();
        for ( $m = 0; $m < $prev_count; $m++ ) {
            $skeleton = array(
                'home'          => null,
                'away'          => null,
                'home_score'    => null,
                'away_score'    => null,
                'draw_num_home' => null,
                'draw_num_away' => null,
                'bye'           => false,
            );
            if ( $use_game_nums ) {
                $feed_home = $prev_matches[ $m * 2 ]['game_num'] ?? null;
                $feed_away = isset( $prev_matches[ $m * 2 + 1 ] )
                    ? ( $prev_matches[ $m * 2 + 1 ]['game_num'] ?? null )
                    : null;
                $skeleton['game_num']       = $next_game++;
                $skeleton['prev_game_home'] = $feed_home;
                $skeleton['prev_game_away'] = $feed_away;
            }
            $rm_round[] = $skeleton;
        }
        $all_matches[] = $rm_round;
        $prev_matches  = $rm_round;
    }

    return array(
        'matches' => $all_matches,
        'pairs'   => $pairs_for_anim,
        'rounds'  => $rounds,
    );
}
