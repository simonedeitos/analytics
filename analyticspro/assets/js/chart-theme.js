(function () {
    'use strict';

    function readVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function palette() {
        return [
            readVar('--ap-blue') || '#2A519F',
            readVar('--ap-orange') || '#f28e0e',
            readVar('--ap-success') || '#12b76a',
            readVar('--ap-info') || '#0ba5ec',
            '#7a5af8',
            '#f97066',
            '#6172f3',
            '#17b26a'
        ];
    }

    function applyDefaults() {
        if (!window.Chart) return;
        var text = readVar('--ap-text') || '#101828';
        var muted = readVar('--ap-muted') || '#667085';
        var border = readVar('--ap-border') || '#e4e7ec';
        Chart.defaults.color = muted;
        Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.boxHeight = 8;
        Chart.defaults.plugins.legend.labels.padding = 12;
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.92)';
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.titleColor = '#fff';
        Chart.defaults.plugins.tooltip.bodyColor = '#fff';
        Chart.defaults.plugins.tooltip.cornerRadius = 12;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.responsive = true;
        Chart.defaults.scale.grid.color = border;
        Chart.defaults.scale.ticks.color = muted;
        Chart.defaults.elements.arc.borderWidth = 0;
        Chart.defaults.elements.line.tension = 0.35;
        Chart.defaults.elements.line.borderWidth = 2;
        Chart.defaults.elements.point.radius = 0;
        Chart.defaults.datasets.bar.borderRadius = 10;
        Chart.defaults.datasets.bar.maxBarThickness = 28;
        Chart.defaults.plugins.title.color = text;
    }

    window.analyticsproChartTheme = {
        palette: palette,
        apply: applyDefaults,
        seriesColors: function (count) {
            var colors = palette();
            var list = [];
            for (var i = 0; i < count; i++) {
                list.push(colors[i % colors.length]);
            }
            return list;
        }
    };

    applyDefaults();
})();
