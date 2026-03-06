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
      + '<td><input type="password" name="nipgl_club_pin[]" placeholder="Set PIN" autocomplete="new-password" class="regular-text"></td>'
      + '<td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>'
      + '</tr>';
    $('#nipgl-club-table tbody').append(row);
  });
  $(document).on('click', '#nipgl-club-table .nipgl-remove-row', function(){
    $(this).closest('tr').remove();
  });
});
