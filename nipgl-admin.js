/* NIPGL Admin JS */
(function($){
    'use strict';

    // Row template
    function newRow() {
        return '<tr class="nipgl-badge-row">'
            + '<td><input type="text" name="nipgl_team[]" value="" placeholder="e.g. MALONE" class="regular-text"></td>'
            + '<td>'
            + '<input type="text" name="nipgl_image[]" value="" placeholder="Image URL" class="regular-text nipgl-image-url" readonly>'
            + '<button type="button" class="button nipgl-pick-image">Choose Image</button>'
            + '</td>'
            + '<td><img class="nipgl-badge-preview" src="" style="display:none;width:48px;height:48px;object-fit:contain;"></td>'
            + '<td><button type="button" class="button-link-delete nipgl-remove-row">Remove</button></td>'
            + '</tr>';
    }

    // Add row
    $('#nipgl-add-row').on('click', function(){
        $('#nipgl-badge-table tbody').append(newRow());
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
