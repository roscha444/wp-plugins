(function () {
  document.querySelectorAll('.srk-cf-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var wrap       = form.closest('.srk-cf-wrap');
      var btn        = form.querySelector('.srk-cf-btn');
      var errBox     = form.querySelector('.srk-cf-error');
      var successBox = wrap.querySelector('.srk-cf-success');
      var formId     = form.getAttribute('data-form-id');

      errBox.style.display = 'none';
      btn.disabled = true;
      var originalHTML = btn.innerHTML;
      btn.textContent = 'Wird gesendet\u2026';

      var data = new FormData(form);
      data.append('action', 'srk_cf_submit');
      data.append('form_id', formId);

      fetch(srkFormsConfig.ajaxUrl, {
        method: 'POST',
        body: data,
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (res.success) {
            form.style.display = 'none';
            successBox.style.display = 'flex';
          } else {
            errBox.textContent = res.data || 'Ein Fehler ist aufgetreten.';
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = originalHTML;
          }
        })
        .catch(function () {
          errBox.textContent =
            'Ein Fehler ist aufgetreten. Bitte versuchen Sie es sp\u00e4ter erneut.';
          errBox.style.display = 'block';
          btn.disabled = false;
          btn.innerHTML = originalHTML;
        });
    });
  });
})();
