window.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('taxChart');
    if (!canvas || typeof Chart === 'undefined' || !Array.isArray(window.chartData)) {
        return;
    }

    const labels = window.chartData.map((row) => row.country);
    const taxRates = window.chartData.map((row) => row.corporate_tax_rate);
    const foundationScores = window.chartData.map((row) => row.friendly_score);
    const costIndex = window.chartData.map((row) => row.operating_cost_index);

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
