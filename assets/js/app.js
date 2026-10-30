/* =====================================================================
   BALITECH IT Inventory - global JavaScript
   (Bootstrap 5 behaviours, AJAX helpers, UI utilities)
   ===================================================================*/
(function () {
    'use strict';

    /* ------------------------------------------------ sidebar (mobile) */
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });
        document.addEventListener('click', function (event) {
            if (document.body.classList.contains('sidebar-open')
                && !event.target.closest('.app-sidebar')
                && !event.target.closest('#sidebarToggle')) {
                document.body.classList.remove('sidebar-open');
            }
        });
    }

    /* ------------------------------------------- auto-dismiss messages */
    document.querySelectorAll('.app-alert').forEach(function (alert) {
        window.setTimeout(function () {
            if (window.bootstrap && bootstrap.Alert) {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }
        }, 6000);
    });

    /* ----------------------------------------------- confirm dialogs */
    document.addEventListener('click', function (event) {
        const el = event.target.closest('[data-confirm]');
        if (!el) { return; }
        if (!window.confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    /* ------------------------------------------------------- printing */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    /* --------------------------------------- live client-side filter */
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        input.addEventListener('input', function () {
            const table = document.querySelector(input.getAttribute('data-table-filter'));
            if (!table) { return; }
            const term = input.value.toLowerCase().trim();
            let visible = 0;
            table.querySelectorAll('tbody tr').forEach(function (row) {
                if (row.classList.contains('no-filter')) { return; }
                const match = row.innerText.toLowerCase().indexOf(term) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) { visible++; }
            });
            const empty = document.querySelector(input.getAttribute('data-table-empty') || '');
            if (empty) { empty.style.display = visible === 0 ? '' : 'none'; }
        });
    });

    /* ------------------------------------------------- select all box */
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        const selector = master.getAttribute('data-check-all');
        master.addEventListener('change', function () {
            document.querySelectorAll(selector).forEach(function (box) {
                box.checked = master.checked;
            });
        });
    });

    /* --------------------------------------- dependent (cascading) selects
       Markup contract:
         <select data-cascade="departments"
                 data-url="api/departments.php"
                 data-parent="#branch_id"
                 data-placeholder="All departments"
                 data-selected="3"></select>
       Supported cascade types: departments | employees | subcategories
    ---------------------------------------------------------------- */
    function cascadeParams(type, parentValue, select) {
        const extra = select.getAttribute('data-extra');
        const params = {};
        if (type === 'employees') {
            if (select.getAttribute('data-branch-source')) {
                const branchEl = document.querySelector(select.getAttribute('data-branch-source'));
                if (branchEl) { params.branch_id = branchEl.value; }
            } else if (parentValue) {
                params.branch_id = parentValue;
            }
        } else if (type === 'departments') {
            if (parentValue) { params.branch_id = parentValue; }
        } else if (type === 'subcategories') {
            if (parentValue) { params.parent_id = parentValue; }
        }
        if (extra) {
            const extraEl = document.querySelector(extra);
            if (extraEl && extraEl.value) { params.department_id = extraEl.value; }
        }
        return params;
    }

    function loadCascade(select) {
        const type = select.getAttribute('data-cascade');
        const url = select.getAttribute('data-url') || 'api/options.php';
        const parentSelector = select.getAttribute('data-parent');
        const parentEl = parentSelector ? document.querySelector(parentSelector) : null;
        const parentValue = parentEl ? parentEl.value : '';
        const selected = select.getAttribute('data-selected') || select.value;
        const placeholder = select.getAttribute('data-placeholder') || '-- Select --';
        const keepEmpty = select.hasAttribute('data-allow-empty');

        select.innerHTML = '<option value="">' + placeholder + '</option>';

        const params = cascadeParams(type, parentValue, select);
        if (type === 'departments' && !params.branch_id && !keepEmpty) {
            return; // wait for the parent branch selection
        }

        const query = new URLSearchParams(Object.assign({ type: type, ajax: 1 }, params));
        fetch(APP.baseUrl + url + '?' + query.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                (data.options || []).forEach(function (item) {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = item.text;
                    if (String(item.id) === String(selected)) { option.selected = true; }
                    select.appendChild(option);
                });
                select.dispatchEvent(new Event('cascade:loaded'));
            })
            .catch(function () { /* silent: keep the static options */ });
    }

    document.querySelectorAll('select[data-cascade]').forEach(function (select) {
        const parentSelector = select.getAttribute('data-parent');
        const parentEl = parentSelector ? document.querySelector(parentSelector) : null;
        if (parentEl) {
            parentEl.addEventListener('change', function () {
                select.setAttribute('data-selected', '');
                loadCascade(select);
            });
        }
        const branchSource = select.getAttribute('data-branch-source');
        if (branchSource) {
            const branchEl = document.querySelector(branchSource);
            if (branchEl) {
                branchEl.addEventListener('change', function () {
                    select.setAttribute('data-selected', '');
                    loadCascade(select);
                });
            }
        }
        loadCascade(select);
    });

    /* ------------------------------------------------- search suggest */
    const globalSearch = document.getElementById('globalSearch');
    if (globalSearch && !globalSearch.dataset.suggestReady) {
        globalSearch.dataset.suggestReady = '1';
        const box = document.createElement('div');
        box.className = 'list-group position-absolute w-100 shadow';
        box.style.cssText = 'z-index:1080;top:46px;max-height:340px;overflow:auto;display:none;border-radius:10px';
        globalSearch.parentNode.appendChild(box);

        let timer = null;
        globalSearch.addEventListener('input', function () {
            window.clearTimeout(timer);
            const term = globalSearch.value.trim();
            if (term.length < 2) { box.style.display = 'none'; return; }
            timer = window.setTimeout(function () {
                fetch(APP.baseUrl + 'api/search_suggest.php?q=' + encodeURIComponent(term), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        box.innerHTML = '';
                        const items = data.results || [];
                        if (!items.length) {
                            box.innerHTML = '<div class="list-group-item text-muted" style="font-size:12.5px">No matches found</div>';
                        } else {
                            items.forEach(function (item) {
                                const a = document.createElement('a');
                                a.className = 'list-group-item list-group-item-action';
                                a.href = item.url;
                                a.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                                    + '<span><b style="color:#1b6ca8">' + item.code + '</b> &middot; ' + item.title + '</span>'
                                    + '<small class="text-muted">' + item.meta + '</small></div>';
                                box.appendChild(a);
                            });
                        }
                        box.style.display = '';
                    })
                    .catch(function () { box.style.display = 'none'; });
            }, 250);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.topbar-search')) { box.style.display = 'none'; }
        });
    }

    /* ------------------------------------------ Bootstrap validation */
    document.querySelectorAll('form.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    /* ------------------------------------------- auto-submit filters */
    document.querySelectorAll('[data-auto-submit]').forEach(function (field) {
        field.addEventListener('change', function () {
            const form = field.closest('form');
            if (form) { form.submit(); }
        });
    });

    /* ------------------------------- tooltips + asset photo preview */
    if (window.bootstrap && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        input.addEventListener('change', function () {
            const target = document.querySelector(input.getAttribute('data-preview'));
            const file = input.files && input.files[0];
            if (!target || !file) { return; }
            if (file.size > 2 * 1024 * 1024) {
                window.alert('The selected file is larger than 2 MB.');
                input.value = '';
                return;
            }
            if (!/^image\//.test(file.type)) {
                window.alert('Only image files are allowed.');
                input.value = '';
                return;
            }
            target.src = URL.createObjectURL(file);
            target.style.display = '';
        });
    });

    /* ----------------------------- return-date toggle on assign form */
    document.querySelectorAll('[data-toggle-fields]').forEach(function (trigger) {
        const showFields = function () {
            document.querySelectorAll(trigger.getAttribute('data-toggle-fields')).forEach(function (el) {
                const wrap = el.closest('[data-toggle-wrap]') || el;
                wrap.style.display = trigger.checked ? '' : 'none';
            });
        };
        trigger.addEventListener('change', showFields);
        showFields();
    });
})();
