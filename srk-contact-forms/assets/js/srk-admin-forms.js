(function ($) {
  var $rows = $('#srk-field-rows');
  var template = $('#srk-field-row-template').html();

  function getNextIndex() {
    var max = -1;
    $rows.find('.srk-field-row').each(function () {
      var match = $(this).find('input, select, textarea').first().attr('name');
      if (match) {
        var idx = parseInt(match.replace(/.*\[(\d+)\].*/, '$1'), 10);
        if (!isNaN(idx) && idx > max) max = idx;
      }
    });
    return max + 1;
  }

  // Add field
  $('#srk-add-field').on('click', function () {
    var idx = getNextIndex();
    var row = template.replace(/__INDEX__/g, idx);
    $rows.append(row);
  });

  // Remove field
  $rows.on('click', '.srk-remove-field', function () {
    $(this).closest('tr').remove();
  });

  // Toggle options textarea for select fields
  $rows.on('change', '.srk-field-type-select', function () {
    var $textarea = $(this).closest('tr').find('.srk-field-options');
    if ($(this).val() === 'select') {
      $textarea.show();
    } else {
      $textarea.hide();
    }
  });

  // Sortable
  $rows.sortable({
    handle: '.srk-fb-drag',
    axis: 'y',
    placeholder: 'srk-sortable-placeholder',
    opacity: 0.7,
  });

  // Auto-generate ID from title (new form only)
  var $idField = $('#srk_cf_id');
  if (!$idField.prop('readonly')) {
    $('#srk_cf_title').on('input', function () {
      var val = $(this)
        .val()
        .toLowerCase()
        .replace(/[äÄ]/g, 'ae')
        .replace(/[öÖ]/g, 'oe')
        .replace(/[üÜ]/g, 'ue')
        .replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
      $idField.val(val);
    });
  }
})(jQuery);
