(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var add = event.target.closest('.notran-fee-add');
        if (add) {
            var root = add.closest('.notran-fees'), rows = root.querySelector('.notran-fee-rows');
            if (rows.children.length >= 20) return;
            var next = Number(root.dataset.next) || 0;
            rows.insertAdjacentHTML('beforeend', root.querySelector('template').innerHTML.replace(/__ROW__/g, String(next)));
            root.dataset.next = next + 1;
            rows.lastElementChild.querySelector('input').focus();
        }
        var remove = event.target.closest('.notran-fee-remove');
        if (remove) remove.closest('.notran-fee-row').remove();
    });
}());
