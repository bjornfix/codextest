window.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('taxChart');
    const wrapper = canvas ? canvas.closest('.chart-wrapper') : null;

    if (!canvas || !wrapper || !Array.isArray(window.chartData)) {
        return;
    }

    const dataset = window.chartData.slice(0, 12);

    if (dataset.length === 0) {
        canvas.remove();
        const message = document.createElement('p');
        message.className = 'chart-empty-message';
        message.textContent = 'Overview data is not available right now.';
        wrapper.appendChild(message);
        wrapper.classList.add('chart-fallback');
        return;
    }

    if (typeof Chart === 'undefined') {
        canvas.remove();
        wrapper.classList.add('chart-fallback');

        const fallback = document.createElement('div');
        fallback.className = 'overview-fallback';

        const maxTax = Math.max(...dataset.map((row) => row.corporate_tax_rate));
        const maxCost = Math.max(...dataset.map((row) => row.operating_cost_index));

        const makeMetric = (label, value, max, modifier, formatter) => {
            const metric = document.createElement('div');
            metric.className = 'fallback-metric';

            const metricLabel = document.createElement('span');
            metricLabel.className = 'fallback-label';
            metricLabel.textContent = label;
            metric.appendChild(metricLabel);

            const bar = document.createElement('span');
            bar.className = 'fallback-bar';
            const fill = document.createElement('span');
            fill.className = `fallback-bar-fill ${modifier}`;

            const numericValue = Number(value);
            const safeValue = Number.isFinite(numericValue) ? numericValue : 0;
            const safeMax = max <= 0 ? 1 : max;
            const ratio = Math.round((safeValue / safeMax) * 100);
            const clampedRatio = Math.max(0, Math.min(100, ratio));
            const width = safeValue <= 0 ? 0 : Math.max(6, clampedRatio);
            fill.style.width = `${width}%`;
            bar.appendChild(fill);
            metric.appendChild(bar);

            const metricValue = document.createElement('span');
            metricValue.className = 'fallback-value';
            metricValue.textContent = formatter(value);
            metric.appendChild(metricValue);

            return metric;
        };

        dataset.forEach((row) => {
            const card = document.createElement('article');
            card.className = 'fallback-card';

            const heading = document.createElement('h3');
            heading.textContent = row.country;
            card.appendChild(heading);

            card.appendChild(makeMetric(
                'Corporate tax',
                row.corporate_tax_rate,
                maxTax,
                'fallback-tax',
                (value) => `${Number(value).toFixed(1)}%`,
            ));

            card.appendChild(makeMetric(
                'Operating cost',
                row.operating_cost_index,
                maxCost,
                'fallback-cost',
                (value) => `${Math.round(Number(value))}`,
            ));

            card.appendChild(makeMetric(
                'Foundation score',
                row.friendly_score,
                5,
                'fallback-foundation',
                (value) => `${Math.round(Number(value))} / 5`,
            ));

            fallback.appendChild(card);
        });

        wrapper.appendChild(fallback);
        return;
    }

    const labels = dataset.map((row) => row.country);
    const taxRates = dataset.map((row) => row.corporate_tax_rate);
    const foundationScores = dataset.map((row) => row.friendly_score);
    const costIndex = dataset.map((row) => row.operating_cost_index);

    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#38bdf8';
    const accentStrong = getComputedStyle(document.documentElement).getPropertyValue('--accent-strong') || '#0ea5e9';

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Corporate tax (%)',
                    data: taxRates,
                    backgroundColor: accent,
                    borderRadius: 12,
                    maxBarThickness: 32,
                    order: 2,
                },
                {
                    type: 'line',
                    label: 'Foundation friendliness (0-5)',
                    data: foundationScores,
                    borderColor: accentStrong,
                    borderWidth: 3,
                    tension: 0.35,
                    fill: false,
                    yAxisID: 'y1',
                    order: 1,
                    pointBackgroundColor: accentStrong,
                    pointRadius: 5,
                },
                {
                    type: 'bar',
                    label: 'Operating cost index',
                    data: costIndex,
                    backgroundColor: 'rgba(15, 23, 42, 0.18)',
                    borderRadius: 12,
                    maxBarThickness: 32,
                    order: 3,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    ticks: {
                        color: '#475569',
                        maxRotation: 60,
                        minRotation: 45,
                    },
                    grid: {
                        display: false,
                    },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#334155',
                        callback: (value) => `${value}%`,
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.15)',
                    },
                    title: {
                        display: true,
                        text: 'Corporate tax / Operating cost index',
                        color: '#0f172a',
                        font: {
                            family: 'Inter',
                            weight: '600',
                        },
                    },
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    suggestedMax: 5,
                    ticks: {
                        color: '#0ea5e9',
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    title: {
                        display: true,
                        text: 'Foundation friendliness',
                        color: '#0ea5e9',
                        font: {
                            family: 'Inter',
                            weight: '600',
                        },
                    },
                },
            },
            plugins: {
                legend: {
                    labels: {
                        color: '#334155',
                        usePointStyle: true,
                    },
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            if (context.datasetIndex === 0) {
                                return `${context.dataset.label}: ${context.parsed.y.toFixed(1)}%`;
                            }
                            if (context.datasetIndex === 1) {
                                return `${context.dataset.label}: ${context.parsed.y.toFixed(1)} / 5`;
                            }
                            return `${context.dataset.label}: ${context.parsed.y}`;
                        },
                    },
                },
            },
        },
    });

    return chart;
});
