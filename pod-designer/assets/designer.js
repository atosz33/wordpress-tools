/* global podDesignerGlobals */
(function () {
    'use strict';

    var GLOBALS = window.podDesignerGlobals || {};
    var MAX_EXPORT_PIXELS = 12000000;
    var MM_PER_INCH = 25.4;

    // PHP passes the translated labels in podDesignerGlobals.i18n. The English
    // strings below are only a fallback for the case where the script somehow
    // runs without its localized data.
    var STRINGS = {
        upload: 'Upload image',
        addText: 'Add text',
        colors: 'Color',
        views: 'Print side',
        layers: 'Layers',
        empty: 'There is nothing on this side yet.',
        deleteLayer: 'Delete',
        forward: 'Forward',
        backward: 'Backward',
        center: 'Center',
        fit: 'Fit',
        size: 'Size',
        rotation: 'Rotation',
        text: 'Text',
        font: 'Font',
        fontSize: 'Font size',
        textColor: 'Text color',
        bold: 'Bold',
        noPrint: 'Not printable',
        view2d: '2D view',
        view3d: '3D view',
        spin: 'Auto rotate',
        name: 'Name',
        email: 'Email',
        phone: 'Phone number',
        quantity: 'Quantity',
        note: 'Note',
        saving: 'Saving…',
        lowDpi: 'Low resolution – the print may look blurry.',
        dropHint: 'Drop an image here, or click to browse.',
        imageLoadFailed: 'The image could not be loaded: ',
        imageLayer: 'Image',
        textDefault: 'Text',
        sideLeft: 'left',
        sideRight: 'right',
        sideTop: 'top',
        sideBottom: 'bottom',
        outsideArea: 'Outside the printable area (%s).',
        issueOne: 'One item is outside the printable area. Move it back before you submit.',
        issueMany: '%s items are outside the printable area. Move them back before you submit.',
        wooHint: 'The design is saved together with the cart item.',
        selectHint: 'Select an item to edit it.',
        fileTooLarge: 'The file is too large (max. %s MB).',
        uploading: 'Uploading image…',
        uploadFailed: 'Upload failed.',
        needContent: 'Upload at least one image or add some text.',
        saveFailed: 'Saving failed.',
        enterName: 'Enter your name.',
        enterEmail: 'Enter a valid email address.',
        enterPhone: 'Enter your phone number.',
        resetRotation: 'Back to 0°'
    };

    function t(key) {
        var localized = GLOBALS.i18n || {};

        if (Object.prototype.hasOwnProperty.call(localized, key)) {
            return localized[key];
        }

        return Object.prototype.hasOwnProperty.call(STRINGS, key) ? STRINGS[key] : key;
    }

    // Fills the single %s placeholder some of the translated strings carry.
    function tf(key, value) {
        return t(key).replace('%s', value);
    }

    /* ---------------------------------------------------------------
     * Small helpers
     * --------------------------------------------------------------- */

    function h(tag, props, children) {
        var node = document.createElement(tag);

        Object.keys(props || {}).forEach(function (key) {
            if ('class' === key) {
                node.className = props[key];
            } else if ('text' === key) {
                node.textContent = props[key];
            } else if ('html' === key) {
                node.innerHTML = props[key];
            } else if (0 === key.indexOf('on') && 'function' === typeof props[key]) {
                node.addEventListener(key.slice(2).toLowerCase(), props[key]);
            } else if (null !== props[key] && undefined !== props[key]) {
                node.setAttribute(key, props[key]);
            }
        });

        (children || []).forEach(function (child) {
            if (!child) {
                return;
            }

            node.appendChild('string' === typeof child ? document.createTextNode(child) : child);
        });

        return node;
    }

    function uid() {
        return 'l' + Math.random().toString(36).slice(2, 10);
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function round(value, digits) {
        var factor = Math.pow(10, digits || 2);

        return Math.round(value * factor) / factor;
    }

    function loadImage(src) {
        return new Promise(function (resolve, reject) {
            var img = new Image();

            img.onload = function () {
                resolve(img);
            };
            img.onerror = function () {
                reject(new Error(t('imageLoadFailed') + src));
            };
            img.src = src;
        });
    }

    function canvasToBlob(canvas) {
        return new Promise(function (resolve) {
            if (canvas.toBlob) {
                canvas.toBlob(function (blob) {
                    resolve(blob);
                }, 'image/png');
                return;
            }

            var parts = canvas.toDataURL('image/png').split(',');
            var binary = atob(parts[1]);
            var bytes = new Uint8Array(binary.length);

            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }

            resolve(new Blob([bytes], { type: 'image/png' }));
        });
    }

    /**
     * The print area stored on the template is a rectangle in percent of the
     * mockup. The real printable rectangle keeps the millimetre aspect ratio,
     * so it is fitted inside that rectangle and centred.
     */
    function fitArea(area, mockupWidth, mockupHeight, widthMm, heightMm) {
        var box = {
            x: (area.x / 100) * mockupWidth,
            y: (area.y / 100) * mockupHeight,
            w: Math.max(1, (area.w / 100) * mockupWidth),
            h: Math.max(1, (area.h / 100) * mockupHeight)
        };
        var target = widthMm / heightMm;
        var current = box.w / box.h;

        if (current > target) {
            var width = box.h * target;
            box.x += (box.w - width) / 2;
            box.w = width;
        } else {
            var height = box.w / target;
            box.y += (box.h - height) / 2;
            box.h = height;
        }

        return box;
    }

    /* ---------------------------------------------------------------
     * Mug 3D preview — a textured cylinder drawn column by column
     * --------------------------------------------------------------- */

    function parseHex(hex) {
        var value = String(hex || '#ffffff').replace('#', '');

        if (3 === value.length) {
            value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
        }

        var number = parseInt(value, 16);

        if (isNaN(number)) {
            return { r: 255, g: 255, b: 255 };
        }

        return { r: (number >> 16) & 255, g: (number >> 8) & 255, b: number & 255 };
    }

    /** Positive amount lightens towards white, negative darkens towards black. */
    function shade(hex, amount, alpha) {
        var rgb = parseHex(hex);
        var target = amount < 0 ? 0 : 255;
        var ratio = Math.min(1, Math.abs(amount));
        var mixed = function (channel) {
            return Math.round(channel + (target - channel) * ratio);
        };

        return 'rgba(' + mixed(rgb.r) + ',' + mixed(rgb.g) + ',' + mixed(rgb.b) + ',' + (undefined === alpha ? 1 : alpha) + ')';
    }

    function MugRenderer(canvas, options) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.options = options || {};
        this.texture = null;
        this.color = '#ffffff';
        // How much of the circumference the printed wrap covers, how tall the
        // body is relative to its diameter, and how much of that height the
        // print band uses. Replaced by setGeometry() with the real numbers.
        this.wrapFraction = 0.72;
        this.heightRatio = 1.16;
        this.bandRatio = 0.95;
        this.rotation = 0;
        this.spinning = true;
        this.raf = null;
        this.dragging = false;
        this.lastX = 0;
        this.bindEvents();
    }

    MugRenderer.prototype.bindEvents = function () {
        var self = this;

        this.canvas.addEventListener('pointerdown', function (event) {
            self.dragging = true;
            self.spinning = false;
            self.lastX = event.clientX;
            self.canvas.classList.add('is-dragging');
            self.canvas.setPointerCapture(event.pointerId);
        });

        this.canvas.addEventListener('pointermove', function (event) {
            if (!self.dragging) {
                return;
            }

            self.rotation += (event.clientX - self.lastX) * 0.01;
            self.lastX = event.clientX;
            self.draw();
        });

        ['pointerup', 'pointercancel'].forEach(function (type) {
            self.canvas.addEventListener(type, function () {
                self.dragging = false;
                self.canvas.classList.remove('is-dragging');
            });
        });
    };

    MugRenderer.prototype.setTexture = function (texture) {
        this.texture = texture;
        this.draw();
    };

    MugRenderer.prototype.setColor = function (hex) {
        this.color = hex || '#ffffff';
        this.draw();
    };

    MugRenderer.prototype.setGeometry = function (geometry) {
        this.wrapFraction = clamp(geometry.wrapFraction, 0.1, 1);
        this.heightRatio = clamp(geometry.heightRatio, 0.4, 4);
        this.bandRatio = clamp(geometry.bandRatio, 0.1, 1);
    };

    MugRenderer.prototype.start = function () {
        var self = this;

        if (this.raf) {
            return;
        }

        var tick = function () {
            if (self.spinning) {
                self.rotation += 0.005;
            }

            self.draw();
            self.raf = window.requestAnimationFrame(tick);
        };

        tick();
    };

    MugRenderer.prototype.stop = function () {
        if (this.raf) {
            window.cancelAnimationFrame(this.raf);
            this.raf = null;
        }
    };

    /**
     * Works out the cylinder that keeps the unwrapped texture undistorted, then
     * draws the mug back to front: far handle, body, opening, rim, near handle.
     */
    MugRenderer.prototype.draw = function () {
        var canvas = this.canvas;
        var ctx = this.ctx;
        var dpr = window.devicePixelRatio || 1;
        var cssWidth = canvas.clientWidth || 560;
        var limit = this.options.maxHeight ? this.options.maxHeight() : Infinity;
        var cssHeight = Math.max(260, Math.round(Math.min(cssWidth * 0.8, limit)));

        if (canvas.width !== Math.round(cssWidth * dpr) || canvas.height !== Math.round(cssHeight * dpr)) {
            canvas.width = Math.round(cssWidth * dpr);
            canvas.height = Math.round(cssHeight * dpr);
            canvas.style.height = cssHeight + 'px';
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);

        if (!this.texture) {
            return;
        }

        var rimRatio = 0.22;
        var byWidth = (cssWidth * 0.9) / 2.65;
        var byHeight = (cssHeight * 0.86) / (2 * this.heightRatio + 2 * rimRatio);
        var radius = Math.max(30, Math.min(byWidth, byHeight));

        var geo = {
            cx: cssWidth / 2,
            radius: radius,
            rimY: radius * rimRatio,
            bodyHeight: 2 * radius * this.heightRatio
        };

        geo.bandHeight = geo.bodyHeight * this.bandRatio;
        geo.bandTop = (geo.bodyHeight - geo.bandHeight) / 2;
        geo.top = (cssHeight - (geo.bodyHeight + geo.rimY * 2)) / 2 + geo.rimY;

        // The handle sits opposite the middle of the printed wrap.
        var handleAngle = Math.PI - this.rotation;
        var handleSin = Math.sin(handleAngle);
        var handleDepth = Math.cos(handleAngle);

        this.drawGroundShadow(ctx, geo);

        if (handleDepth <= 0) {
            this.drawHandle(ctx, geo, handleSin);
        }

        this.drawOpening(ctx, geo);
        this.drawBody(ctx, geo);
        this.drawRim(ctx, geo);

        if (handleDepth > 0) {
            this.drawHandle(ctx, geo, handleSin);
        }
    };

    MugRenderer.prototype.bodyPath = function (ctx, geo) {
        var steps = 60;
        var i;
        var ratio;

        ctx.moveTo(geo.cx - geo.radius, geo.top);

        for (i = 0; i <= steps; i++) {
            ratio = -1 + (2 * i) / steps;
            ctx.lineTo(geo.cx + ratio * geo.radius, geo.top + geo.rimY * Math.sqrt(Math.max(0, 1 - ratio * ratio)));
        }

        for (i = steps; i >= 0; i--) {
            ratio = -1 + (2 * i) / steps;
            ctx.lineTo(geo.cx + ratio * geo.radius, geo.top + geo.bodyHeight + geo.rimY * Math.sqrt(Math.max(0, 1 - ratio * ratio)));
        }

        ctx.closePath();
    };

    MugRenderer.prototype.drawGroundShadow = function (ctx, geo) {
        var baseY = geo.top + geo.bodyHeight + geo.rimY;

        ctx.save();
        var gradient = ctx.createRadialGradient(geo.cx, baseY, geo.radius * 0.15, geo.cx, baseY, geo.radius * 1.5);
        gradient.addColorStop(0, 'rgba(15, 23, 42, 0.3)');
        gradient.addColorStop(0.55, 'rgba(15, 23, 42, 0.1)');
        gradient.addColorStop(1, 'rgba(15, 23, 42, 0)');
        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.ellipse(geo.cx, baseY + geo.rimY * 0.25, geo.radius * 1.45, geo.rimY * 0.9, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    };

    /** The opening: the ceramic rim ring and the shaded cavity inside it. */
    MugRenderer.prototype.drawOpening = function (ctx, geo) {
        var innerRx = geo.radius * 0.87;
        var innerRy = geo.rimY * 0.84;

        ctx.save();

        ctx.fillStyle = shade(this.color, -0.12);
        ctx.beginPath();
        ctx.ellipse(geo.cx, geo.top, geo.radius, geo.rimY, 0, 0, Math.PI * 2);
        ctx.fill();

        var cavity = ctx.createLinearGradient(0, geo.top - innerRy, 0, geo.top + innerRy);
        cavity.addColorStop(0, shade(this.color, -0.62));
        cavity.addColorStop(0.55, shade(this.color, -0.45));
        cavity.addColorStop(1, shade(this.color, -0.2));
        ctx.fillStyle = cavity;
        ctx.beginPath();
        ctx.ellipse(geo.cx, geo.top + geo.rimY * 0.05, innerRx, innerRy, 0, 0, Math.PI * 2);
        ctx.fill();

        ctx.restore();
    };

    MugRenderer.prototype.drawBody = function (ctx, geo) {
        var texture = this.texture;
        var radius = geo.radius;
        var step = 1;
        var x;

        ctx.save();
        ctx.beginPath();
        this.bodyPath(ctx, geo);
        ctx.clip();

        // A solid base coat keeps the background from showing through the
        // one pixel wide texture columns.
        ctx.fillStyle = this.color;
        ctx.fillRect(geo.cx - radius, geo.top - geo.rimY, radius * 2, geo.bodyHeight + geo.rimY * 3);

        var halfWrap = Math.PI * this.wrapFraction;

        for (x = -radius; x <= radius; x += step) {
            var ratio = clamp(x / radius, -1, 1);
            var angle = this.rotation + Math.asin(ratio);

            // Normalise to (-PI, PI]; outside the printed wrap the base coat and
            // the shading below are all there is, which is the bare ceramic.
            angle = angle - Math.PI * 2 * Math.round(angle / (Math.PI * 2));

            if (Math.abs(angle) > halfWrap) {
                continue;
            }

            var u = (angle + halfWrap) / (2 * halfWrap);
            var shift = geo.rimY * Math.sqrt(Math.max(0, 1 - ratio * ratio));

            ctx.drawImage(
                texture,
                clamp(u * texture.width, 0, texture.width - 1), 0, 1, texture.height,
                geo.cx + x, geo.top + shift + geo.bandTop, step + 1, geo.bandHeight
            );
        }

        var across = ctx.createLinearGradient(geo.cx - radius, 0, geo.cx + radius, 0);
        across.addColorStop(0, 'rgba(0, 0, 0, 0.45)');
        across.addColorStop(0.1, 'rgba(0, 0, 0, 0.2)');
        across.addColorStop(0.26, 'rgba(255, 255, 255, 0.22)');
        across.addColorStop(0.42, 'rgba(255, 255, 255, 0.05)');
        across.addColorStop(0.66, 'rgba(0, 0, 0, 0.06)');
        across.addColorStop(0.88, 'rgba(0, 0, 0, 0.26)');
        across.addColorStop(1, 'rgba(0, 0, 0, 0.48)');
        ctx.fillStyle = across;
        ctx.fillRect(geo.cx - radius, geo.top - geo.rimY, radius * 2, geo.bodyHeight + geo.rimY * 3);

        var down = ctx.createLinearGradient(0, geo.top, 0, geo.top + geo.bodyHeight + geo.rimY);
        down.addColorStop(0, 'rgba(0, 0, 0, 0.12)');
        down.addColorStop(0.12, 'rgba(0, 0, 0, 0)');
        down.addColorStop(0.82, 'rgba(0, 0, 0, 0)');
        down.addColorStop(1, 'rgba(0, 0, 0, 0.22)');
        ctx.fillStyle = down;
        ctx.fillRect(geo.cx - radius, geo.top, radius * 2, geo.bodyHeight + geo.rimY);

        ctx.restore();

        ctx.save();
        ctx.beginPath();
        this.bodyPath(ctx, geo);
        ctx.strokeStyle = shade(this.color, -0.45, 0.35);
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.restore();
    };

    /** The lip drawn over the top of the body so the ceramic edge reads. */
    MugRenderer.prototype.drawRim = function (ctx, geo) {
        ctx.save();
        ctx.beginPath();
        ctx.ellipse(geo.cx, geo.top, geo.radius, geo.rimY, 0, 0, Math.PI * 2);
        ctx.ellipse(geo.cx, geo.top + geo.rimY * 0.05, geo.radius * 0.87, geo.rimY * 0.84, 0, 0, Math.PI * 2, true);

        var lip = ctx.createLinearGradient(geo.cx - geo.radius, 0, geo.cx + geo.radius, 0);
        lip.addColorStop(0, shade(this.color, -0.3));
        lip.addColorStop(0.3, shade(this.color, 0.28));
        lip.addColorStop(0.7, shade(this.color, 0.08));
        lip.addColorStop(1, shade(this.color, -0.34));
        ctx.fillStyle = lip;
        ctx.fill();

        ctx.strokeStyle = shade(this.color, -0.4, 0.4);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.ellipse(geo.cx, geo.top, geo.radius, geo.rimY, 0, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
    };

    /**
     * The handle is a ring standing in the plane of the surface normal, so its
     * width collapses with the sine of the angle while its height stays put.
     */
    MugRenderer.prototype.drawHandle = function (ctx, geo, sin) {
        var radius = geo.radius;
        var reach = radius * 0.56;
        var centerX = geo.cx + (radius + reach * 0.34) * sin;
        var centerY = geo.top + geo.rimY + geo.bodyHeight * 0.46;
        var thickness = radius * 0.17;
        var outerRx = Math.max(thickness * 0.5, (reach * 0.7 + thickness) * Math.abs(sin));
        var outerRy = geo.bodyHeight * 0.3;
        var innerRx = outerRx - thickness * Math.abs(sin);
        var innerRy = outerRy - thickness;

        ctx.save();
        ctx.beginPath();
        ctx.ellipse(centerX, centerY, outerRx, outerRy, 0, 0, Math.PI * 2);

        if (innerRx > 0.6 && innerRy > 0.6) {
            ctx.ellipse(centerX, centerY, innerRx, innerRy, 0, 0, Math.PI * 2, true);
        }

        var gradient = ctx.createLinearGradient(centerX - outerRx, centerY - outerRy, centerX + outerRx, centerY + outerRy);
        gradient.addColorStop(0, shade(this.color, 0.26));
        gradient.addColorStop(0.4, shade(this.color, -0.04));
        gradient.addColorStop(1, shade(this.color, -0.38));
        ctx.fillStyle = gradient;
        ctx.fill();

        ctx.strokeStyle = shade(this.color, -0.45, 0.4);
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.restore();
    };

    /* ---------------------------------------------------------------
     * Designer
     * --------------------------------------------------------------- */

    function Designer(root, config) {
        this.root = root;
        this.config = config;
        this.template = config.template;
        this.settings = config.settings;
        this.views = this.template.views;
        this.activeView = this.views.length ? this.views[0].key : '';
        this.color = this.template.colors.length ? this.template.colors[0] : null;
        this.layers = [];
        this.selectedId = null;
        this.viewNodes = {};
        this.mode3d = false;
        this.busy = false;
        this.savedDesignId = 0;
        this.build();
    }

    Designer.prototype.t = function (key) {
        return t(key);
    };

    Designer.prototype.build = function () {
        var self = this;

        this.root.innerHTML = '';
        this.root.classList.add('pod-designer--ready');

        this.stage = h('div', { class: 'pod-stage' });
        this.stageInner = h('div', { class: 'pod-stage__inner' });
        this.stage.appendChild(this.stageInner);

        this.views.forEach(function (view) {
            var mockup = h('img', { class: 'pod-view__mockup', alt: '', src: view.image });
            var tint = h('div', { class: 'pod-view__tint' });
            var area = h('div', { class: 'pod-view__area' });
            var outline = h('div', { class: 'pod-view__outline' });

            // Bands the press cannot reproduce, marked so the customer sees
            // that anything placed there will be trimmed from the print file.
            [['top', view.safeTop], ['bottom', view.safeBottom]].forEach(function (band) {
                if (!band[1]) {
                    return;
                }

                outline.appendChild(h('span', {
                    class: 'pod-nogo pod-nogo--' + band[0],
                    style: 'height:' + band[1] + '%',
                    title: self.t('noPrint')
                }, [h('span', { class: 'pod-nogo__label', text: self.t('noPrint') })]));
            });

            var node = h('div', { class: 'pod-view' }, [mockup, tint, outline, area]);

            node.dataset.view = view.key;
            mockup.addEventListener('load', function () {
                self.layout();
            });

            area.addEventListener('pointerdown', function (event) {
                if (event.target === area) {
                    self.select(null);
                }
            });

            self.viewNodes[view.key] = { node: node, mockup: mockup, tint: tint, area: area, outline: outline, view: view };
            self.stageInner.appendChild(node);
        });

        this.canvas3d = h('canvas', { class: 'pod-stage__3d' });
        this.stageInner.appendChild(this.canvas3d);

        this.tabs = h('div', { class: 'pod-tabs' });
        this.toolbar = h('div', { class: 'pod-toolbar' });
        this.panel = h('div', { class: 'pod-panel' });

        var main = h('div', { class: 'pod-designer__main' }, [this.tabs, this.stage, this.toolbar]);
        this.root.appendChild(h('div', { class: 'pod-designer__grid' }, [main, this.panel]));

        this.buildTabs();
        this.buildPanel();
        this.renderColorLayer();
        this.showView(this.activeView);
        this.renderLayers();
        this.renderToolbar();

        window.addEventListener('resize', function () {
            self.layout();
        });

        document.addEventListener('keydown', function (event) {
            self.onKeyDown(event);
        });
    };

    Designer.prototype.buildTabs = function () {
        var self = this;

        this.tabs.innerHTML = '';

        if (this.views.length > 1) {
            var group = h('div', { class: 'pod-tabs__group' });

            this.views.forEach(function (view) {
                var button = h('button', {
                    type: 'button',
                    class: 'pod-tab' + (view.key === self.activeView ? ' is-active' : ''),
                    text: view.label,
                    onClick: function () {
                        if (self.mode3d) {
                            self.set3d(false);
                        }

                        self.showView(view.key);
                    }
                });

                button.dataset.viewTab = view.key;
                group.appendChild(button);
            });

            this.tabs.appendChild(group);
        }

        if (this.template.allow3d) {
            var toggle = h('div', { class: 'pod-tabs__group pod-tabs__group--right' });

            this.button2d = h('button', {
                type: 'button',
                class: 'pod-tab is-active',
                text: this.t('view2d'),
                onClick: function () {
                    self.set3d(false);
                }
            });

            this.button3d = h('button', {
                type: 'button',
                class: 'pod-tab',
                text: this.t('view3d'),
                onClick: function () {
                    self.set3d(true);
                }
            });

            toggle.appendChild(this.button2d);
            toggle.appendChild(this.button3d);
            this.tabs.appendChild(toggle);
        }
    };

    Designer.prototype.buildPanel = function () {
        var self = this;

        this.panel.innerHTML = '';

        if (this.template.colors.length) {
            var swatches = h('div', { class: 'pod-swatches' });

            this.template.colors.forEach(function (color) {
                var swatch = h('button', {
                    type: 'button',
                    class: 'pod-swatch' + (self.color && color.id === self.color.id ? ' is-active' : ''),
                    title: color.name,
                    style: 'background:' + color.hex,
                    onClick: function () {
                        self.setColor(color);
                    }
                });

                swatch.dataset.colorId = color.id;
                swatches.appendChild(swatch);
            });

            var colorSection = this.section(this.colorTitleText(), [swatches]);
            this.colorTitle = colorSection.querySelector('.pod-section__title');
            this.panel.appendChild(colorSection);
        }

        var uploadInput = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', class: 'pod-file-input' });

        uploadInput.addEventListener('change', function () {
            if (uploadInput.files && uploadInput.files[0]) {
                self.uploadFile(uploadInput.files[0]);
                uploadInput.value = '';
            }
        });

        // The input stays outside the drop zone: a programmatic click on a child
        // would bubble back into the drop zone handler.
        var dropZone = h('div', { class: 'pod-dropzone' }, [
            h('strong', { text: this.t('upload') }),
            h('span', { text: t('dropHint') })
        ]);

        dropZone.addEventListener('click', function () {
            uploadInput.click();
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) {
                event.preventDefault();
                dropZone.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (type) {
            dropZone.addEventListener(type, function (event) {
                event.preventDefault();
                dropZone.classList.remove('is-over');
            });
        });

        dropZone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]) {
                self.uploadFile(event.dataTransfer.files[0]);
            }
        });

        var uploadChildren = [dropZone, uploadInput];

        if (this.settings.enableText) {
            uploadChildren.push(h('button', {
                type: 'button',
                class: 'pod-button pod-button--ghost',
                text: '+ ' + this.t('addText'),
                onClick: function () {
                    self.addTextLayer();
                }
            }));
        }

        this.panel.appendChild(this.section(this.t('upload'), uploadChildren));

        this.layerList = h('div', { class: 'pod-layers' });
        this.issueBox = h('div', { class: 'pod-issues' });
        this.panel.appendChild(this.section(this.t('layers'), [this.layerList, this.issueBox]));

        this.buildSubmit();
    };

    Designer.prototype.colorTitleText = function () {
        return this.t('colors') + (this.color ? ': ' + this.color.name : '');
    };

    /**
     * Only the swatches and the mockup change: rebuilding the whole panel here
     * would wipe anything already typed into the submission form.
     */
    Designer.prototype.setColor = function (color) {
        var self = this;

        this.color = color;
        this.savedDesignId = 0;

        Array.prototype.forEach.call(this.panel.querySelectorAll('.pod-swatch'), function (swatch) {
            swatch.classList.toggle('is-active', swatch.dataset.colorId === color.id);
        });

        if (this.colorTitle) {
            this.colorTitle.textContent = this.colorTitleText();
        }

        this.renderColorLayer();
        this.refresh3d();

        window.setTimeout(function () {
            self.layout();
        }, 0);
    };

    Designer.prototype.section = function (title, children) {
        return h('section', { class: 'pod-section' }, [h('h3', { class: 'pod-section__title', text: title })].concat(children));
    };

    Designer.prototype.buildSubmit = function () {
        var self = this;

        this.message = h('p', { class: 'pod-message' });

        if ('woo' === this.config.mode) {
            this.panel.appendChild(this.section('', [
                h('p', { class: 'pod-hint', text: t('wooHint') }),
                this.message
            ]));
            this.bindWooForm();
            return;
        }

        this.fields = {};

        var makeField = function (key, label, type, required) {
            var input = 'textarea' === type
                ? h('textarea', { rows: 3, class: 'pod-input' })
                : h('input', { type: type, class: 'pod-input' });

            if (required) {
                input.setAttribute('required', 'required');
            }

            self.fields[key] = input;

            return h('label', { class: 'pod-field' }, [h('span', { text: label + (required ? ' *' : '') }), input]);
        };

        var quantity = makeField('quantity', this.t('quantity'), 'number', false);
        this.fields.quantity.value = '1';
        this.fields.quantity.setAttribute('min', '1');

        this.submitButton = h('button', {
            type: 'button',
            class: 'pod-button pod-button--primary',
            text: this.settings.submitLabel,
            onClick: function () {
                self.submitStandalone();
            }
        });

        this.panel.appendChild(this.section(this.settings.submitLabel, [
            makeField('name', this.t('name'), 'text', true),
            makeField('email', this.t('email'), 'email', true),
            makeField('phone', this.t('phone'), 'tel', !!this.settings.requirePhone),
            quantity,
            makeField('note', this.t('note'), 'textarea', false),
            this.submitButton,
            this.message
        ]));
    };

    Designer.prototype.bindWooForm = function () {
        var self = this;
        var form = this.root.closest('form.cart');

        if (!form) {
            return;
        }

        this.wooForm = form;
        this.designField = form.querySelector('.pod-design-id-field');

        form.addEventListener('submit', function (event) {
            if (self.savedDesignId && self.designField && self.designField.value) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (self.busy) {
                return;
            }

            self.save().then(function (designId) {
                if (self.designField) {
                    self.designField.value = designId;
                }

                self.ensureAddToCartField(form);
                form.submit();
            }).catch(function (error) {
                self.setMessage(error.message, true);
            });
        });

        var button = form.querySelector('button[type="submit"].single_add_to_cart_button');

        if (button && this.settings.addToCartLabel) {
            button.textContent = this.settings.addToCartLabel;
        }
    };

    /**
     * form.submit() does not send the name/value of the submit button, so the
     * add-to-cart product id has to exist as a real field.
     */
    Designer.prototype.ensureAddToCartField = function (form) {
        if (form.querySelector('input[name="add-to-cart"]')) {
            return;
        }

        var button = form.querySelector('button[name="add-to-cart"]');
        var value = button ? button.value : this.config.productId;

        form.appendChild(h('input', { type: 'hidden', name: 'add-to-cart', value: value }));
    };

    /* ---------------------------------------------------------------
     * Layout and rendering
     * --------------------------------------------------------------- */

    Designer.prototype.viewByKey = function (key) {
        for (var i = 0; i < this.views.length; i++) {
            if (this.views[i].key === key) {
                return this.views[i];
            }
        }

        return null;
    };

    Designer.prototype.currentView = function () {
        return this.viewByKey(this.activeView) || this.views[0];
    };

    Designer.prototype.showView = function (key) {
        var self = this;

        this.activeView = key;

        Object.keys(this.viewNodes).forEach(function (viewKey) {
            self.viewNodes[viewKey].node.classList.toggle('is-active', viewKey === key && !self.mode3d);
        });

        Array.prototype.forEach.call(this.tabs.querySelectorAll('[data-view-tab]'), function (tab) {
            tab.classList.toggle('is-active', tab.dataset.viewTab === key);
        });

        this.select(null);
        this.layout();
        this.renderLayers();
    };

    Designer.prototype.set3d = function (enabled) {
        var self = this;

        this.mode3d = enabled;
        this.stage.classList.toggle('pod-stage--3d', enabled);

        if (this.button2d) {
            this.button2d.classList.toggle('is-active', !enabled);
            this.button3d.classList.toggle('is-active', enabled);
        }

        Object.keys(this.viewNodes).forEach(function (viewKey) {
            self.viewNodes[viewKey].node.classList.toggle('is-active', viewKey === self.activeView && !enabled);
        });

        if (!enabled) {
            if (this.mug) {
                this.mug.stop();
            }

            this.layout();
            return;
        }

        if (!this.mug) {
            this.mug = new MugRenderer(this.canvas3d, {
                maxHeight: function () {
                    return window.innerHeight * (self.settings.stageHeight || 74) / 100;
                }
            });
        }

        this.mug.spinning = true;
        this.mug.start();
        this.refresh3d();
    };

    Designer.prototype.refresh3d = function () {
        var self = this;

        if (!this.mode3d || !this.mug) {
            return;
        }

        var view = this.views[0];
        var diameter = this.template.mugDiameterMm || 82;
        var mugHeight = this.template.mugHeightMm || 95;

        this.mug.color = this.color ? this.color.hex : '#ffffff';
        this.mug.setGeometry({
            wrapFraction: view.widthMm / (Math.PI * diameter),
            heightRatio: mugHeight / diameter,
            bandRatio: view.heightMm / mugHeight
        });

        this.buildMugTexture().then(function (texture) {
            self.mug.setTexture(texture);
        }).catch(function () {
            /* Ignore texture errors, the 2D view stays authoritative. */
        });
    };

    Designer.prototype.mockupUrl = function (view) {
        if (this.color && view.colorImages && view.colorImages[this.color.id]) {
            return view.colorImages[this.color.id];
        }

        return view.image;
    };

    Designer.prototype.usesTint = function (view) {
        if (!this.color) {
            return false;
        }

        if (view.colorImages && view.colorImages[this.color.id]) {
            return false;
        }

        return this.color.hex.toLowerCase() !== '#ffffff';
    };

    Designer.prototype.renderColorLayer = function () {
        var self = this;

        this.views.forEach(function (view) {
            var node = self.viewNodes[view.key];
            var url = self.mockupUrl(view);

            if (node.mockup.getAttribute('src') !== url) {
                node.mockup.setAttribute('src', url);
            }

            if (self.usesTint(view)) {
                node.tint.style.display = 'block';
                node.tint.style.background = self.color.hex;
                node.tint.style.webkitMaskImage = 'url("' + url + '")';
                node.tint.style.maskImage = 'url("' + url + '")';
            } else {
                node.tint.style.display = 'none';
            }
        });
    };

    /**
     * Pulls the designer out of the theme's content column. Block themes pin
     * their children with `margin-inline: auto !important`, so the override has
     * to be an important inline style, and the offset has to be measured rather
     * than expressed as `50% - 50vw`: the container is not always centred in
     * the viewport, a WooCommerce product summary column being the usual case.
     */
    Designer.prototype.applyBreakout = function () {
        var full = this.root.classList.contains('pod-designer--full');

        if (!full && !this.root.classList.contains('pod-designer--wide')) {
            return;
        }

        var parent = this.root.parentNode;

        if (!parent || !parent.getBoundingClientRect) {
            return;
        }

        // clientWidth is the viewport without the scrollbar, unlike 100vw.
        var viewport = document.documentElement.clientWidth;
        var style = window.getComputedStyle(parent);
        var parentLeft = parent.getBoundingClientRect().left
            + (parseFloat(style.paddingLeft) || 0)
            + (parseFloat(style.borderLeftWidth) || 0);

        var width = full ? viewport : Math.min(1500, viewport - 32);
        var left = (viewport - width) / 2;

        this.root.style.setProperty('width', width + 'px', 'important');
        this.root.style.setProperty('margin-left', (left - parentLeft) + 'px', 'important');
        this.root.style.setProperty('margin-right', '0', 'important');
    };

    Designer.prototype.layout = function () {
        var self = this;

        this.applyBreakout();

        this.views.forEach(function (view) {
            var node = self.viewNodes[view.key];
            var width = node.mockup.clientWidth;
            var height = node.mockup.clientHeight;

            if (!width || !height) {
                return;
            }

            // The mockup is sized by both max-width and max-height, so it may be
            // narrower than its container: everything is anchored to the image
            // box rather than to the view element.
            var originX = node.mockup.offsetLeft;
            var originY = node.mockup.offsetTop;
            var box = fitArea(view.area, width, height, view.widthMm, view.heightMm);

            box.x += originX;
            box.y += originY;

            ['area', 'outline'].forEach(function (key) {
                node[key].style.left = box.x + 'px';
                node[key].style.top = box.y + 'px';
                node[key].style.width = box.w + 'px';
                node[key].style.height = box.h + 'px';
            });

            Array.prototype.forEach.call(node.outline.querySelectorAll('.pod-nogo'), function (band) {
                band.classList.toggle('is-thin', band.offsetHeight < 15);
            });

            node.tint.style.left = originX + 'px';
            node.tint.style.top = originY + 'px';
            node.tint.style.width = width + 'px';
            node.tint.style.height = height + 'px';

            node.box = box;
        });

        this.updateLayerNodes();
    };

    Designer.prototype.pxPerMm = function (viewKey) {
        var node = this.viewNodes[viewKey];

        if (!node || !node.box) {
            return 1;
        }

        return node.box.w / node.view.widthMm;
    };

    Designer.prototype.layersFor = function (viewKey) {
        return this.layers.filter(function (layer) {
            return layer.view === viewKey;
        });
    };

    Designer.prototype.renderLayers = function () {
        var self = this;

        Object.keys(this.viewNodes).forEach(function (viewKey) {
            var node = self.viewNodes[viewKey];
            node.area.innerHTML = '';

            self.layersFor(viewKey).forEach(function (layer) {
                node.area.appendChild(self.buildLayerNode(layer));
            });
        });

        this.updateLayerNodes();
        this.renderLayerList();
        this.renderToolbar();
    };

    Designer.prototype.buildLayerNode = function (layer) {
        var self = this;
        var content;

        if ('text' === layer.type) {
            content = h('div', { class: 'pod-layer__text', text: layer.text });
        } else {
            content = h('img', { class: 'pod-layer__image', src: layer.url, alt: '', draggable: 'false' });
        }

        var node = h('div', { class: 'pod-layer' }, [
            content,
            h('span', { class: 'pod-layer__flag' }, [warningIcon()]),
            h('span', { class: 'pod-handle pod-handle--rotate', 'data-handle': 'rotate' }),
            h('span', { class: 'pod-handle pod-handle--scale', 'data-handle': 'scale' }),
            h('button', { type: 'button', class: 'pod-handle pod-handle--remove', 'data-handle': 'remove', text: '×' })
        ]);

        node.dataset.layerId = layer.id;
        layer.node = node;
        layer.content = content;

        node.addEventListener('pointerdown', function (event) {
            self.onLayerPointerDown(event, layer);
        });

        return node;
    };

    Designer.prototype.updateLayerNodes = function () {
        var self = this;

        this.layers.forEach(function (layer) {
            if (!layer.node) {
                return;
            }

            var view = self.viewNodes[layer.view];

            if (!view || !view.box) {
                return;
            }

            var scale = self.pxPerMm(layer.view);

            layer.node.style.left = (layer.x / view.view.widthMm) * 100 + '%';
            layer.node.style.top = (layer.y / view.view.heightMm) * 100 + '%';
            layer.node.style.width = (layer.w / view.view.widthMm) * 100 + '%';
            layer.node.style.height = (layer.h / view.view.heightMm) * 100 + '%';
            layer.node.style.transform = 'translate(-50%, -50%) rotate(' + layer.rotation + 'deg)';
            layer.node.classList.toggle('is-selected', layer.id === self.selectedId);

            if ('text' === layer.type) {
                layer.content.style.fontFamily = layer.font;
                layer.content.style.fontWeight = layer.weight;
                layer.content.style.color = layer.color;
                layer.content.style.textAlign = layer.align;
                layer.content.style.fontSize = layer.fontSize * scale + 'px';
                layer.content.textContent = layer.text;

                self.measureText(layer, scale);
            }

            layer.issue = self.layerIssue(layer);
            layer.node.classList.toggle('is-invalid', !!layer.issue);
        });
    };

    /**
     * Axis aligned bounding box of the layer after rotation, in millimetres.
     */
    function rotatedBounds(layer) {
        var radians = (layer.rotation * Math.PI) / 180;
        var cos = Math.abs(Math.cos(radians));
        var sin = Math.abs(Math.sin(radians));
        var width = layer.w * cos + layer.h * sin;
        var height = layer.w * sin + layer.h * cos;

        return {
            left: layer.x - width / 2,
            right: layer.x + width / 2,
            top: layer.y - height / 2,
            bottom: layer.y + height / 2
        };
    }

    /** The print area minus the bands the press cannot reproduce. */
    Designer.prototype.printableBounds = function (view) {
        return {
            left: 0,
            right: view.widthMm,
            top: (view.heightMm * (view.safeTop || 0)) / 100,
            bottom: view.heightMm * (1 - (view.safeBottom || 0) / 100)
        };
    };

    /**
     * Anything sticking out of the printable area is an error, not something to
     * silently crop. The tolerance is a twentieth of a millimetre, below one
     * pixel at 300 DPI, so it only absorbs rounding noise.
     */
    Designer.prototype.layerIssue = function (layer) {
        var view = this.viewByKey(layer.view);

        if (!view) {
            return null;
        }

        var bounds = rotatedBounds(layer);
        var area = this.printableBounds(view);
        var tolerance = 0.05;
        var sides = [];

        if (bounds.left < area.left - tolerance) {
            sides.push(t('sideLeft'));
        }

        if (bounds.right > area.right + tolerance) {
            sides.push(t('sideRight'));
        }

        if (bounds.top < area.top - tolerance) {
            sides.push(t('sideTop'));
        }

        if (bounds.bottom > area.bottom + tolerance) {
            sides.push(t('sideBottom'));
        }

        if (!sides.length) {
            return null;
        }

        return tf('outsideArea', sides.join(', '));
    };

    Designer.prototype.invalidLayers = function () {
        var self = this;

        return this.layers.filter(function (layer) {
            return !!self.layerIssue(layer);
        });
    };

    function warningIcon(title) {
        var icon = h('span', { class: 'pod-warning', title: title || '' });

        icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            + '<path class="pod-warning__body" d="M12 2.8 1.2 21.2h21.6L12 2.8Z"/>'
            + '<rect class="pod-warning__mark" x="11" y="8.6" width="2" height="6.4" rx="1"/>'
            + '<circle class="pod-warning__mark" cx="12" cy="18" r="1.25"/></svg>';

        return icon;
    }

    Designer.prototype.measureText = function (layer, scale) {
        var view = this.viewNodes[layer.view];

        if (!view || !view.box || !layer.content) {
            return;
        }

        layer.node.style.width = 'auto';
        layer.node.style.height = 'auto';

        // offsetWidth/Height ignore the rotation transform on the parent, so the
        // stored millimetre box stays the unrotated size of the text.
        var width = layer.content.offsetWidth / scale;
        var height = layer.content.offsetHeight / scale;

        if (width > 0 && height > 0) {
            layer.w = round(width, 2);
            layer.h = round(height, 2);
        }
    };

    Designer.prototype.renderLayerList = function () {
        var self = this;

        if (!this.layerList) {
            return;
        }

        this.layerList.innerHTML = '';

        var layers = this.layersFor(this.activeView);

        if (!layers.length) {
            this.layerList.appendChild(h('p', { class: 'pod-hint', text: this.t('empty') }));
            return;
        }

        layers.slice().reverse().forEach(function (layer) {
            var label = 'text' === layer.type ? layer.text.split('\n')[0] : t('imageLayer');
            var issue = self.layerIssue(layer);
            var row = h('div', {
                class: 'pod-layer-row'
                    + (layer.id === self.selectedId ? ' is-selected' : '')
                    + (issue ? ' is-invalid' : '')
            }, [
                issue ? warningIcon(issue) : null,
                h('span', { class: 'pod-layer-row__label', text: label }),
                h('button', {
                    type: 'button',
                    class: 'pod-icon-button',
                    title: self.t('deleteLayer'),
                    text: '×',
                    onClick: function (event) {
                        event.stopPropagation();
                        self.removeLayer(layer.id);
                    }
                })
            ]);

            if (issue) {
                row.appendChild(h('span', { class: 'pod-layer-row__issue', text: issue }));
            }

            if ('image' === layer.type && layer.dpi && layer.dpi < self.settings.minDpi) {
                row.appendChild(h('span', { class: 'pod-dpi-warning', text: self.t('lowDpi') }));
            }

            row.addEventListener('click', function () {
                self.select(layer.id);
            });

            self.layerList.appendChild(row);
        });

        this.renderIssues();
    };

    /**
     * A single banner above the submit button, so the error is not only visible
     * on the layer that causes it.
     */
    Designer.prototype.renderIssues = function () {
        if (!this.issueBox) {
            return;
        }

        var invalid = this.invalidLayers();

        this.issueBox.innerHTML = '';
        this.issueBox.classList.toggle('is-visible', invalid.length > 0);

        if (!invalid.length) {
            return;
        }

        var self = this;
        var text = 1 === invalid.length
            ? t('issueOne')
            : tf('issueMany', invalid.length);

        this.issueBox.appendChild(warningIcon());
        this.issueBox.appendChild(h('span', { text: text }));

        invalid.forEach(function (layer) {
            var view = self.viewByKey(layer.view);
            var label = ('text' === layer.type ? layer.text.split('\n')[0] : t('imageLayer'))
                + (view && self.views.length > 1 ? ' – ' + view.label : '');

            self.issueBox.appendChild(h('button', {
                type: 'button',
                class: 'pod-issues__jump',
                text: label,
                onClick: function () {
                    if (layer.view !== self.activeView) {
                        self.showView(layer.view);
                    }

                    self.select(layer.id);
                }
            }));
        });
    };

    /**
     * A slider paired with a number field, so a value like "back to 0 degrees"
     * can be typed instead of hunted for with the mouse.
     */
    Designer.prototype.sliderControl = function (options) {
        var slider = h('input', {
            type: 'range',
            min: options.min,
            max: options.max,
            step: options.step,
            value: options.value
        });

        var field = h('input', {
            type: 'number',
            class: 'pod-number',
            step: options.step,
            value: round(options.value, options.decimals)
        });

        var apply = function (raw, source) {
            var value = parseFloat(raw);

            if (isNaN(value)) {
                return;
            }

            value = clamp(value, options.min, options.max);

            if (source !== slider) {
                slider.value = value;
            }

            if (source !== field) {
                field.value = round(value, options.decimals);
            }

            options.onChange(value);
        };

        slider.addEventListener('input', function () {
            apply(slider.value, slider);
        });

        field.addEventListener('input', function () {
            apply(field.value, field);
        });

        // Typing can leave the field empty or out of range; snap it back on blur.
        field.addEventListener('blur', function () {
            field.value = round(clamp(parseFloat(field.value) || 0, options.min, options.max), options.decimals);
        });

        var row = h('span', { class: 'pod-control__row' }, [
            slider,
            field,
            options.suffix ? h('span', { class: 'pod-control__unit', text: options.suffix }) : null
        ]);

        if (undefined !== options.reset) {
            row.appendChild(h('button', {
                type: 'button',
                class: 'pod-control__reset',
                title: options.resetTitle || '',
                text: '⟲',
                onClick: function () {
                    apply(options.reset, null);
                }
            }));
        }

        return h('label', { class: 'pod-control' }, [
            h('span', { text: options.label }),
            row
        ]);
    };

    Designer.prototype.renderToolbar = function () {
        var self = this;
        var layer = this.getSelected();

        this.toolbar.innerHTML = '';

        if (!layer) {
            this.toolbar.appendChild(h('p', { class: 'pod-hint', text: t('selectHint') }));
            return;
        }

        var view = this.currentView();

        this.toolbar.appendChild(this.sliderControl({
            label: this.t('size'),
            suffix: 'mm',
            min: 5,
            max: Math.round(view.widthMm * 1.5),
            step: 0.5,
            value: layer.w,
            decimals: 1,
            onChange: function (value) {
                self.resizeLayer(layer, value);
            }
        }));

        this.toolbar.appendChild(this.sliderControl({
            label: this.t('rotation'),
            suffix: '°',
            min: -180,
            max: 180,
            step: 1,
            value: layer.rotation,
            decimals: 1,
            reset: 0,
            resetTitle: this.t('resetRotation'),
            onChange: function (value) {
                layer.rotation = value;
                self.updateLayerNodes();
                self.renderLayerList();
            }
        }));

        var actions = h('div', { class: 'pod-control pod-control--actions' }, [
            h('button', {
                type: 'button', class: 'pod-button pod-button--ghost', text: this.t('center'),
                onClick: function () {
                    layer.x = view.widthMm / 2;
                    layer.y = view.heightMm / 2;
                    self.updateLayerNodes();
                }
            }),
            h('button', {
                type: 'button', class: 'pod-button pod-button--ghost', text: this.t('forward'),
                onClick: function () {
                    self.reorder(layer, 1);
                }
            }),
            h('button', {
                type: 'button', class: 'pod-button pod-button--ghost', text: this.t('backward'),
                onClick: function () {
                    self.reorder(layer, -1);
                }
            }),
            h('button', {
                type: 'button', class: 'pod-button pod-button--danger', text: this.t('deleteLayer'),
                onClick: function () {
                    self.removeLayer(layer.id);
                }
            })
        ]);

        this.toolbar.appendChild(actions);

        if ('text' !== layer.type) {
            if (layer.dpi && layer.dpi < this.settings.minDpi) {
                this.toolbar.appendChild(h('p', { class: 'pod-dpi-warning', text: this.t('lowDpi') + ' (' + Math.round(layer.dpi) + ' DPI)' }));
            }

            return;
        }

        var textInput = h('textarea', { class: 'pod-input', rows: 2 });
        textInput.value = layer.text;
        textInput.addEventListener('input', function () {
            layer.text = textInput.value;
            self.updateLayerNodes();
            self.renderLayerList();
        });

        var fontSelect = h('select', { class: 'pod-input' });

        this.settings.fonts.forEach(function (font) {
            var option = h('option', { value: font.stack, text: font.label });

            if (font.stack === layer.font) {
                option.setAttribute('selected', 'selected');
            }

            fontSelect.appendChild(option);
        });

        fontSelect.addEventListener('change', function () {
            layer.font = fontSelect.value;
            self.updateLayerNodes();
        });

        var sizeField = h('input', { type: 'number', class: 'pod-input', min: 3, max: 200, step: 1, value: Math.round(layer.fontSize) });
        sizeField.addEventListener('input', function () {
            layer.fontSize = clamp(parseFloat(sizeField.value) || 10, 3, 400);
            self.updateLayerNodes();
        });

        var colorField = h('input', { type: 'color', class: 'pod-input pod-input--color', value: layer.color });
        colorField.addEventListener('input', function () {
            layer.color = colorField.value;
            self.updateLayerNodes();
        });

        var boldToggle = h('button', {
            type: 'button',
            class: 'pod-button pod-button--ghost' + ('bold' === layer.weight ? ' is-active' : ''),
            text: this.t('bold'),
            onClick: function () {
                layer.weight = 'bold' === layer.weight ? 'normal' : 'bold';
                self.updateLayerNodes();
                self.renderToolbar();
            }
        });

        this.toolbar.appendChild(h('label', { class: 'pod-control pod-control--wide' }, [h('span', { text: this.t('text') }), textInput]));
        this.toolbar.appendChild(h('label', { class: 'pod-control' }, [h('span', { text: this.t('font') }), fontSelect]));
        this.toolbar.appendChild(h('label', { class: 'pod-control' }, [h('span', { text: this.t('fontSize') + ' (mm)' }), sizeField]));
        this.toolbar.appendChild(h('label', { class: 'pod-control' }, [h('span', { text: this.t('textColor') }), colorField]));
        this.toolbar.appendChild(h('div', { class: 'pod-control' }, [boldToggle]));
    };

    /* ---------------------------------------------------------------
     * Layer operations
     * --------------------------------------------------------------- */

    Designer.prototype.getSelected = function () {
        var id = this.selectedId;

        for (var i = 0; i < this.layers.length; i++) {
            if (this.layers[i].id === id) {
                return this.layers[i];
            }
        }

        return null;
    };

    Designer.prototype.select = function (id) {
        this.selectedId = id;
        this.updateLayerNodes();
        this.renderLayerList();
        this.renderToolbar();
    };

    Designer.prototype.removeLayer = function (id) {
        this.layers = this.layers.filter(function (layer) {
            return layer.id !== id;
        });

        if (this.selectedId === id) {
            this.selectedId = null;
        }

        this.savedDesignId = 0;
        this.renderLayers();
        this.refresh3d();
    };

    Designer.prototype.reorder = function (layer, direction) {
        var index = this.layers.indexOf(layer);
        var target = index + direction;

        if (index < 0 || target < 0 || target >= this.layers.length) {
            return;
        }

        this.layers.splice(index, 1);
        this.layers.splice(target, 0, layer);
        this.renderLayers();
        this.refresh3d();
    };

    Designer.prototype.updateDpi = function (layer) {
        if ('image' !== layer.type || !layer.naturalW || layer.w <= 0) {
            return;
        }

        layer.dpi = Math.round(layer.naturalW / (layer.w / MM_PER_INCH));
    };

    Designer.prototype.resizeLayer = function (layer, widthMm) {
        var ratio = layer.h / layer.w;

        if ('text' === layer.type) {
            layer.fontSize = clamp(layer.fontSize * (widthMm / layer.w), 3, 400);
        } else {
            layer.w = clamp(widthMm, 5, 2000);
            layer.h = layer.w * ratio;
            this.updateDpi(layer);
        }

        this.updateLayerNodes();
        this.renderLayerList();
        this.refresh3d();
    };

    Designer.prototype.addImageLayer = function (data) {
        var view = this.currentView();
        var ratio = data.height / data.width;
        var width = view.widthMm * 0.6;
        var height = width * ratio;

        if (height > view.heightMm * 0.8) {
            height = view.heightMm * 0.8;
            width = height / ratio;
        }

        var layer = {
            id: uid(),
            type: 'image',
            view: view.key,
            x: view.widthMm / 2,
            y: view.heightMm / 2,
            w: round(width, 2),
            h: round(height, 2),
            rotation: 0,
            attachmentId: data.id,
            url: data.url,
            naturalW: data.width,
            naturalH: data.height,
            dpi: width > 0 ? Math.round(data.width / (width / MM_PER_INCH)) : 0
        };

        this.layers.push(layer);
        this.savedDesignId = 0;
        this.renderLayers();
        this.select(layer.id);
        this.refresh3d();
    };

    Designer.prototype.addTextLayer = function () {
        var view = this.currentView();
        var font = this.settings.fonts.length ? this.settings.fonts[0].stack : 'Arial, sans-serif';

        var layer = {
            id: uid(),
            type: 'text',
            view: view.key,
            x: view.widthMm / 2,
            y: view.heightMm / 2,
            w: view.widthMm * 0.5,
            h: view.heightMm * 0.1,
            rotation: 0,
            text: t('textDefault'),
            font: font,
            fontSize: Math.max(8, round(view.heightMm * 0.08, 1)),
            color: '#000000',
            weight: 'bold',
            align: 'center'
        };

        this.layers.push(layer);
        this.savedDesignId = 0;
        this.renderLayers();
        this.select(layer.id);
        this.refresh3d();
    };

    Designer.prototype.onKeyDown = function (event) {
        var layer = this.getSelected();

        if (!layer || !this.root.contains(document.activeElement) && document.activeElement !== document.body) {
            return;
        }

        var tag = document.activeElement ? document.activeElement.tagName : '';

        if ('INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag) {
            return;
        }

        var step = event.shiftKey ? 10 : 1;

        if ('Delete' === event.key || 'Backspace' === event.key) {
            event.preventDefault();
            this.removeLayer(layer.id);
            return;
        }

        var moves = {
            ArrowLeft: [-step, 0],
            ArrowRight: [step, 0],
            ArrowUp: [0, -step],
            ArrowDown: [0, step]
        };

        if (moves[event.key]) {
            event.preventDefault();
            layer.x += moves[event.key][0];
            layer.y += moves[event.key][1];
            this.updateLayerNodes();
        }
    };

    Designer.prototype.onLayerPointerDown = function (event, layer) {
        var self = this;
        var handle = event.target.dataset ? event.target.dataset.handle : '';

        event.preventDefault();
        event.stopPropagation();
        this.select(layer.id);

        if ('remove' === handle) {
            this.removeLayer(layer.id);
            return;
        }

        var node = this.viewNodes[layer.view];
        var scale = this.pxPerMm(layer.view);
        var rect = node.area.getBoundingClientRect();
        var centerX = rect.left + (layer.x / node.view.widthMm) * rect.width;
        var centerY = rect.top + (layer.y / node.view.heightMm) * rect.height;
        var startX = event.clientX;
        var startY = event.clientY;
        var start = { x: layer.x, y: layer.y, w: layer.w, h: layer.h, rotation: layer.rotation, fontSize: layer.fontSize };
        var startDistance = Math.hypot(startX - centerX, startY - centerY);
        var startAngle = Math.atan2(startY - centerY, startX - centerX);

        var move = function (moveEvent) {
            var dx = moveEvent.clientX - startX;
            var dy = moveEvent.clientY - startY;

            if ('scale' === handle) {
                var distance = Math.hypot(moveEvent.clientX - centerX, moveEvent.clientY - centerY);
                var factor = startDistance > 0 ? distance / startDistance : 1;

                if ('text' === layer.type) {
                    layer.fontSize = clamp(start.fontSize * factor, 3, 400);
                } else {
                    layer.w = clamp(start.w * factor, 5, node.view.widthMm * 3);
                    layer.h = layer.w * (start.h / start.w);
                    self.updateDpi(layer);
                }
            } else if ('rotate' === handle) {
                var angle = Math.atan2(moveEvent.clientY - centerY, moveEvent.clientX - centerX);
                layer.rotation = round(start.rotation + ((angle - startAngle) * 180) / Math.PI, 1);
            } else {
                layer.x = round(start.x + dx / scale, 2);
                layer.y = round(start.y + dy / scale, 2);
            }

            self.updateLayerNodes();
        };

        var up = function () {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            self.savedDesignId = 0;
            self.renderLayerList();
            self.renderToolbar();
            self.refresh3d();
        };

        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    };

    /* ---------------------------------------------------------------
     * Upload
     * --------------------------------------------------------------- */

    Designer.prototype.uploadFile = function (file) {
        var self = this;

        if (file.size > this.settings.maxUploadBytes) {
            this.setMessage(tf('fileTooLarge', Math.round(this.settings.maxUploadBytes / 1048576)), true);
            return;
        }

        var data = new FormData();
        data.append('action', 'pod_upload_image');
        data.append('nonce', GLOBALS.nonce);
        data.append('file', file);

        this.setBusy(true, t('uploading'));

        window.fetch(GLOBALS.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                self.setBusy(false);

                if (!result || !result.success) {
                    throw new Error(result && result.data ? result.data.message : t('uploadFailed'));
                }

                self.addImageLayer(result.data);
                self.setMessage('', false);
            })
            .catch(function (error) {
                self.setBusy(false);
                self.setMessage(error.message, true);
            });
    };

    /* ---------------------------------------------------------------
     * Export
     * --------------------------------------------------------------- */

    /**
     * Print resolution, capped so the canvas stays inside the pixel budget that
     * mobile browsers (iOS in particular) are willing to rasterise.
     */
    Designer.prototype.printScale = function (view, maxWidth) {
        var widthInch = view.widthMm / MM_PER_INCH;
        var heightInch = view.heightMm / MM_PER_INCH;
        var dpi = this.settings.exportDpi;

        if (widthInch * heightInch * dpi * dpi > MAX_EXPORT_PIXELS) {
            dpi = Math.floor(Math.sqrt(MAX_EXPORT_PIXELS / (widthInch * heightInch)));
        }

        if (maxWidth && widthInch * dpi > maxWidth) {
            dpi = Math.max(24, Math.floor(maxWidth / widthInch));
        }

        return {
            dpi: dpi,
            pxPerMm: dpi / MM_PER_INCH
        };
    };

    Designer.prototype.renderPrintCanvas = function (view, options) {
        var self = this;
        var scale = this.printScale(view, options && options.maxWidth);
        var canvas = document.createElement('canvas');

        canvas.width = Math.max(1, Math.round(view.widthMm * scale.pxPerMm));
        canvas.height = Math.max(1, Math.round(view.heightMm * scale.pxPerMm));

        var ctx = canvas.getContext('2d');
        var layers = this.layersFor(view.key);
        var images = layers.filter(function (layer) {
            return 'image' === layer.type;
        });

        return Promise.all(images.map(function (layer) {
            return loadImage(layer.url).then(function (img) {
                layer.loaded = img;
            });
        })).then(function () {
            // Anything reaching into the non printable bands is trimmed here, so
            // the file handed to production matches what the press can do.
            var top = ((view.safeTop || 0) / 100) * canvas.height;
            var bottom = ((view.safeBottom || 0) / 100) * canvas.height;

            if (top > 0 || bottom > 0) {
                ctx.beginPath();
                ctx.rect(0, top, canvas.width, Math.max(1, canvas.height - top - bottom));
                ctx.clip();
            }

            layers.forEach(function (layer) {
                self.drawLayer(ctx, layer, scale.pxPerMm);
            });

            return { canvas: canvas, dpi: scale.dpi };
        });
    };

    Designer.prototype.drawLayer = function (ctx, layer, pxPerMm) {
        ctx.save();
        ctx.translate(layer.x * pxPerMm, layer.y * pxPerMm);
        ctx.rotate((layer.rotation * Math.PI) / 180);

        if ('text' === layer.type) {
            var fontPx = layer.fontSize * pxPerMm;
            var lines = layer.text.split('\n');
            var lineHeight = fontPx * 1.2;

            ctx.font = layer.weight + ' ' + fontPx + 'px ' + layer.font;
            ctx.fillStyle = layer.color;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            lines.forEach(function (line, index) {
                var offset = (index - (lines.length - 1) / 2) * lineHeight;
                ctx.fillText(line, 0, offset);
            });
        } else if (layer.loaded) {
            ctx.drawImage(
                layer.loaded,
                (-layer.w / 2) * pxPerMm,
                (-layer.h / 2) * pxPerMm,
                layer.w * pxPerMm,
                layer.h * pxPerMm
            );
        }

        ctx.restore();
    };

    Designer.prototype.renderPreviewCanvas = function (view, options) {
        var self = this;
        var url = this.mockupUrl(view);

        options = options || {};

        return Promise.all([loadImage(url), this.renderPrintCanvas(view, options)]).then(function (results) {
            var mockup = results[0];
            var print = results[1];
            var width = options.previewWidth || Math.min(self.settings.previewWidth, Math.max(600, mockup.naturalWidth || 1200));
            var naturalWidth = mockup.naturalWidth || width;
            var naturalHeight = mockup.naturalHeight || Math.round(width * 1.2);
            var height = Math.round((naturalHeight / naturalWidth) * width);
            var canvas = document.createElement('canvas');

            canvas.width = width;
            canvas.height = height;

            var ctx = canvas.getContext('2d');
            ctx.drawImage(mockup, 0, 0, width, height);

            if (self.usesTint(view)) {
                var tint = document.createElement('canvas');
                tint.width = width;
                tint.height = height;

                var tintCtx = tint.getContext('2d');
                tintCtx.drawImage(mockup, 0, 0, width, height);
                tintCtx.globalCompositeOperation = 'source-in';
                tintCtx.fillStyle = self.color.hex;
                tintCtx.fillRect(0, 0, width, height);

                ctx.globalCompositeOperation = 'multiply';
                ctx.drawImage(tint, 0, 0);
                ctx.globalCompositeOperation = 'source-over';
            }

            var box = fitArea(view.area, width, height, view.widthMm, view.heightMm);
            ctx.drawImage(print.canvas, box.x, box.y, box.w, box.h);

            return { canvas: canvas, print: print };
        });
    };

    /**
     * The wrap handed to the 3D preview is the plain product colour plus the
     * artwork. The flat mockup is deliberately left out: its baked in edge
     * shading is a 2D trick, and wrapping it around the cylinder would show up
     * as dark stripes on top of the shading the renderer already applies.
     */
    Designer.prototype.buildMugTexture = function () {
        var self = this;
        var view = this.views[0];
        var options = { maxWidth: 1100 };
        var body = this.template.body;

        // Any further view printed on the same flat mockup (for example a mug
        // with a separate left and right print area) belongs on the same wrap.
        var wrapViews = this.views.filter(function (candidate) {
            return candidate.key === view.key || candidate.image === view.image;
        });

        return loadImage(this.mockupUrl(view)).then(function (mockup) {
            var width = 1100;
            var naturalWidth = mockup.naturalWidth || width;
            var naturalHeight = mockup.naturalHeight || width;
            var height = Math.max(1, Math.round((naturalHeight / naturalWidth) * width));

            var texture = document.createElement('canvas');
            texture.width = Math.max(1, Math.round((body.w / 100) * width));
            texture.height = Math.max(1, Math.round((body.h / 100) * height));

            var ctx = texture.getContext('2d');
            ctx.fillStyle = self.color ? self.color.hex : '#ffffff';
            ctx.fillRect(0, 0, texture.width, texture.height);

            var offsetX = (body.x / 100) * width;
            var offsetY = (body.y / 100) * height;

            return wrapViews.reduce(function (chain, wrapView) {
                return chain.then(function () {
                    return self.renderPrintCanvas(wrapView, options).then(function (print) {
                        var box = fitArea(wrapView.area, width, height, wrapView.widthMm, wrapView.heightMm);
                        ctx.drawImage(print.canvas, box.x - offsetX, box.y - offsetY, box.w, box.h);
                    });
                });
            }, Promise.resolve()).then(function () {
                return texture;
            });
        });
    };

    /* ---------------------------------------------------------------
     * Saving
     * --------------------------------------------------------------- */

    Designer.prototype.collectPayload = function (viewsWithLayers, dpiByView) {
        var self = this;

        return {
            mode: this.config.mode,
            product_id: this.config.productId,
            template_id: this.template.id,
            color_id: this.color ? this.color.id : '',
            views: viewsWithLayers.map(function (view) {
                return {
                    key: view.key,
                    print_dpi: dpiByView[view.key] || 0,
                    layers: self.layersFor(view.key).map(function (layer) {
                        var base = {
                            type: layer.type,
                            x: round(layer.x, 2),
                            y: round(layer.y, 2),
                            w: round(layer.w, 2),
                            h: round(layer.h, 2),
                            rotation: round(layer.rotation, 2)
                        };

                        if ('text' === layer.type) {
                            base.text = layer.text;
                            base.font = layer.font;
                            base.font_size = round(layer.fontSize, 2);
                            base.color = layer.color;
                            base.weight = layer.weight;
                            base.align = layer.align;
                        } else {
                            base.attachment_id = layer.attachmentId;
                        }

                        return base;
                    })
                };
            }),
            customer: this.collectCustomer()
        };
    };

    Designer.prototype.collectCustomer = function () {
        if (!this.fields) {
            return { name: '', email: '', phone: '', quantity: 1, note: '' };
        }

        return {
            name: this.fields.name.value.trim(),
            email: this.fields.email.value.trim(),
            phone: this.fields.phone.value.trim(),
            quantity: parseInt(this.fields.quantity.value, 10) || 1,
            note: this.fields.note.value.trim()
        };
    };

    Designer.prototype.save = function () {
        var self = this;

        var viewsWithLayers = this.views.filter(function (view) {
            return self.layersFor(view.key).length > 0;
        });

        if (!viewsWithLayers.length) {
            return Promise.reject(new Error(t('needContent')));
        }

        var invalid = this.invalidLayers();

        if (invalid.length) {
            this.showView(invalid[0].view);
            this.select(invalid[0].id);

            return Promise.reject(new Error(
                1 === invalid.length
                    ? t('issueOne')
                    : tf('issueMany', invalid.length)
            ));
        }

        this.setBusy(true, this.t('saving'));

        var data = new FormData();
        var dpiByView = {};

        return viewsWithLayers.reduce(function (chain, view) {
            return chain.then(function () {
                return self.renderPreviewCanvas(view).then(function (result) {
                    dpiByView[view.key] = result.print.dpi;

                    return Promise.all([canvasToBlob(result.canvas), canvasToBlob(result.print.canvas)]).then(function (blobs) {
                        data.append('preview_' + view.key, blobs[0], 'preview-' + view.key + '.png');
                        data.append('print_' + view.key, blobs[1], 'print-' + view.key + '.png');
                    });
                });
            });
        }, Promise.resolve()).then(function () {
            data.append('action', 'pod_save_design');
            data.append('nonce', GLOBALS.nonce);
            data.append('design', JSON.stringify(self.collectPayload(viewsWithLayers, dpiByView)));

            return window.fetch(GLOBALS.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            self.setBusy(false);

            if (!result || !result.success) {
                throw new Error(result && result.data ? result.data.message : t('saveFailed'));
            }

            self.savedDesignId = result.data.design_id;

            return result.data.design_id;
        }).catch(function (error) {
            self.setBusy(false);
            throw error;
        });
    };

    Designer.prototype.submitStandalone = function () {
        var self = this;
        var customer = this.collectCustomer();

        if (!customer.name) {
            this.setMessage(t('enterName'), true);
            return;
        }

        if (!customer.email || customer.email.indexOf('@') < 0) {
            this.setMessage(t('enterEmail'), true);
            return;
        }

        if (this.settings.requirePhone && !customer.phone) {
            this.setMessage(t('enterPhone'), true);
            return;
        }

        this.save().then(function () {
            self.setMessage(self.settings.successMessage, false);
            self.submitButton.setAttribute('disabled', 'disabled');
        }).catch(function (error) {
            self.setMessage(error.message, true);
        });
    };

    Designer.prototype.setBusy = function (busy, text) {
        this.busy = busy;
        this.root.classList.toggle('is-busy', busy);

        if (busy && text) {
            this.setMessage(text, false);
        }
    };

    Designer.prototype.setMessage = function (text, isError) {
        if (!this.message) {
            return;
        }

        this.message.textContent = text || '';
        this.message.classList.toggle('is-error', !!isError);
    };

    /* ---------------------------------------------------------------
     * Boot
     * --------------------------------------------------------------- */

    function boot() {
        var nodes = document.querySelectorAll('.pod-designer[data-pod-config]');

        Array.prototype.forEach.call(nodes, function (node) {
            if (node.podDesigner) {
                return;
            }

            var config;

            try {
                config = JSON.parse(node.getAttribute('data-pod-config'));
            } catch (error) {
                return;
            }

            if (!config || !config.template || !config.template.views.length) {
                return;
            }

            node.podDesigner = new Designer(node, config);
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
