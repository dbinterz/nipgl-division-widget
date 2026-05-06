/* LGW Group Stage Championships JS — v7.2.9 */
(function () {
    'use strict';

    var ajaxUrl   = (typeof lgwGchampData !== 'undefined') ? lgwGchampData.ajaxUrl : '';
    var nonce     = (typeof lgwGchampData !== 'undefined') ? lgwGchampData.nonce   : '';
    var canScore  = (typeof lgwGchampData !== 'undefined') ? !!lgwGchampData.canScore : false;

    // ── Day-level tabs ────────────────────────────────────────────────────────
    document.querySelectorAll('.lgw-gchamp-wrap').forEach(function (wrap) {
        var champId = wrap.getAttribute('data-gchamp-id');

        // Bind day tabs
        wrap.querySelectorAll('.lgw-gchamp-day-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var idx = tab.getAttribute('data-day-tab');
                wrap.querySelectorAll('.lgw-gchamp-day-tab').forEach(function (t) {
                    t.classList.toggle('active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                wrap.querySelectorAll('.lgw-gchamp-day-pane').forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-day-pane') === idx);
                });
                try { if (champId) sessionStorage.setItem('lgw_gchamp_day_' + champId, idx); } catch(e){}
            });
        });

        // Restore saved day tab
        try {
            var savedDay = champId ? sessionStorage.getItem('lgw_gchamp_day_' + champId) : null;
            if (savedDay !== null) {
                var savedTab = wrap.querySelector('.lgw-gchamp-day-tab[data-day-tab="' + savedDay + '"]');
                if (savedTab) savedTab.click();
            }
        } catch(e) {}

        // Bind sub-tabs (Groups | Knockout) — per day pane
        wrap.querySelectorAll('.lgw-gchamp-day-pane').forEach(function (pane) {
            var dayIdx = pane.getAttribute('data-day-pane');
            pane.querySelectorAll('.lgw-gchamp-sub-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    if (tab.classList.contains('locked')) return;
                    var target = tab.getAttribute('data-sub-pane');
                    pane.querySelectorAll('.lgw-gchamp-sub-tab').forEach(function (t) {
                        t.classList.toggle('active', t === tab);
                    });
                    pane.querySelectorAll('.lgw-gchamp-sub-pane').forEach(function (p) {
                        p.classList.toggle('active', p.getAttribute('data-sub-pane') === target);
                    });
                    try { if (champId) sessionStorage.setItem('lgw_gchamp_sub_' + champId + '_' + dayIdx, target); } catch(e){}
                });
            });
            // Restore sub-tab
            try {
                var savedSub = champId ? sessionStorage.getItem('lgw_gchamp_sub_' + champId + '_' + dayIdx) : null;
                if (savedSub) {
                    var st = pane.querySelector('.lgw-gchamp-sub-tab[data-sub-pane="' + savedSub + '"]');
                    if (st && !st.classList.contains('locked')) st.click();
                }
            } catch(e){}
        });
    });

    // ── Fixture toggle ────────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lgw-gchamp-fixtures-toggle');
        if (!btn) return;
        var body = btn.nextElementSibling;
        if (!body) return;
        var open = body.classList.toggle('open');
        btn.classList.toggle('open', open);
    });

    // ── Score entry helpers ───────────────────────────────────────────────────
    function showForm(entry) {
        var form    = entry.querySelector('.lgw-gchamp-score-form');
        var display = entry.querySelector('.lgw-gchamp-score-display');
        var addBtn  = entry.querySelector('.lgw-gchamp-score-add-btn');
        var editBtn = entry.querySelector('.lgw-gchamp-score-edit-btn');
        if (form)    form.style.display    = 'flex';
        if (display) display.style.display = 'none';
        if (addBtn)  addBtn.style.display  = 'none';
        if (editBtn) editBtn.style.display = 'none';
        var h = entry.querySelector('.lgw-gchamp-score-h');
        if (h) { h.focus(); h.select(); }
    }

    function hideForm(entry) {
        var form    = entry.querySelector('.lgw-gchamp-score-form');
        var display = entry.querySelector('.lgw-gchamp-score-display');
        var addBtn  = entry.querySelector('.lgw-gchamp-score-add-btn');
        var editBtn = entry.querySelector('.lgw-gchamp-score-edit-btn');
        if (form)    form.style.display    = 'none';
        if (display) display.style.display = '';
        if (addBtn)  addBtn.style.display  = '';
        if (editBtn) editBtn.style.display = '';
    }

    function showError(row, msg) {
        var err = row.nextElementSibling;
        if (err && err.classList.contains('lgw-gchamp-row-err')) err.remove();
        var div = document.createElement('div');
        div.className = 'lgw-gchamp-row-err';
        div.style.cssText = 'padding:4px 10px;font-size:11px;color:#dc2626;background:#fff5f5;border-bottom:1px solid #fca5a5';
        div.textContent = msg;
        row.parentNode.insertBefore(div, row.nextSibling);
        setTimeout(function(){ div.remove(); }, 4000);
    }

    // ── Group score entry ─────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var addBtn  = e.target.closest('.lgw-gchamp-score-add-btn');
        var editBtn = e.target.closest('.lgw-gchamp-score-edit-btn');
        var saveBtn = e.target.closest('.lgw-gchamp-score-save-btn');
        var clearBtn= e.target.closest('.lgw-gchamp-score-clear-btn');
        var cancelBtn=e.target.closest('.lgw-gchamp-score-cancel-btn');

        if (addBtn || editBtn) {
            var entry = (addBtn || editBtn).closest('.lgw-gchamp-score-entry');
            if (entry) showForm(entry);
            return;
        }
        if (cancelBtn) {
            var entry = cancelBtn.closest('.lgw-gchamp-score-entry');
            if (entry) hideForm(entry);
            return;
        }
        if (saveBtn || clearBtn) {
            var entry = (saveBtn||clearBtn).closest('.lgw-gchamp-score-entry');
            var row   = entry ? entry.closest('.lgw-gchamp-fixture-row') : null;
            if (!row) return;
            submitGroupScore(row, entry, !!clearBtn);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        var inp = e.target.closest('.lgw-gchamp-score-h, .lgw-gchamp-score-a');
        if (!inp) return;
        e.preventDefault();
        var entry = inp.closest('.lgw-gchamp-score-entry');
        var row   = entry ? entry.closest('.lgw-gchamp-fixture-row') : null;
        if (row) submitGroupScore(row, entry, false);
    });

    function submitGroupScore(row, entry, clear) {
        var wrap    = row.closest('.lgw-gchamp-wrap');
        var champId = wrap ? wrap.getAttribute('data-gchamp-id') : '';
        var posKey  = row.getAttribute('data-pos-key');
        var dayId   = row.getAttribute('data-day-id');
        var groupId = row.getAttribute('data-group-id');
        var hInput  = entry.querySelector('.lgw-gchamp-score-h');
        var aInput  = entry.querySelector('.lgw-gchamp-score-a');
        var saving  = entry.querySelector('.lgw-gchamp-score-saving');

        if (!clear) {
            if (!hInput.value.trim() || !aInput.value.trim()) { showError(row,'Enter both scores.'); return; }
            if (parseInt(hInput.value)<0||parseInt(aInput.value)<0) { showError(row,'Scores must be 0 or above.'); return; }
        }

        entry.querySelector('.lgw-gchamp-score-form').style.display = 'none';
        if (saving) saving.style.display = 'inline';

        var fd = new FormData();
        fd.append('action','lgw_gchamp_save_score'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('group_id',groupId); fd.append('pos_key',posKey);
        fd.append('context','group');
        if (clear) fd.append('clear','1');
        else { fd.append('home_score',hInput.value); fd.append('away_score',aInput.value); }

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if (saving) saving.style.display='none';
            if (!data.success) { entry.querySelector('.lgw-gchamp-score-form').style.display='flex'; showError(row,data.data||'Error saving score.'); return; }
            if (clear) { hInput.value=''; aInput.value=''; }
            // Update displayed score on the row
            updateGroupFixtureRow(row, data.data, clear);
            // Update standings table in this group card
            if (data.data && data.data.standings) {
                var card = row.closest('.lgw-gchamp-group-card');
                if (card) updateStandingsTable(card, data.data.standings);
            }
            // Reload if day just completed, KO was just seeded, or KO bracket changed (reseed after unlock edit)
            var dayPane    = row.closest('[data-day-pane]');
            var hasKoWrap  = dayPane ? !!dayPane.querySelector('[data-sub-pane="knockout"] .lgw-gchamp-ko-wrap') : false;
            var justSeeded = data.data && data.data.ko_seeded && !hasKoWrap;
            var koChanged  = data.data && data.data.ko_seeded && hasKoWrap; // bracket was reseeded
            var justDone   = data.data && data.data.day_complete;
            if (justDone || justSeeded || koChanged) {
                setTimeout(function(){ location.reload(); }, 800);
            }
        }).catch(function(err){ if(saving)saving.style.display='none'; entry.querySelector('.lgw-gchamp-score-form').style.display='flex'; showError(row,'Request failed: '+err.message); });
    }

    function updateGroupFixtureRow(row, data, clear) {
        var entry   = row.querySelector('.lgw-gchamp-score-entry');
        var display = entry ? entry.querySelector('.lgw-gchamp-score-display') : null;
        var addBtn  = entry ? entry.querySelector('.lgw-gchamp-score-add-btn')  : null;
        var editBtn = entry ? entry.querySelector('.lgw-gchamp-score-edit-btn') : null;
        var hInput  = entry ? entry.querySelector('.lgw-gchamp-score-h') : null;
        var aInput  = entry ? entry.querySelector('.lgw-gchamp-score-a') : null;

        if (clear) {
            if (display) display.style.display = 'none';
            if (addBtn)  { addBtn.style.display=''; }
            if (editBtn) editBtn.remove();
            row.classList.remove('lgw-gf-home-win','lgw-gf-away-win');
            var cb = entry ? entry.querySelector('.lgw-gchamp-score-clear-btn') : null;
            if (cb) cb.remove();
        } else {
            if (display) {
                var hs = hInput ? parseInt(hInput.value) : 0;
                var as = aInput ? parseInt(aInput.value) : 0;
                display.innerHTML = '<span class="lgw-gchamp-fixture-score-h">'+hs+'</span>&ndash;<span class="lgw-gchamp-fixture-score-a">'+as+'</span>';
                display.style.display = '';
            }
            if (addBtn)  addBtn.style.display  = 'none';
            if (!editBtn && entry) {
                var eb = document.createElement('button');
                eb.type='button'; eb.className='lgw-gchamp-score-edit-btn'; eb.textContent='✏';
                entry.insertBefore(eb, entry.querySelector('.lgw-gchamp-score-form'));
            }
            // Win/loss class
            if (hInput && aInput) {
                var hs2 = parseInt(hInput.value), as2 = parseInt(aInput.value);
                row.classList.toggle('lgw-gf-home-win', hs2 > as2);
                row.classList.toggle('lgw-gf-away-win', as2 > hs2);
            }
        }
    }

    // ── Update standings table ───────────────────────────────────────────────
    function updateStandingsTable(card, standings) {
        var tbody = card.querySelector('.lgw-gchamp-standings tbody');
        if (!tbody || !standings) return;
        standings.forEach(function(row, pos) {
            var tr = tbody.rows[pos];
            if (!tr) return;
            var diff = row.sf - row.sa;
            var diffStr = (diff > 0 ? '+' : '') + diff;
            var diffCls = diff > 0 ? 'lgw-gs-diff-pos' : (diff < 0 ? 'lgw-gs-diff-neg' : 'lgw-gs-diff-zero');
            // Update each cell by class
            var cells = {
                '.lgw-gs-num:nth-of-type(3)': row.p,
                '.lgw-gs-num:nth-of-type(4)': row.w,
                '.lgw-gs-num:nth-of-type(5)': row.d,
                '.lgw-gs-num:nth-of-type(6)': row.l,
            };
            // Simpler: update by index (pos 2=P,3=W,4=D,5=L,6=SF,7=SA,8=diff,9=pts)
            var tds = tr.querySelectorAll('td');
            if (tds.length >= 10) {
                tds[2].textContent = row.p;
                tds[3].textContent = row.w;
                tds[4].textContent = row.d;
                tds[5].textContent = row.l;
                tds[6].textContent = row.sf;
                tds[7].textContent = row.sa;
                tds[8].textContent = diffStr;
                tds[8].className   = 'lgw-gs-diff ' + diffCls;
                tds[9].textContent = row.pts;
            }
            // Row qualify highlight — re-sort visually would need DOM move, skip for now
        });
        // Update progress count in group header
        var progress = card.querySelector('.lgw-gchamp-group-progress');
        if (progress) {
            var total = card.querySelectorAll('.lgw-gchamp-fixture-row').length;
            var played = card.querySelectorAll('.lgw-gchamp-fixture-row.lgw-gf-home-win, .lgw-gchamp-fixture-row.lgw-gf-away-win').length;
            // Count drawn games too — simpler to just count rows with a score display visible
            var scoredCount = 0;
            card.querySelectorAll('.lgw-gchamp-fixture-row').forEach(function(r) {
                var disp = r.querySelector('.lgw-gchamp-score-display, .lgw-gchamp-fixture-score:not(.lgw-gchamp-fixture-score-tbd)');
                if (disp && disp.style.display !== 'none') scoredCount++;
            });
            progress.textContent = scoredCount + '/' + total;
        }
    }

    // ── KO score entry ────────────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var koBtn    = e.target.closest('.lgw-gchamp-ko-score-btn, .lgw-gchamp-ko-score-edit-btn');
        var koSave   = e.target.closest('.lgw-gchamp-ko-save-btn');
        var koClear  = e.target.closest('.lgw-gchamp-ko-clear-btn');
        var koCancel = e.target.closest('.lgw-gchamp-ko-cancel-btn');

        if (koBtn) {
            var scoreEntry = koBtn.closest('.lgw-gchamp-ko-score-entry');
            if (!scoreEntry) return;
            koBtn.style.display = 'none';
            var form = scoreEntry.querySelector('.lgw-gchamp-ko-score-form');
            if (form) { form.style.display='flex'; var h=form.querySelector('.lgw-gchamp-ko-score-h'); if(h){h.focus();h.select();} }
            return;
        }
        if (koCancel) {
            var scoreEntry = koCancel.closest('.lgw-gchamp-ko-score-entry');
            if (!scoreEntry) return;
            scoreEntry.querySelector('.lgw-gchamp-ko-score-form').style.display='none';
            var btn=scoreEntry.querySelector('.lgw-gchamp-ko-score-btn,.lgw-gchamp-ko-score-edit-btn');
            if(btn) btn.style.display='';
            return;
        }
        if (koSave || koClear) {
            var scoreEntry = (koSave||koClear).closest('.lgw-gchamp-ko-score-entry');
            var match      = scoreEntry ? scoreEntry.closest('.lgw-gchamp-ko-match') : null;
            if (!match) return;
            submitKoScore(match, scoreEntry, !!koClear);
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key!=='Enter') return;
        var inp = e.target.closest('.lgw-gchamp-ko-score-h, .lgw-gchamp-ko-score-a');
        if (!inp) return;
        e.preventDefault();
        var scoreEntry = inp.closest('.lgw-gchamp-ko-score-entry');
        var match      = scoreEntry ? scoreEntry.closest('.lgw-gchamp-ko-match') : null;
        if (match) submitKoScore(match, scoreEntry, false);
    });

    function submitKoScore(matchEl, scoreEntry, clear) {
        var wrap    = matchEl.closest('.lgw-gchamp-wrap');
        var koWrap  = matchEl.closest('.lgw-gchamp-ko-wrap');
        var champId = wrap    ? wrap.getAttribute('data-gchamp-id') : '';
        var dayId   = koWrap  ? koWrap.getAttribute('data-day-id')  : matchEl.getAttribute('data-day-id');
        var round   = matchEl.getAttribute('data-round');
        var match   = matchEl.getAttribute('data-match');
        var hInput  = scoreEntry.querySelector('.lgw-gchamp-ko-score-h');
        var aInput  = scoreEntry.querySelector('.lgw-gchamp-ko-score-a');
        var saving  = scoreEntry.querySelector('.lgw-gchamp-ko-saving');
        var form    = scoreEntry.querySelector('.lgw-gchamp-ko-score-form');

        if (!clear) {
            if (!hInput.value.trim()||!aInput.value.trim()) return;
            if (parseInt(hInput.value)<0||parseInt(aInput.value)<0) return;
        }

        if (form)   form.style.display   = 'none';
        if (saving) saving.style.display = 'inline';

        var fd = new FormData();
        fd.append('action','lgw_gchamp_save_score'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('context','ko'); fd.append('ko_round',round); fd.append('ko_match',match);
        fd.append('group_id','-1'); fd.append('pos_key','ko');
        if (clear) fd.append('clear','1');
        else { fd.append('home_score',hInput.value); fd.append('away_score',aInput.value); }

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            if (saving) saving.style.display='none';
            if (!data.success) { if(form)form.style.display='flex'; alert('Error: '+(data.data||'Unknown')); return; }
            // Reload to reflect bracket advancement
            location.reload();
        }).catch(function(err){ if(saving)saving.style.display='none'; if(form)form.style.display='flex'; alert('Failed: '+err.message); });
    }

    // ── Auto-seed KO on page load for completed days without a bracket ────────
    if (window.lgwGchampData && lgwGchampData.isAdmin) {
        document.querySelectorAll('.lgw-gchamp-day-pane[data-seed-needed="1"]').forEach(function(pane) {
            var dayIdx = pane.getAttribute('data-day-pane');
            var wrap   = pane.closest('.lgw-gchamp-wrap');
            var champId= wrap ? wrap.getAttribute('data-gchamp-id') : '';
            if (!champId) return;

            // Show a seeding indicator in the KO sub-tab
            var koTab = pane.querySelector('.lgw-gchamp-sub-tab[data-sub-pane="knockout"]');
            if (koTab) koTab.textContent = '🏆 Knockout (seeding…)';

            var fd = new FormData();
            fd.append('action','lgw_gchamp_seed_day_ko'); fd.append('nonce',nonce);
            fd.append('champ_id',champId); fd.append('day_id',dayIdx);

            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if (data.success) {
                    location.reload();
                } else {
                    if (koTab) koTab.textContent = '🏆 Knockout';
                    console.warn('lgw_gchamp_seed_day_ko failed:', data.data);
                }
            }).catch(function(err){
                if (koTab) koTab.textContent = '🏆 Knockout';
                console.warn('lgw_gchamp_seed_day_ko error:', err);
            });
        });
    }

    // ── Group lock / unlock ───────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.lgw-gchamp-group-lock-btn');
        if (!btn || btn.disabled) return;

        var koHasScores = btn.getAttribute('data-ko-has-scores') === '1';
        if (koHasScores) return; // button is disabled, but just in case

        var locked  = btn.getAttribute('data-locked') === '1';
        var newLock = !locked;
        var dayId   = btn.getAttribute('data-day-id');
        var groupId = btn.getAttribute('data-group-id');
        var wrap    = btn.closest('.lgw-gchamp-wrap');
        var champId = wrap ? wrap.getAttribute('data-gchamp-id') : '';

        var label = newLock ? 'Lock this group? Score entry will be disabled.' : 'Unlock this group for editing?';
        if (!confirm(label)) return;

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action','lgw_gchamp_toggle_group_lock'); fd.append('nonce',nonce);
        fd.append('champ_id',champId); fd.append('day_id',dayId);
        fd.append('group_id',groupId); fd.append('lock', newLock ? '1' : '0');

        fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
            btn.disabled = false;
            if (!data.success) { alert('Error: '+(data.data||'Unknown')); return; }
            // Update button state
            btn.setAttribute('data-locked', data.data.locked ? '1' : '0');
            btn.textContent = data.data.locked ? '🔒' : '🔓';
            btn.title = data.data.locked ? 'Unlock group for editing' : 'Lock group scores';
            // Show/hide score entry buttons on all fixture rows in this group
            var card = btn.closest('.lgw-gchamp-group-card');
            if (card) {
                card.querySelectorAll('.lgw-gchamp-score-add-btn, .lgw-gchamp-score-edit-btn').forEach(function(b) {
                    b.style.display = data.data.locked ? 'none' : '';
                });
            }
        }).catch(function(err){ btn.disabled=false; alert('Failed: '+err.message); });
    });


})();
