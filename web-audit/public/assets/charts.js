/*
 * Minimal SVG line charts - no external dependency, so the tool works behind a
 * firewall. Marks follow the house spec: 2px lines, 8px dots with a 2px surface
 * ring, a 10% area wash, hairline solid gridlines, one measure per chart on one
 * axis, and a crosshair tooltip on hover.
 */
(function () {
    'use strict';

    var PAD = { top: 14, right: 16, bottom: 26, left: 46 };
    var NS = 'http://www.w3.org/2000/svg';

    function token(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (value || '').trim() || fallback;
    }

    function rgba(hex, alpha) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex.trim());
        if (!m) { return hex; }
        return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + alpha + ')';
    }

    function el(name, attrs) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        return node;
    }

    /* Round the axis top to something a person would choose. */
    function niceCeil(value) {
        if (value <= 0) { return 1; }
        var magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        var steps = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10];
        for (var i = 0; i < steps.length; i++) {
            if (value <= steps[i] * magnitude) { return steps[i] * magnitude; }
        }
        return 10 * magnitude;
    }

    function defaultFormat(options) {
        return function (value) {
            if (value === null || typeof value === 'undefined') { return 'no data'; }
            var decimals = typeof options.decimals === 'number' ? options.decimals : null;
            var shown = decimals === null
                ? (Math.abs(value) >= 100 ? Math.round(value) : Math.round(value * 100) / 100)
                : Number(value).toFixed(decimals);
            return shown + (options.suffix || '');
        };
    }

    function draw(host, options) {
        var width = host.clientWidth;
        var height = host.clientHeight;
        if (!width || !height) { return; }

        var series = options.values.map(function (v) { return (v === null || v === '' || typeof v === 'undefined') ? null : Number(v); });
        var present = series.filter(function (v) { return v !== null; });
        if (!present.length) { return; }

        var color = options.color || token('--series-1', '#2a78d6');
        var surface = token('--surface-1', '#fcfcfb');
        var muted = token('--text-muted', '#898781');
        var grid = token('--grid', '#e1e0d9');
        var baseline = token('--baseline', '#c3c2b7');
        var format = options.format || defaultFormat(options);

        var min = typeof options.min === 'number' ? options.min : 0;
        var maxData = Math.max.apply(null, present);
        if (typeof options.threshold === 'number') { maxData = Math.max(maxData, options.threshold); }
        var max = typeof options.max === 'number' ? options.max : niceCeil(maxData * 1.1) || 1;
        if (max === min) { max = min + 1; }

        /* Widen the gutter to fit the widest tick label - "1,000 ms" needs more
           room than "50", and a clipped axis label is worse than none. */
        var tickCount = height - PAD.top - PAD.bottom < 160 ? 3 : 4;
        var tickValues = [];
        var widest = 0;
        for (var ti = 0; ti <= tickCount; ti++) {
            var tickValue = min + ((max - min) * ti) / tickCount;
            var text = format(tickValue);
            tickValues.push([tickValue, text]);
            widest = Math.max(widest, String(text).length);
        }
        var padLeft = Math.min(84, Math.max(30, Math.round(widest * 6.4) + 14));

        var plotW = Math.max(10, width - padLeft - PAD.right);
        var plotH = Math.max(10, height - PAD.top - PAD.bottom);
        var stepX = series.length > 1 ? plotW / (series.length - 1) : 0;

        function x(i) { return padLeft + (series.length > 1 ? i * stepX : plotW / 2); }
        function y(v) { return PAD.top + plotH - ((v - min) / (max - min)) * plotH; }

        var svg = el('svg', {
            width: width, height: height, viewBox: '0 0 ' + width + ' ' + height,
            role: 'img', 'aria-label': (options.label || 'Chart') + ' over time'
        });

        /* Gridlines + y ticks */
        tickValues.forEach(function (tick) {
            var yy = y(tick[0]);
            svg.appendChild(el('line', {
                x1: padLeft, x2: width - PAD.right, y1: yy, y2: yy,
                stroke: grid, 'stroke-width': 1
            }));
            var label = el('text', {
                x: padLeft - 8, y: yy + 3.5, 'text-anchor': 'end',
                fill: muted, 'font-size': 11, 'font-family': 'system-ui, -apple-system, "Segoe UI", sans-serif'
            });
            label.textContent = tick[1];
            svg.appendChild(label);
        });

        /* Area wash + line */
        var points = [];
        series.forEach(function (v, i) { if (v !== null) { points.push([x(i), y(v), i, v]); } });

        var linePath = points.map(function (p, i) { return (i ? 'L' : 'M') + p[0] + ' ' + p[1]; }).join(' ');
        if (options.fill !== false && points.length > 1) {
            svg.appendChild(el('path', {
                d: linePath + ' L' + points[points.length - 1][0] + ' ' + y(min) + ' L' + points[0][0] + ' ' + y(min) + ' Z',
                fill: rgba(color, 0.10), stroke: 'none'
            }));
        }
        svg.appendChild(el('path', {
            d: linePath, fill: 'none', stroke: color,
            'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'
        }));

        /* Threshold hairline - solid, never dashed */
        if (typeof options.threshold === 'number' && options.threshold >= min && options.threshold <= max) {
            var ty = y(options.threshold);
            svg.appendChild(el('line', {
                x1: padLeft, x2: width - PAD.right, y1: ty, y2: ty, stroke: baseline, 'stroke-width': 1
            }));
            if (options.thresholdLabel) {
                var tl = el('text', {
                    x: width - PAD.right, y: ty - 5, 'text-anchor': 'end', fill: muted,
                    'font-size': 11, 'font-family': 'system-ui, -apple-system, "Segoe UI", sans-serif'
                });
                tl.textContent = options.thresholdLabel;
                svg.appendChild(tl);
            }
        }

        /* Dots with a surface ring so they stay legible where they overlap */
        points.forEach(function (p) {
            svg.appendChild(el('circle', {
                cx: p[0], cy: p[1], r: 4, fill: color, stroke: surface, 'stroke-width': 2
            }));
        });

        /* X labels - thinned so they never collide */
        var every = Math.max(1, Math.ceil(series.length / Math.max(2, Math.floor(plotW / 70))));
        series.forEach(function (v, i) {
            if (i % every !== 0 && i !== series.length - 1) { return; }
            var xt = el('text', {
                x: x(i), y: height - 8, 'text-anchor': i === 0 ? 'start' : (i === series.length - 1 ? 'end' : 'middle'),
                fill: muted, 'font-size': 11, 'font-family': 'system-ui, -apple-system, "Segoe UI", sans-serif'
            });
            xt.textContent = options.labels[i] || '';
            svg.appendChild(xt);
        });

        /* Hover layer */
        var crosshair = el('line', { x1: 0, x2: 0, y1: PAD.top, y2: PAD.top + plotH, stroke: baseline, 'stroke-width': 1, opacity: 0 });
        var marker = el('circle', { cx: 0, cy: 0, r: 6, fill: color, stroke: surface, 'stroke-width': 2, opacity: 0 });
        svg.appendChild(crosshair);
        svg.appendChild(marker);

        host.innerHTML = '';
        host.appendChild(svg);

        var tip = document.createElement('div');
        tip.className = 'chart-tip';
        tip.setAttribute('hidden', 'hidden');
        host.appendChild(tip);

        function nearest(clientX) {
            var box = svg.getBoundingClientRect();
            var px = clientX - box.left;
            var best = null;
            var bestDistance = Infinity;
            points.forEach(function (p) {
                var distance = Math.abs(p[0] - px);
                if (distance < bestDistance) { bestDistance = distance; best = p; }
            });
            return best;
        }

        svg.addEventListener('pointermove', function (event) {
            var point = nearest(event.clientX);
            if (!point) { return; }
            crosshair.setAttribute('x1', point[0]);
            crosshair.setAttribute('x2', point[0]);
            crosshair.setAttribute('opacity', 1);
            marker.setAttribute('cx', point[0]);
            marker.setAttribute('cy', point[1]);
            marker.setAttribute('opacity', 1);
            tip.removeAttribute('hidden');
            tip.innerHTML = '<span class="tip-label">' + (options.labels[point[2]] || '') + '</span>'
                + '<span class="tip-value">' + format(point[3]) + '</span>';
            var left = Math.min(Math.max(point[0] - tip.offsetWidth / 2, 4), width - tip.offsetWidth - 4);
            tip.style.left = left + 'px';
            tip.style.top = Math.max(2, point[1] - tip.offsetHeight - 12) + 'px';
        });

        svg.addEventListener('pointerleave', function () {
            crosshair.setAttribute('opacity', 0);
            marker.setAttribute('opacity', 0);
            tip.setAttribute('hidden', 'hidden');
        });
    }

    window.wvaLineChart = function (options) {
        var host = typeof options.el === 'string' ? document.getElementById(options.el) : options.el;
        if (!host || !options.values || !options.values.length) { return; }

        draw(host, options);

        var timer = null;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { draw(host, options); }, 150);
        });
    };
})();
