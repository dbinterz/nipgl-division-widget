/* NIPGL Admin JS */
(function($){
    'use strict';

    // Row template for badges
    function newRow() {
        return '<tr class="nipgl-badge-row">'
            + '<td><input type="text" name="nipgl_team[]" value="" placeholder="e.g. MALONE" class="regular-text"></td>'
            + '<td>'
            + '<select name="nipgl_badge_type[]" class="nipgl-badge-type">'
            + '<option value="club">Club prefix</option>'
            + '<option value="exact">Exact</option>'
            + '</select>'
            + '</td>'
            + '<td>'
            + '<input type="text" name="nipgl_image[]" value="" placeholder="Image URL" class="regular-text nipgl-image-url" readonly>'
            + '<button type="button" class="button nipgl-pick-image">Choose Image</button>'
            + '</td>'
            + '<td><img class="nipgl-badge-preview" src="" style="display:none;width:48px;height:48px;object-fit:contain;"></td>'
            + '<td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>'
            + '</tr>';
    }

    // Row template for sponsors
    function newSponsorRow() {
        return '<tr class="nipgl-sponsor-row">'
            + '<td><input type="text" name="nipgl_sp_name[]" value="" placeholder="e.g. Acme Ltd" class="regular-text"></td>'
            + '<td>'
            + '<input type="text" name="nipgl_sp_image[]" value="" placeholder="Image URL" class="regular-text nipgl-image-url" readonly>'
            + '<button type="button" class="button nipgl-pick-image">Choose Image</button>'
            + '</td>'
            + '<td><input type="text" name="nipgl_sp_url[]" value="" placeholder="https://" class="regular-text"></td>'
            + '<td><img class="nipgl-badge-preview" src="" style="display:none;height:40px;object-fit:contain;max-width:120px;"></td>'
            + '<td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>'
            + '</tr>';
    }

    // Add badge row
    $('#nipgl-add-row').on('click', function(){
        $('#nipgl-badge-table tbody').append(newRow());
    });

    // Add sponsor row
    $('#nipgl-add-sponsor').on('click', function(){
        $('#nipgl-sponsor-table tbody').append(newSponsorRow());
    });

    // Remove row
    $(document).on('click', '.nipgl-remove-row', function(){
        $(this).closest('tr').remove();
    });

    // Media library picker
    var mediaFrame;
    var currentRow;

    $(document).on('click', '.nipgl-pick-image', function(e){
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
            currentRow.find('.nipgl-image-url').val(url);
            currentRow.find('.nipgl-badge-preview').attr('src', url).show();
        });

        mediaFrame.open();
    });

})(jQuery);

// Club table add/remove rows
jQuery(function($){
  $('#nipgl-add-club').on('click', function(){
    var row = '<tr class="nipgl-club-row">'
      + '<td><input type="text" name="nipgl_club_name[]" placeholder="e.g. Ards" class="regular-text"></td>'
      + '<td><input type="text" name="nipgl_club_pin[]" placeholder="Set passphrase (word.word.word)" autocomplete="off" autocapitalize="none" spellcheck="false" class="regular-text"></td>'
      + '<td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>'
      + '</tr>';
    $('#nipgl-club-table tbody').append(row);
  });
  $(document).on('click', '#nipgl-club-table .nipgl-remove-row', function(){
    $(this).closest('tr').remove();
  });
});

// ── Cup admin: inline draw button ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var drawBtn = document.querySelector('.nipgl-cup-admin-draw-btn-inline');
    if (drawBtn) {
        drawBtn.addEventListener('click', function() {
            if (!confirm('Perform the draw now? This will randomise the bracket and publish it live.')) return;
            var btn = this;
            var msg = document.getElementById('nipgl-draw-inline-msg');
            btn.disabled = true; btn.textContent = '⏳ Drawing…';
            var fd = new FormData();
            fd.append('action', 'nipgl_cup_perform_draw');
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
    var syncBtn = document.getElementById('nipgl-cup-sync-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function() {
            var btn = this;
            var msg = document.getElementById('nipgl-sync-msg');
            btn.disabled = true; btn.textContent = '⏳ Syncing…';
            var fd = new FormData();
            fd.append('action', 'nipgl_cup_sync_results');
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
    document.querySelectorAll('.nipgl-champ-admin-draw-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Perform the draw for this section now? This cannot be undone.')) return;
            var msg = btn.nextElementSibling;
            btn.disabled = true; btn.textContent = '⏳ Drawing…';
            var fd = new FormData();
            fd.append('action',   'nipgl_champ_perform_draw');
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
});
