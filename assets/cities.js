/* Local city data; no service request, polling or prefetching. */
(function ($) {
  'use strict';
  const cfg = window.notranIRCities;
  if (!cfg) return;
  const cache = new Map();
  const key = value => String(value || '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[آأإ]/g, 'ا').replace(/[\u064B-\u065F\u0670\s\u200C\u200D\-_–ـ]/g, '');
  const state = value => cfg.states[String(value || '').trim().toUpperCase()] || '';
  function cities(code) {
    if (!Object.prototype.hasOwnProperty.call(cfg.states, code) || cfg.states[code] !== code) return Promise.reject(new Error('state'));
    if (!cache.has(code)) {
      cache.set(code, fetch(cfg.url + code + '.json?ver=' + encodeURIComponent(cfg.version), { credentials: 'same-origin', cache: 'force-cache' })
        .then(response => { if (!response.ok) throw new Error('response'); return response.json(); })
        .then(names => { if (!Array.isArray(names) || names.length > 2000 || names.some(n => typeof n !== 'string')) throw new Error('data'); return names; })
        .catch(error => { cache.delete(code); throw error; }));
    }
    return cache.get(code);
  }
  function seed(code, names) { if (code && names.length && !cache.has(code)) cache.set(code, Promise.resolve(names)); }
  window.NotranIRLocal = { cities, state, key, seed };
  const scopes = ['billing', 'shipping', 'calc_shipping'];
  const get = (scope, field) => document.getElementById(scope + '_' + field);
  function enhance(node) {
    if ($.fn.selectWoo && !$(node).data('select2')) $(node).selectWoo({ width: '100%', placeholder: cfg.choose, language: { noResults: () => 'شهری پیدا نشد' } });
  }
  function destroy(node) { if ($(node).data('select2')) $(node).selectWoo('destroy'); }
  function status(node) {
    let box = node.parentNode.querySelector('.nt-ir-status');
    if (!box) { box = document.createElement('span'); box.className = 'nt-ir-status'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); node.parentNode.appendChild(box); }
    box.replaceChildren(); return box;
  }
  function convert(old, type) {
    destroy(old);
    const node = document.createElement(type);
    for (const attr of old.attributes) if (!['type', 'class', 'value', 'data-nt-state', 'data-nt-loaded'].includes(attr.name)) node.setAttribute(attr.name, attr.value);
    node.className = type === 'select' ? 'city_select' : 'input-text';
    node.setAttribute('autocomplete', 'address-level2');
    if (type === 'input') node.type = 'text';
    old.replaceWith(node); return node;
  }
  function refresh(scope, force) {
    let node = get(scope, 'city');
    if (!node) return;
    const country = get(scope, 'country');
    if (country && country.value !== 'IR') {
      if (node.tagName === 'SELECT' && (node.classList.contains('city_select') || node.dataset.ntLoaded)) { status(node); node = convert(node, 'input'); node.value = ''; }
      return;
    }
    const code = state(get(scope, 'state')?.value);
    if (!force && node.dataset.ntLoaded === code && node.tagName === 'SELECT') return;
    const first = !node.dataset.ntLoaded;
    const wanted = first ? (node.value || node.dataset.ntValue || '') : '';
    if (first && node.tagName === 'SELECT' && node.dataset.ntState === code && node.options.length > 1) {
      const names = Array.from(node.options).map(o => o.value).filter(Boolean); seed(code, names);
      const match = names.find(n => key(n) === key(wanted)); if (match) node.value = match;
      node.dataset.ntLoaded = code; enhance(node); return;
    }
    if (node.tagName !== 'SELECT') node = convert(node, 'select');
    destroy(node);
    node.closest('.form-row')?.classList.add('update_totals_on_change');
    const token = (node._ntToken || 0) + 1; node._ntToken = token;
    node.dataset.ntLoaded = code;
    node.replaceChildren(new Option(code ? cfg.loading : cfg.province, ''));
    node.disabled = Boolean(code); status(node);
    if (!code) return;
    cities(code).then(names => {
      if (node._ntToken !== token || state(get(scope, 'state')?.value) !== code || (get(scope, 'country') && get(scope, 'country').value !== 'IR')) return;
      node.replaceChildren(new Option(cfg.choose, ''));
      for (const name of names) node.add(new Option(name, name));
      const match = names.find(n => key(n) === key(wanted)); if (match) node.value = match;
      node.disabled = false; node.dataset.ntState = code; enhance(node);
      $(node).trigger('change');
    }).catch(() => {
      if (node._ntToken !== token) return;
      node.disabled = false; delete node.dataset.ntLoaded;
      node.replaceChildren(new Option(cfg.choose, ''));
      const box = status(node); box.append(document.createTextNode(cfg.error + ' '));
      const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'button'; retry.textContent = cfg.retry;
      retry.addEventListener('click', () => refresh(scope, true)); box.append(retry);
    });
  }
  function init() { for (const scope of scopes) refresh(scope, false); }
  $(init);
  $(document.body).on('country_to_state_changed updated_checkout updated_wc_div', init);
  $(document).on('change', '#billing_state,#shipping_state,#calc_shipping_state,#billing_country,#shipping_country,#calc_shipping_country', init);
})(jQuery);
