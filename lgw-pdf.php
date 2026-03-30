<?php
/**
 * LGW Minimal PDF Generator
 * Pure PHP — no extensions required beyond standard PHP.
 * Generates formal scorecard PDFs.
 */

class NipglPdf {
    private $pages   = array();
    private $current = '';   // current page content buffer
    private $fonts   = array();
    private $font    = 'Helvetica';
    private $style   = '';   // B, I, U combinations
    private $size    = 10;
    private $x       = 15;
    private $y       = 15;
    private $w       = 210;  // A4 width mm
    private $h       = 297;  // A4 height mm
    private $margin  = 15;
    private $line_h  = 6;    // default line height mm
    private $objects = array();
    private $obj_n   = 0;
    private $offsets = array();
    private $buffer  = '';
    private $page    = 0;
    private $fill_r  = 255; private $fill_g = 255; private $fill_b = 255;
    private $text_r  = 0;   private $text_g  = 0;  private $text_b  = 0;
    private $draw_r  = 0;   private $draw_g  = 0;  private $draw_b  = 0;

    // Core font widths table (Helvetica, approximate per-char in 1/1000 unit)
    private $cw = array();

    public function __construct() {
        // Helvetica approximate character widths (1/1000 of pt)
        $w = array_fill(0, 256, 556);
        // Adjust common chars
        foreach (array(32=>278,33=>278,34=>355,39=>222,40=>333,41=>333,
                       44=>278,46=>278,58=>278,59=>278,73=>222,74=>222,
                       76=>500,77=>667,87=>722,105=>222,106=>222,108=>222,
                       109=>833,119=>667) as $c=>$v) $w[$c]=$v;
        $this->cw = $w;
    }

    // ── Page management ───────────────────────────────────────────────────────

    public function AddPage() {
        if ($this->page > 0) $this->pages[] = $this->current;
        $this->page++;
        $this->current = '';
        $this->x = $this->margin;
        $this->y = $this->margin;
        // Page stream header
        $this->current .= "BT\n/F1 10 Tf\nET\n";
        // Reset colour state
        $this->SetFillColor(255,255,255);
        $this->SetTextColor(0,0,0);
        $this->SetDrawColor(0,0,0);
    }

    public function ClosePage() {
        $this->pages[] = $this->current;
    }

    // ── Colours ───────────────────────────────────────────────────────────────

    public function SetFillColor($r, $g, $b) {
        $this->fill_r=$r; $this->fill_g=$g; $this->fill_b=$b;
    }

    public function SetTextColor($r, $g, $b) {
        $this->text_r=$r; $this->text_g=$g; $this->text_b=$b;
    }

    public function SetDrawColor($r, $g, $b) {
        $this->draw_r=$r; $this->draw_g=$g; $this->draw_b=$b;
    }

    // ── Font ──────────────────────────────────────────────────────────────────

    public function SetFont($family, $style='', $size=0) {
        if ($size > 0) $this->size = $size;
        $this->style = strtoupper($style);
    }

    public function SetFontSize($size) { $this->size = $size; }

    // ── Position ──────────────────────────────────────────────────────────────

    public function SetX($x) { $this->x = $x; }
    public function SetY($y) { $this->y = $y; }
    public function SetXY($x,$y) { $this->x=$x; $this->y=$y; }
    public function GetX() { return $this->x; }
    public function GetY() { return $this->y; }

    // ── Drawing ───────────────────────────────────────────────────────────────

    public function Line($x1, $y1, $x2, $y2) {
        $op = $this->_mm2pt($x1).' '.$this->_h($y1).' m '
            . $this->_mm2pt($x2).' '.$this->_h($y2).' l S';
        $this->current .= $this->_color_draw() . $op . "\n";
    }

    public function Rect($x, $y, $w, $h, $style='D') {
        $op = ($style === 'F') ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $s  = '';
        if ($style === 'F' || $style === 'FD' || $style === 'DF') {
            $s .= $this->_color_fill();
        }
        $s .= $this->_color_draw();
        $s .= sprintf("%.2f %.2f %.2f %.2f re %s\n",
            $this->_mm2pt($x), $this->_h($y) - $this->_mm2pt($h),
            $this->_mm2pt($w), $this->_mm2pt($h), $op);
        $this->current .= $s;
    }

    // ── Text ──────────────────────────────────────────────────────────────────

    /**
     * Cell — renders a box of width $w, height $h with text $txt.
     * $border: 0=none, 1=all, or string of L,R,T,B
     * $align: L, C, R
     * $fill: true/false
     * $ln: 0=right, 1=newline, 2=below
     */
    public function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='L', $fill=false) {
        if ($h === 0) $h = $this->line_h;
        $s = '';

        if ($fill) {
            $s .= $this->_color_fill();
            $s .= sprintf("%.2f %.2f %.2f %.2f re f\n",
                $this->_mm2pt($this->x), $this->_h($this->y) - $this->_mm2pt($h),
                $this->_mm2pt($w), $this->_mm2pt($h));
        }

        if ($border) {
            $s .= $this->_color_draw();
            if ($border === 1) {
                $s .= sprintf("%.2f %.2f %.2f %.2f re S\n",
                    $this->_mm2pt($this->x), $this->_h($this->y) - $this->_mm2pt($h),
                    $this->_mm2pt($w), $this->_mm2pt($h));
            } else {
                $bx = $this->x; $by = $this->y;
                if (strpos($border,'L')!==false) $s .= sprintf("%.2f %.2f m %.2f %.2f l S\n",$this->_mm2pt($bx),$this->_h($by),$this->_mm2pt($bx),$this->_h($by+$h));
                if (strpos($border,'R')!==false) $s .= sprintf("%.2f %.2f m %.2f %.2f l S\n",$this->_mm2pt($bx+$w),$this->_h($by),$this->_mm2pt($bx+$w),$this->_h($by+$h));
                if (strpos($border,'T')!==false) $s .= sprintf("%.2f %.2f m %.2f %.2f l S\n",$this->_mm2pt($bx),$this->_h($by),$this->_mm2pt($bx+$w),$this->_h($by));
                if (strpos($border,'B')!==false) $s .= sprintf("%.2f %.2f m %.2f %.2f l S\n",$this->_mm2pt($bx),$this->_h($by+$h),$this->_mm2pt($bx+$w),$this->_h($by+$h));
            }
        }

        if ($txt !== '' && $txt !== null) {
            $txt   = (string)$txt;
            $tw    = $this->GetStringWidth($txt);
            if ($align === 'C') $dx = ($w - $tw) / 2;
            elseif ($align === 'R') $dx = $w - $tw - 1;
            else $dx = 1;
            $tx = $this->_mm2pt($this->x + $dx);
            $ty = $this->_h($this->y) - $this->_mm2pt($h) + $this->_mm2pt($h * 0.25);
            $s .= $this->_color_text();
            $s .= sprintf("/F%s %.1f Tf\n", $this->_font_key(), $this->size);
            $s .= sprintf("BT %.2f %.2f Td (%s) Tj ET\n", $tx, $ty, $this->_escape($txt));
        }

        $this->current .= $s;

        if ($ln === 0) $this->x += $w;
        elseif ($ln === 1) { $this->x = $this->margin; $this->y += $h; }
        elseif ($ln === 2) { $this->y += $h; }
    }

    public function Ln($h=0) {
        $this->x = $this->margin;
        $this->y += ($h > 0 ? $h : $this->line_h);
    }

    /**
     * MultiCell — word-wrapped cell
     */
    public function MultiCell($w, $h, $txt, $border=0, $align='L', $fill=false) {
        $lines = $this->_wrap_text($txt, $w - 2);
        foreach ($lines as $i => $line) {
            $b = ($i === 0 && $border) ? 'LTR' : ($border ? 'LR' : 0);
            $this->Cell($w, $h, $line, $b, 1, $align, $fill);
        }
        if ($border) $this->Cell($w, 0, '', 'LBR', 1);
    }

    public function GetStringWidth($txt) {
        $txt = (string)$txt;
        $w = 0;
        for ($i = 0; $i < strlen($txt); $i++) {
            $w += $this->cw[ord($txt[$i])] ?? 556;
        }
        return $w * $this->size / 1000;
    }

    // ── Output ────────────────────────────────────────────────────────────────

    public function Output() {
        $this->ClosePage();
        $out = '%PDF-1.4' . "\n";

        // Catalog and pages tree placeholders
        $page_ids   = array();
        $stream_ids = array();

        $n = 0;
        $offsets = array();

        // Object 1: Catalog
        $objs = array();
        $objs[1] = null; // placeholder — will be filled after we know pages obj id
        $objs[2] = null; // pages tree

        // Build font object (obj 3)
        $font_name = (strpos($this->style,'B')!==false) ? 'Helvetica-Bold' : 'Helvetica';
        $objs[3] = "<<\n/Type /Font\n/Subtype /Type1\n/BaseFont /$font_name\n/Encoding /WinAnsiEncoding\n>>";

        // Build page content + page objects
        $page_obj_ids    = array();
        $content_obj_ids = array();
        $next_id = 4;

        foreach ($this->pages as $pi => $pg_stream) {
            $content_id = $next_id++;
            $page_id    = $next_id++;
            $stream     = $pg_stream;
            $objs[$content_id] = "<<\n/Length " . strlen($stream) . "\n>>\nstream\n" . $stream . "\nendstream";
            $objs[$page_id]    = null; // fill after pages tree id known
            $page_obj_ids[]    = $page_id;
            $content_obj_ids[] = $content_id;
        }

        $pages_id = $next_id++;

        // Fill page objects
        foreach ($page_obj_ids as $i => $pid) {
            $objs[$pid] = "<<\n/Type /Page\n/Parent $pages_id 0 R\n"
                . "/MediaBox [0 0 595 842]\n"
                . "/Contents " . $content_obj_ids[$i] . " 0 R\n"
                . "/Resources <<\n  /Font <<\n    /F1 3 0 R\n    /FB 3 0 R\n  >>\n>>\n>>";
        }

        // Kids list
        $kids = implode(' 0 R ', $page_obj_ids) . ' 0 R';
        $objs[$pages_id] = "<<\n/Type /Pages\n/Kids [$kids]\n/Count " . count($page_obj_ids) . "\n>>";

        $catalog_id = $next_id++;
        $objs[$catalog_id] = "<<\n/Type /Catalog\n/Pages $pages_id 0 R\n>>";

        // Write all objects
        $body    = '';
        $offsets = array();
        $ids     = array_keys($objs);
        sort($ids);
        foreach ($ids as $id) {
            $offsets[$id] = strlen($out) + strlen($body);
            $body .= "$id 0 obj\n" . $objs[$id] . "\nendobj\n";
        }
        $out .= $body;

        // Cross-reference table
        $xref_offset = strlen($out);
        $max_id = max($ids);
        $out .= "xref\n0 " . ($max_id + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max_id; $i++) {
            if (isset($offsets[$i])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }

        // Trailer
        $out .= "trailer\n<<\n/Size " . ($max_id + 1) . "\n/Root $catalog_id 0 R\n>>\n";
        $out .= "startxref\n$xref_offset\n%%EOF\n";

        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function _mm2pt($mm) { return $mm * 2.8346; }
    private function _h($y)      { return $this->_mm2pt($this->h - $y); }

    private function _font_key() {
        return (strpos($this->style,'B') !== false) ? 'FB' : 'F1';
    }

    private function _color_fill() {
        return sprintf("%.3f %.3f %.3f rg\n",
            $this->fill_r/255, $this->fill_g/255, $this->fill_b/255);
    }
    private function _color_text() {
        return sprintf("%.3f %.3f %.3f rg\n",
            $this->text_r/255, $this->text_g/255, $this->text_b/255);
    }
    private function _color_draw() {
        return sprintf("%.3f %.3f %.3f RG\n",
            $this->draw_r/255, $this->draw_g/255, $this->draw_b/255);
    }

    private function _escape($s) {
        return str_replace(array('\\','(',')',"\r"), array('\\\\','\\(','\\)',''), $s);
    }

    private function _wrap_text($txt, $max_w) {
        $words = explode(' ', $txt);
        $lines = array();
        $line  = '';
        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            if ($this->GetStringWidth($test) <= $max_w) {
                $line = $test;
            } else {
                if ($line !== '') $lines[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: array('');
    }
}

// ── Scorecard PDF builder ─────────────────────────────────────────────────────

function lgw_build_scorecard_pdf($sc, $post_id) {
    $pdf = new NipglPdf();
    $pdf->AddPage();

    $pw      = 210 - 30; // usable width (margins 15 each side)
    $navy    = array(26, 46, 90);
    $navy2   = array(46, 74, 138);
    $ltblue  = array(208, 216, 238);
    $white   = array(255,255,255);
    $black   = array(0,0,0);
    $green   = array(212, 237, 218);
    $amber   = array(255, 243, 205);
    $red     = array(248, 215, 218);

    $status    = get_post_meta($post_id, 'lgw_sc_status',    true) ?: 'pending';
    $con_by    = get_post_meta($post_id, 'lgw_confirmed_by', true) ?: '';
    $sub_by    = get_post_meta($post_id, 'lgw_submitted_by', true) ?: '';
    $edited_at = get_post_meta($post_id, 'lgw_admin_edited', true) ?: '';

    // ── Header band ───────────────────────────────────────────────────────────
    $pdf->SetFillColor(...$navy);
    $pdf->SetTextColor(...$white);
    $pdf->SetFont('Helvetica','B', 14);
    $pdf->Rect(15, 15, $pw, 12, 'F');
    $pdf->SetXY(15, 15);
    $pdf->Cell($pw, 12, 'LGW Match Scorecard', 0, 1, 'C', false);

    // Division / venue / date band
    $pdf->SetFillColor(...$navy2);
    $pdf->SetFont('Helvetica','', 9);
    $pdf->SetXY(15, 27);
    $pdf->Rect(15, 27, $pw, 7, 'F');
    $meta_parts = array();
    if (!empty($sc['division']))    $meta_parts[] = $sc['division'];
    if (!empty($sc['competition'])) $meta_parts[] = $sc['competition'];
    if (!empty($sc['venue']))       $meta_parts[] = $sc['venue'];
    if (!empty($sc['date']))        $meta_parts[] = $sc['date'];
    $pdf->SetXY(15, 27);
    $pdf->Cell($pw, 7, implode('   |   ', $meta_parts), 0, 1, 'C', false);

    // ── Teams ─────────────────────────────────────────────────────────────────
    $pdf->SetTextColor(...$black);
    $pdf->SetFillColor(...$ltblue);
    $pdf->SetFont('Helvetica','B', 13);
    $pdf->SetXY(15, 38);
    $hw = $pw / 2 - 5;
    // Home
    $pdf->Rect(15, 38, $hw, 12, 'FD');
    $pdf->SetXY(15, 38);
    $pdf->Cell($hw, 12, $sc['home_team'] ?? '', 0, 0, 'C', false);
    // vs
    $pdf->SetFont('Helvetica','', 10);
    $pdf->SetXY(15 + $hw, 38);
    $pdf->Cell(10, 12, 'v', 0, 0, 'C', false);
    // Away
    $pdf->SetFont('Helvetica','B', 13);
    $pdf->Rect(15 + $hw + 10, 38, $hw, 12, 'FD');
    $pdf->SetXY(15 + $hw + 10, 38);
    $pdf->Cell($hw, 12, $sc['away_team'] ?? '', 0, 1, 'C', false);

    // ── Rinks table ───────────────────────────────────────────────────────────
    $y = 55;
    $pdf->SetY($y);

    // Column widths: Rink | Home Players | H Score | A Score | Away Players
    $cw = array(12, 68, 14, 14, 68);
    $ch = 7; // cell height

    // Header row
    $headers = array('Rink', 'Home Players', 'H', 'A', 'Away Players');
    $aligns  = array('C','L','C','C','L');
    $pdf->SetFillColor(...$navy);
    $pdf->SetTextColor(...$white);
    $pdf->SetFont('Helvetica','B', 9);
    $x = 15;
    foreach ($headers as $i => $hdr) {
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[$i], $ch, 'FD');
        $pdf->SetXY($x, $y);
        $pdf->Cell($cw[$i], $ch, $hdr, 0, 0, $aligns[$i], false);
        $x += $cw[$i];
    }
    $y += $ch;

    // Rink rows
    $pdf->SetTextColor(...$black);
    foreach (($sc['rinks'] ?? array()) as $ri => $rk) {
        $row_fill = ($ri % 2 === 0) ? array(249,249,249) : array(255,255,255);
        $pdf->SetFillColor(...$row_fill);
        $pdf->SetFont('Helvetica','B', 9);

        // Calculate row height based on player count
        $hp = $rk['home_players'] ?? array();
        $ap = $rk['away_players'] ?? array();
        $n_players = max(count($hp), count($ap), 1);
        $rh = max($ch, $n_players * 5 + 3);

        $x = 15;
        // Rink number
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[0], $rh, 'FD');
        $pdf->SetXY($x, $y);
        $pdf->Cell($cw[0], $rh, (string)$rk['rink'], 0, 0, 'C', false);
        $x += $cw[0];

        // Home players
        $pdf->SetFont('Helvetica','', 8);
        $hp_str = implode("\n", $hp);
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[1], $rh, 'FD');
        foreach ($hp as $pi => $pname) {
            $pdf->SetXY($x + 1, $y + 1 + $pi * 5);
            $pdf->Cell($cw[1] - 2, 5, $pname, 0, 0, 'L', false);
        }
        $x += $cw[1];

        // Home score
        $pdf->SetFont('Helvetica','B', 11);
        $hs = $rk['home_score'] ?? '';
        $as = $rk['away_score'] ?? '';
        $h_win = ($hs !== '' && $as !== '' && $hs > $as);
        $a_win = ($hs !== '' && $as !== '' && $as > $hs);
        if ($h_win) $pdf->SetFillColor(200, 230, 200); else $pdf->SetFillColor(...$row_fill);
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[2], $rh, 'FD');
        $pdf->SetXY($x, $y);
        $pdf->Cell($cw[2], $rh, $hs !== null ? (string)$hs : '', 0, 0, 'C', false);
        $x += $cw[2];

        // Away score
        if ($a_win) $pdf->SetFillColor(200, 230, 200); else $pdf->SetFillColor(...$row_fill);
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[3], $rh, 'FD');
        $pdf->SetXY($x, $y);
        $pdf->Cell($cw[3], $rh, $as !== null ? (string)$as : '', 0, 0, 'C', false);
        $x += $cw[3];

        // Away players
        $pdf->SetFont('Helvetica','', 8);
        $pdf->SetFillColor(...$row_fill);
        $pdf->SetXY($x, $y);
        $pdf->Rect($x, $y, $cw[4], $rh, 'FD');
        foreach ($ap as $pi => $pname) {
            $pdf->SetXY($x + 1, $y + 1 + $pi * 5);
            $pdf->Cell($cw[4] - 2, 5, $pname, 0, 0, 'L', false);
        }
        $y += $rh;
    }

    // ── Totals row ────────────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica','B', 10);
    $pdf->SetFillColor(...$ltblue);
    $pdf->SetTextColor(...$black);
    $tw_home = $cw[0] + $cw[1];
    $tw_mid  = $cw[2] + $cw[3];
    $tw_away = $cw[4];
    $y += 2;

    $pdf->Rect(15, $y, $tw_home, $ch, 'FD');
    $pdf->SetXY(15, $y);
    $pdf->Cell($tw_home, $ch, 'Total Shots', 0, 0, 'R', false);

    $ht = $sc['home_total'] ?? '?'; $at = $sc['away_total'] ?? '?';
    $h_win_t = is_numeric($ht) && is_numeric($at) && $ht > $at;
    $a_win_t = is_numeric($ht) && is_numeric($at) && $at > $ht;

    if ($h_win_t) $pdf->SetFillColor(180, 220, 180); else $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home, $y, $cw[2], $ch, 'FD');
    $pdf->SetXY(15 + $tw_home, $y);
    $pdf->Cell($cw[2], $ch, (string)$ht, 0, 0, 'C', false);

    if ($a_win_t) $pdf->SetFillColor(180, 220, 180); else $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home + $cw[2], $y, $cw[3], $ch, 'FD');
    $pdf->SetXY(15 + $tw_home + $cw[2], $y);
    $pdf->Cell($cw[3], $ch, (string)$at, 0, 0, 'C', false);

    $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home + $tw_mid, $y, $tw_away, $ch, 'FD');
    $pdf->SetXY(15 + $tw_home + $tw_mid, $y);
    $pdf->Cell($tw_away, $ch, 'Match Points', 0, 1, 'L', false);
    $y += $ch;

    // Points row
    $hp2 = $sc['home_points'] ?? '?'; $ap2 = $sc['away_points'] ?? '?';
    $h_win_p = is_numeric($hp2) && is_numeric($ap2) && $hp2 > $ap2;
    $a_win_p = is_numeric($hp2) && is_numeric($ap2) && $ap2 > $hp2;

    $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15, $y, $tw_home, $ch, 'FD');
    $pdf->SetXY(15, $y);
    $pdf->Cell($tw_home, $ch, 'Points', 0, 0, 'R', false);

    if ($h_win_p) $pdf->SetFillColor(180, 220, 180); else $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home, $y, $cw[2], $ch, 'FD');
    $pdf->SetXY(15 + $tw_home, $y);
    $pdf->Cell($cw[2], $ch, (string)$hp2, 0, 0, 'C', false);

    if ($a_win_p) $pdf->SetFillColor(180, 220, 180); else $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home + $cw[2], $y, $cw[3], $ch, 'FD');
    $pdf->SetXY(15 + $tw_home + $cw[2], $y);
    $pdf->Cell($cw[3], $ch, (string)$ap2, 0, 0, 'C', false);

    $pdf->SetFillColor(...$ltblue);
    $pdf->Rect(15 + $tw_home + $tw_mid, $y, $tw_away, $ch, 'FD');
    $pdf->SetXY(15 + $tw_home + $tw_mid, $y);
    $pdf->Cell($tw_away, $ch, '', 0, 1, 'L', false);
    $y += $ch + 6;

    // ── Status footer ─────────────────────────────────────────────────────────
    $status_colors = array(
        'confirmed' => $green,
        'pending'   => $amber,
        'disputed'  => $red,
    );
    $fc = $status_colors[$status] ?? $ltblue;
    $pdf->SetFillColor(...$fc);
    $pdf->SetTextColor(...$black);
    $pdf->SetFont('Helvetica','B', 9);
    $pdf->Rect(15, $y, $pw, 8, 'FD');
    $pdf->SetXY(15, $y);
    $status_text = ucfirst($status);
    if ($status === 'confirmed') {
        $status_text = 'Confirmed';
        if ($con_by) $status_text .= ' by ' . $con_by;
    } elseif ($status === 'pending') {
        $status_text = 'Pending — awaiting confirmation from ' . ($sub_by ? 'the other club' : 'both clubs');
    } elseif ($status === 'disputed') {
        $status_text = 'Disputed — under admin review';
    }
    $pdf->Cell($pw, 8, $status_text, 0, 1, 'C', false);
    $y += 8;

    if ($edited_at) {
        $pdf->SetFillColor(255, 243, 205);
        $pdf->SetFont('Helvetica','', 8);
        $pdf->Rect(15, $y, $pw, 6, 'FD');
        $pdf->SetXY(15, $y);
        $pdf->Cell($pw, 6, 'Amended by admin on ' . date('d M Y H:i', strtotime($edited_at)), 0, 1, 'C', false);
        $y += 6;
    }

    // ── Audit summary ─────────────────────────────────────────────────────────
    $audit_log = get_post_meta($post_id, 'lgw_audit_log', true) ?: array();
    if (!empty($audit_log)) {
        $y += 4;
        $pdf->SetFont('Helvetica','B', 8);
        $pdf->SetTextColor(...$black);
        $pdf->SetXY(15, $y);
        $pdf->Cell($pw, 5, 'Audit History', 0, 1, 'L', false);
        $pdf->SetFont('Helvetica','', 7);
        foreach (array_slice($audit_log, 0, 8) as $entry) {
            $ts   = date('d M Y H:i', strtotime($entry['ts']));
            $line = $ts . '  ' . strtoupper($entry['action']) . '  ' . $entry['user'] . '  — ' . $entry['note'];
            $pdf->SetXY(15, $y += 5);
            $pdf->Cell($pw, 5, $line, 0, 1, 'L', false);
        }
    }

    // ── Generated timestamp ───────────────────────────────────────────────────
    $pdf->SetFont('Helvetica','', 7);
    $pdf->SetTextColor(150,150,150);
    $pdf->SetXY(15, 285);
    $pdf->Cell($pw, 5, 'Generated ' . date('d M Y H:i') . ' by LGW Division Widget', 0, 0, 'R', false);

    return $pdf->Output();
}
