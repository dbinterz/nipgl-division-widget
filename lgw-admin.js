/* LGW Admin JS */
(function($){
    'use strict';

    // Row template for sponsors
    function newSponsorRow() {
        return '<tr class="lgw-sponsor-row">'
            + '<td><input type="text" name="lgw_sp_name[]" value="" placeholder="e.g. Acme Ltd" class="regular-text"></td>'
            + '<td>'
            + '<input type="text" name="lgw_sp_image[]" value="" placeholder="Image URL" class="regular-text lgw-image-url" readonly>'
            + '<button type="button" class="button lgw-pick-image">Choose Image</button>'
            + '</td>'
            + '<td><input type="text" name="lgw_sp_url[]" value="" placeholder="https://" class="regular-text"></td>'
            + '<td><img class="lgw-badge-preview" src="" style="display:none;height:40px;object-fit:contain;max-width:120px;"></td>'
            + '<td><button type="button" class="button-link-delete lgw-remove-row">Remove</button></td>'
            + '</tr>';
    }

    // Add sponsor row
    $('#lgw-add-sponsor').on('click', function(){
        $('#lgw-sponsor-table tbody').append(newSponsorRow());
    });

    // Remove row
    $(document).on('click', '.lgw-remove-row', function(){
        $(this).closest('tr').remove();
    });

    // Media library picker
    var mediaFrame;
    var currentRow;

    $(document).on('click', '.lgw-pick-image', function(e){
        e.preventDefault();
        currentRow = $(this).closest('tr');

        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media({
            title: 'Select Club Badge',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });

        mediaFrame.on('select', function(){
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            var url = attachment.url;
            currentRow.find('.lgw-image-url').val(url);
            currentRow.find('.lgw-badge-preview').attr('src', url).show();
        });

        mediaFrame.open();
    });

})(jQuery);

// Club table add rows
jQuery(function($){
  function newClubRow() {
    return '<tr class="lgw-club-row">'
      + '<td><input type="text" name="lgw_club_name[]" placeholder="e.g. Ards" class="regular-text" style="width:120px"></td>'
      + '<td><input type="text" name="lgw_club_pin[]" placeholder="word.word.word" autocomplete="off" autocapitalize="none" spellcheck="false" class="regular-text" style="width:180px"></td>'
      + '<td>'
      + '<select name="lgw_badge_type[]" class="lgw-badge-type">'
      + '<option value="club">Club prefix</option>'
      + '<option value="exact">Exact</option>'
      + '</select>'
      + '</td>'
      + '<td>'
      + '<input type="text" name="lgw_image[]" value="" placeholder="Image URL" class="regular-text lgw-image-url" readonly style="width:140px">'
      + '<button type="button" class="button lgw-pick-image">Choose</button>'
      + '</td>'
      + '<td><img class="lgw-badge-preview" src="" style="display:none;width:40px;height:40px;object-fit:contain;"></td>'
      + '<td><button type="button" class="button-link-delete lgw-remove-row">Remove</button></td>'
      + '</tr>';
  }
  $('#lgw-add-club').on('click', function(){
    $('#lgw-club-table tbody').append(newClubRow());
  });
  $(document).on('click', '#lgw-club-table .lgw-remove-row', function(){
    $(this).closest('tr').remove();
  });
});

// ── Cup admin: inline draw button ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var drawBtn = document.querySelector('.lgw-cup-admin-draw-btn-inline');
    if (drawBtn) {
        drawBtn.addEventListener('click', function() {
            if (!confirm('Perform the draw now? This will randomise the bracket and publish it live.')) return;
            var btn = this;
            var msg = document.getElementById('lgw-draw-inline-msg');
            btn.disabled = true; btn.textContent = '⏳ Drawing…';
            var fd = new FormData();
            fd.append('action', 'lgw_cup_perform_draw');
            fd.append('cup_id', btn.dataset.cupId);
            fd.append('nonce',  btn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false; btn.textContent = '🎲 Perform Draw Now';
                    if (msg) {
                        msg.style.display = '';
                        if (res.success) {
                            msg.style.color = '#0a3622';
                            msg.textContent = '✅ Draw complete! ' + (res.data.pairs||[]).length + ' matches drawn. Reload the public page to see the bracket.';
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            msg.style.color = '#c0202a';
                            msg.textContent = 'Error: ' + (res.data || 'Unknown');
                        }
                    }
                });
        });
    }

    // ── Cup admin: sync results button ────────────────────────────────────────
    var syncBtn = document.getElementById('lgw-cup-sync-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function() {
            var btn = this;
            var msg = document.getElementById('lgw-sync-msg');
            btn.disabled = true; btn.textContent = '⏳ Syncing…';
            var fd = new FormData();
            fd.append('action', 'lgw_cup_sync_results');
            fd.append('cup_id', btn.dataset.cupId);
            fd.append('nonce',  btn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false; btn.textContent = '🔄 Sync Results Now';
                    if (msg) {
                        msg.style.display = '';
                        msg.textContent = res.success ? '✅ Results synced.' : '❌ ' + (res.data || 'Error');
                        msg.style.color = res.success ? '#0a3622' : '#c0202a';
                    }
                });
        });
    }

    // ── Champ admin: draw buttons ─────────────────────────────────────────────
    document.querySelectorAll('.lgw-champ-admin-draw-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Perform the draw for this section now? This cannot be undone.')) return;
            var msg = btn.nextElementSibling;
            btn.disabled = true; btn.textContent = '⏳ Drawing…';
            var fd = new FormData();
            fd.append('action',   'lgw_champ_perform_draw');
            fd.append('champ_id', btn.dataset.champId);
            fd.append('section',  btn.dataset.section);
            fd.append('nonce',    btn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    btn.disabled = false; btn.textContent = '🎲 Draw Now';
                    if (msg) {
                        msg.style.display = '';
                        if (res.success) {
                            msg.style.color = '#0a3622';
                            msg.textContent = '✅ Draw complete! ' + (res.data.pairs||[]).length + ' matches drawn.';
                            setTimeout(function(){ location.reload(); }, 1500);
                        } else {
                            msg.style.color = '#c0202a';
                            msg.textContent = 'Error: ' + (res.data || 'Unknown');
                        }
                    }
                });
        });
    });

    // ── Champ admin: Edit Draw UI ─────────────────────────────────────────────────

    // ── Toggle edit panel visibility ─────────────────────────────────────────
    document.querySelectorAll('.lgw-champ-edit-draw-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var section = btn.dataset.section;
            var panel   = document.getElementById('lgw-edit-draw-' + section);
            if (!panel) return;
            var visible = panel.style.display !== 'none';
            panel.style.display = visible ? 'none' : 'block';
            btn.textContent = visible ? '✏️ Edit Draw' : '✖ Close Editor';
        });
    });

    // ── Edit button opens inline edit row ────────────────────────────────────
    document.querySelectorAll('.lgw-em-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var section = btn.closest('.lgw-edit-draw-section');
            var entries = JSON.parse(section.dataset.entries || '[]');
            var tr      = btn.closest('tr');
            var round   = btn.dataset.round;
            var match   = btn.dataset.match;
            var curHome = btn.dataset.home;
            var curAway = btn.dataset.away;

            // Remove any existing edit row
            var existing = section.querySelector('.lgw-em-edit-row');
            if (existing) existing.remove();

            // Build select options
            function buildSelect(name, current) {
                var sel = '<select class="' + name + '" style="max-width:280px;font-size:12px">';
                entries.forEach(function(e) {
                    var sel_attr = e === current ? ' selected' : '';
                    sel += '<option value="' + escHtml(e) + '"' + sel_attr + '>' + escHtml(e) + '</option>';
                });
                sel += '</select>';
                return sel;
            }

            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            var numCols = tr.cells.length;
            var editRow = document.createElement('tr');
            editRow.className = 'lgw-em-edit-row';
            editRow.style.background = '#fffbea';
            editRow.innerHTML = '<td colspan="' + numCols + '" style="padding:10px 12px">'
                + '<strong style="font-size:12px;display:block;margin-bottom:6px">Edit match — Round index ' + round + ', Match ' + (parseInt(match)+1) + '</strong>'
                + '<label style="font-size:12px;margin-right:8px">Home: ' + buildSelect('lgw-em-sel-home', curHome) + '</label>'
                + '<label style="font-size:12px;margin-right:8px">Away: ' + buildSelect('lgw-em-sel-away', curAway) + '</label>'
                + '<button type="button" class="button button-primary lgw-em-save-btn" '
                +   'data-round="' + round + '" data-match="' + match + '" style="margin-right:6px">Save</button>'
                + '<button type="button" class="button lgw-em-cancel-btn">Cancel</button>'
                + '<span class="lgw-em-row-msg" style="margin-left:10px;font-size:12px"></span>'
                + '</td>';

            tr.insertAdjacentElement('afterend', editRow);

            editRow.querySelector('.lgw-em-cancel-btn').addEventListener('click', function() {
                editRow.remove();
            });

            editRow.querySelector('.lgw-em-save-btn').addEventListener('click', function() {
                var saveBtn  = this;
                var newHome  = editRow.querySelector('.lgw-em-sel-home').value;
                var newAway  = editRow.querySelector('.lgw-em-sel-away').value;
                var rowMsg   = editRow.querySelector('.lgw-em-row-msg');
                var statMsg  = section.querySelector('.lgw-em-status');

                if (newHome === newAway) {
                    rowMsg.style.color = '#c0202a';
                    rowMsg.textContent = 'Home and Away cannot be the same player.';
                    return;
                }

                saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

                var fd = new FormData();
                fd.append('action',    'lgw_champ_edit_match');
                fd.append('champ_id',  section.dataset.champId);
                fd.append('section',   section.dataset.section);
                fd.append('round_idx', round);
                fd.append('match_idx', match);
                fd.append('new_home',  newHome);
                fd.append('new_away',  newAway);
                fd.append('nonce',     section.dataset.nonce);

                fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        saveBtn.disabled = false; saveBtn.textContent = 'Save';
                        if (res.success) {
                            // Update displayed cells in the source row
                            tr.querySelector('.lgw-em-home').textContent = newHome;
                            tr.querySelector('.lgw-em-away').textContent = newAway;
                            // Update btn data attrs so re-edit shows current values
                            btn.dataset.home = newHome;
                            btn.dataset.away = newAway;

                            rowMsg.style.color = '#0a3622';
                            rowMsg.textContent = '✅ Saved';

                            if (statMsg) {
                                statMsg.style.color = '#0a3622';
                                statMsg.textContent = '✅ ' + (res.data.message || 'Match updated — reload the public page to see changes.');
                            }

                            setTimeout(function(){ editRow.remove(); }, 1200);
                        } else {
                            rowMsg.style.color = '#c0202a';
                            rowMsg.textContent = '❌ ' + (res.data || 'Error saving match');
                        }
                    })
                    .catch(function() {
                        saveBtn.disabled = false; saveBtn.textContent = 'Save';
                        rowMsg.style.color = '#c0202a';
                        rowMsg.textContent = '❌ Network error';
                    });
            });
        });
    });
});

