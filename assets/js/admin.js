(function () {
  'use strict';

  var table = document.querySelector('#dmuf-rules tbody');
  var add = document.querySelector('#dmuf-add-rule');
  if (!table || !add) {
    return;
  }

  function reindex() {
    var rows = table.querySelectorAll('tr');
    rows.forEach(function (row, index) {
      row.querySelectorAll('[name]').forEach(function (field) {
        field.name = field.name.replace(/rules\]\[\d+\]/, 'rules][' + index + ']');
      });
    });
  }

  add.addEventListener('click', function () {
    var row = table.querySelector('tr');
    if (!row) {
      return;
    }
    var clone = row.cloneNode(true);
    clone.querySelectorAll('input').forEach(function (field) {
      if (field.type === 'checkbox') {
        field.checked = true;
      } else {
        field.value = '';
        field.placeholder = '';
      }
    });
    table.appendChild(clone);
    reindex();
  });

  table.addEventListener('click', function (event) {
    if (!event.target.classList.contains('dmuf-remove-rule')) {
      return;
    }
    if (table.querySelectorAll('tr').length === 1) {
      table.querySelectorAll('input').forEach(function (field) {
        if (field.type !== 'checkbox') {
          field.value = '';
        }
      });
      return;
    }
    event.target.closest('tr').remove();
    reindex();
  });
}());
