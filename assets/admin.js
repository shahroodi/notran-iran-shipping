(function ($) {
  'use strict';
  const local = window.NotranIRLocal;
  if (!local) return;
  function enhance(node) { if ($.fn.selectWoo && !$(node).data('select2')) $(node).selectWoo({ width: '100%', placeholder: 'انتخاب شهرها' }); }
  function destroy(node) { if ($(node).data('select2')) $(node).selectWoo('destroy'); }
  function mode(row) { row.querySelector('.nt-ir-cost').disabled = row.querySelector('.nt-ir-mode').value === 'free'; }
  function load(row, initial) {
    const select = row.querySelector('.nt-ir-cities'), code = row.querySelector('.nt-ir-state').value;
    const info = row.querySelector('.nt-ir-load-status') || row.appendChild(Object.assign(document.createElement('span'), { className: 'nt-ir-load-status' }));
    info.setAttribute('role', 'status'); info.replaceChildren();
    if (initial && select.dataset.state === code && select.options.length) {
      local.seed(code, Array.from(select.options).map(o => o.value)); enhance(select); return;
    }
    destroy(select); select.replaceChildren(); select.disabled = Boolean(code);
    const token = (select._ntToken || 0) + 1; select._ntToken = token;
    if (!code) { select.disabled = false; return; }
    local.cities(code).then(names => {
      if (select._ntToken !== token || row.querySelector('.nt-ir-state').value !== code) return;
      for (const name of names) select.add(new Option(name, name));
      select.disabled = false; select.dataset.state = code; enhance(select);
    }).catch(() => {
      if (select._ntToken !== token) return;
      select.disabled = false; info.append(document.createTextNode('دریافت شهرها انجام نشد. '));
      const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'button'; retry.textContent = 'تلاش دوباره';
      retry.addEventListener('click', () => load(row, false)); info.append(retry);
    });
  }
  $(function () {
    for (const wrap of document.querySelectorAll('.nt-ir-rules')) {
      for (const row of wrap.querySelectorAll('.nt-ir-rows .nt-ir-row')) { mode(row); load(row, true); }
      wrap.addEventListener('click', event => {
        if (event.target.closest('.nt-ir-add')) {
          if (wrap.querySelectorAll('.nt-ir-rows .nt-ir-row').length >= 80) return;
          const fragment = wrap.querySelector('template').content.cloneNode(true), i = Number(wrap.dataset.next || 0);
          wrap.dataset.next = i + 1;
          for (const field of fragment.querySelectorAll('[name]')) field.name = field.name.replace('__ROW__', String(i));
          const row = fragment.querySelector('.nt-ir-row'); wrap.querySelector('.nt-ir-rows').appendChild(fragment); mode(row); load(row, true);
        }
        const remove = event.target.closest('.nt-ir-remove');
        if (remove) { const row = remove.closest('.nt-ir-row'); destroy(row.querySelector('.nt-ir-cities')); row.remove(); }
      });
      wrap.addEventListener('change', event => {
        const row = event.target.closest('.nt-ir-row'); if (!row) return;
        if (event.target.matches('.nt-ir-state')) load(row, false);
        if (event.target.matches('.nt-ir-mode')) mode(row);
      });
    }
  });
})(jQuery);
