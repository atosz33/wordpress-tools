/* global wp, podAdmin */
(function () {
    'use strict';

    var strings = window.podAdmin || {};
    var counter = 1000;

    function nextIndex() {
        counter += 1;

        return counter;
    }

    function closest(node, selector) {
        return node ? node.closest(selector) : null;
    }

    function setPreview(container, url) {
        var preview = container.querySelector('.pod-media-preview');

        if (!preview) {
            return;
        }

        var img = preview.querySelector('img');

        if (!url) {
            if (img) {
                img.remove();
            }

            preview.dataset.image = '';
            return;
        }

        if (!img) {
            img = document.createElement('img');
            preview.insertBefore(img, preview.firstChild);
        }

        img.src = url;
        preview.dataset.image = url;
    }

    /* ------------------------------------------------------------- Media */

    function pickImage(button) {
        var container = closest(button, '.pod-row__media') || closest(button, '.pod-color-image');

        if (!container) {
            return;
        }

        var field = container.querySelector('.pod-image-id');

        var frame = wp.media({
            title: strings.chooseImage,
            button: { text: strings.useImage },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();

            field.value = attachment.id;
            setPreview(container, attachment.url);
            updateMarker(closest(button, '.pod-view-row'));
        });

        frame.open();
    }

    function fieldIn(row, suffix) {
        return row.querySelector('[name$="' + suffix + '"]');
    }

    /**
     * The bundled mockups are presets, not just images: picking one also fills
     * in the printable size, the print area and the non printable bands, so a
     * new product template does not have to be measured by hand.
     */
    function applyBuiltin(select) {
        var row = closest(select, '.pod-view-row');
        var container = row ? row.querySelector('.pod-row__media') : null;

        if (!container) {
            return;
        }

        var preset = (strings.builtins || {})[select.value];

        if (!preset) {
            if (!container.querySelector('.pod-image-id').value) {
                setPreview(container, '');
            }

            updateMarker(row);
            return;
        }

        // Choosing a built-in replaces whatever was uploaded for this view.
        container.querySelector('.pod-image-id').value = '';
        setPreview(container, preset.url);

        [['[width_mm]', preset.width_mm], ['[height_mm]', preset.height_mm],
            ['[safe_top]', preset.safe_top], ['[safe_bottom]', preset.safe_bottom]].forEach(function (pair) {
            var field = fieldIn(row, pair[0]);

            if (field) {
                field.value = pair[1];
            }
        });

        var inputs = areaInputs(row);

        if (inputs && preset.area) {
            fillArea(inputs, preset.area);
        }

        // A mug preset also knows where the cylinder wall sits on the artwork,
        // which is what the 3D preview wraps.
        var card = closest(row, '.pod-card');
        var body = card ? card.querySelector('.pod-mug-only .pod-area-inputs') : null;

        if (body && preset.body) {
            fillArea({
                x: body.querySelector('.pod-area-x'),
                y: body.querySelector('.pod-area-y'),
                w: body.querySelector('.pod-area-w'),
                h: body.querySelector('.pod-area-h')
            }, preset.body);
        }

        updateMarker(row);
    }

    function fillArea(inputs, area) {
        ['x', 'y', 'w', 'h'].forEach(function (key) {
            if (inputs[key]) {
                inputs[key].value = area[key];
            }
        });
    }

    /**
     * Duplicates a whole template card so a second t-shirt with different
     * colours does not have to be rebuilt from scratch. Current field values
     * are written back into the attributes first, because outerHTML serialises
     * attributes rather than the live values.
     */
    function duplicateTemplate(button) {
        var card = closest(button, '.pod-card');

        if (!card) {
            return;
        }

        Array.prototype.forEach.call(card.querySelectorAll('input'), function (input) {
            if ('checkbox' === input.type || 'radio' === input.type) {
                if (input.checked) {
                    input.setAttribute('checked', 'checked');
                } else {
                    input.removeAttribute('checked');
                }
            } else {
                input.setAttribute('value', input.value);
            }
        });

        Array.prototype.forEach.call(card.querySelectorAll('select'), function (select) {
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.selected) {
                    option.setAttribute('selected', 'selected');
                } else {
                    option.removeAttribute('selected');
                }
            });
        });

        var oldIndex = card.dataset.tindex;
        var newIndex = nextIndex();
        var html = card.outerHTML
            .split('pod_templates[' + oldIndex + ']')
            .join('pod_templates[' + newIndex + ']');

        var node = protoToNode(html, {});

        if (!node) {
            return;
        }

        node.dataset.tindex = newIndex;

        // Blank ids so the copy gets fresh UUIDs instead of colliding.
        Array.prototype.forEach.call(node.querySelectorAll('[name$="[id]"]'), function (input) {
            input.value = '';
        });

        var name = node.querySelector('.pod-template-name');
        var slug = node.querySelector('[name$="[slug]"]');

        if (name) {
            name.value = name.value + ' ' + (strings.copySuffix || 'copy');
            node.querySelector('.pod-card__title').textContent = name.value;
        }

        if (slug) {
            slug.value = '';
        }

        card.parentNode.insertBefore(node, card.nextSibling);
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearImage(button) {
        var container = closest(button, '.pod-row__media') || closest(button, '.pod-color-image');

        if (!container) {
            return;
        }

        container.querySelector('.pod-image-id').value = '';
        setPreview(container, '');

        var builtin = container.querySelector('.pod-builtin-select');

        if (builtin) {
            builtin.value = '';
        }
    }

    /* -------------------------------------------------------- Print area */

    function areaInputs(row) {
        if (!row) {
            return null;
        }

        var inputs = {
            x: row.querySelector('.pod-area-line .pod-area-x'),
            y: row.querySelector('.pod-area-line .pod-area-y'),
            w: row.querySelector('.pod-area-line .pod-area-w'),
            h: row.querySelector('.pod-area-line .pod-area-h')
        };

        return inputs.x ? inputs : null;
    }

    function updateMarker(row) {
        if (!row) {
            return;
        }

        var inputs = areaInputs(row);
        var preview = row.querySelector('.pod-media-preview');

        if (!inputs || !preview || !preview.querySelector('img')) {
            return;
        }

        var marker = preview.querySelector('.pod-area-marker');

        if (!marker) {
            marker = document.createElement('span');
            marker.className = 'pod-area-marker';
            preview.appendChild(marker);
        }

        marker.style.left = (parseFloat(inputs.x.value) || 0) + '%';
        marker.style.top = (parseFloat(inputs.y.value) || 0) + '%';
        marker.style.width = (parseFloat(inputs.w.value) || 0) + '%';
        marker.style.height = (parseFloat(inputs.h.value) || 0) + '%';
    }

    function startDrawing(button) {
        var row = closest(button, '.pod-view-row');
        var preview = row ? row.querySelector('.pod-media-preview') : null;
        var image = preview ? preview.querySelector('img') : null;
        var inputs = areaInputs(row);

        if (!image || !inputs) {
            window.alert(strings.noImage);
            return;
        }

        preview.classList.add('is-drawing');

        var origin = null;

        var toPercent = function (event) {
            var rect = image.getBoundingClientRect();

            return {
                x: Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100)),
                y: Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100))
            };
        };

        var apply = function (point) {
            var x = Math.min(origin.x, point.x);
            var y = Math.min(origin.y, point.y);

            inputs.x.value = x.toFixed(2);
            inputs.y.value = y.toFixed(2);
            inputs.w.value = Math.abs(point.x - origin.x).toFixed(2);
            inputs.h.value = Math.abs(point.y - origin.y).toFixed(2);
            updateMarker(row);
        };

        var down = function (event) {
            event.preventDefault();
            origin = toPercent(event);
            apply(origin);
        };

        var move = function (event) {
            if (origin) {
                apply(toPercent(event));
            }
        };

        var up = function (event) {
            if (!origin) {
                return;
            }

            apply(toPercent(event));
            origin = null;
            preview.classList.remove('is-drawing');
            preview.removeEventListener('mousedown', down);
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
        };

        preview.addEventListener('mousedown', down);
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    }

    /* ------------------------------------------------------------- Rows */

    function protoToNode(html, replacements) {
        Object.keys(replacements).forEach(function (token) {
            html = html.split(token).join(replacements[token]);
        });

        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();

        return wrapper.firstElementChild;
    }

    function addTemplate() {
        var proto = document.getElementById('pod-template-proto');
        var list = document.getElementById('pod-template-list');
        var node = protoToNode(proto.innerHTML, { __TINDEX__: nextIndex() });

        if (node) {
            list.appendChild(node);
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function addRow(button, protoClass, containerClass, token) {
        var card = closest(button, '.pod-card');
        var proto = card.querySelector(protoClass);
        var container = card.querySelector(containerClass);
        var replacements = {};

        replacements[token] = nextIndex();

        var node = protoToNode(proto.innerHTML, replacements);

        if (node) {
            container.appendChild(node);
        }
    }

    /* ------------------------------------------------------------- Init */

    document.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target.matches('.pod-pick-image')) {
            event.preventDefault();
            pickImage(target);
        } else if (target.matches('.pod-clear-image')) {
            event.preventDefault();
            clearImage(target);
        } else if (target.matches('.pod-draw-area')) {
            event.preventDefault();
            startDrawing(target);
        } else if (target.matches('#pod-add-template')) {
            event.preventDefault();
            addTemplate();
        } else if (target.matches('.pod-add-view')) {
            event.preventDefault();
            addRow(target, '.pod-view-proto', '.pod-view-rows', '__VINDEX__');
        } else if (target.matches('.pod-add-color')) {
            event.preventDefault();
            addRow(target, '.pod-color-proto', '.pod-color-rows', '__CINDEX__');
        } else if (target.matches('.pod-delete-row')) {
            event.preventDefault();

            if (window.confirm(strings.confirmDeleteRow)) {
                closest(target, '.pod-row').remove();
            }
        } else if (target.matches('.pod-duplicate-template')) {
            event.preventDefault();
            duplicateTemplate(target);
        } else if (target.matches('.pod-delete-template')) {
            event.preventDefault();

            if (window.confirm(strings.confirmDeleteTemplate)) {
                closest(target, '.pod-card').remove();
            }
        } else if (target.matches('.pod-toggle-card')) {
            event.preventDefault();
            closest(target, '.pod-card').classList.toggle('is-collapsed');
        }
    });

    document.addEventListener('input', function (event) {
        var target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        if (target.matches('.pod-area-x, .pod-area-y, .pod-area-w, .pod-area-h')) {
            updateMarker(closest(target, '.pod-view-row'));
        }

        if (target.matches('.pod-template-name')) {
            var card = closest(target, '.pod-card');
            var title = card ? card.querySelector('.pod-card__title') : null;

            if (title) {
                title.textContent = target.value;
            }
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target instanceof Element && target.matches('.pod-builtin-select')) {
            applyBuiltin(target);
        }

        if (target instanceof Element && target.matches('.pod-template-type')) {
            var card = closest(target, '.pod-card');
            var isMug = 'mug' === target.value;

            Array.prototype.forEach.call(card.querySelectorAll('.pod-mug-only'), function (row) {
                row.style.display = isMug ? '' : 'none';
            });
        }
    });
})();
