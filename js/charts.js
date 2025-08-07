document.addEventListener("DOMContentLoaded", function () {
    const equityData = window.EQUITY_SERIES;
    const chartPoints = equityData.map(([t, pnl]) => ({ x: t, y: pnl }));
    const equityctx = document.getElementById("equityChart").getContext("2d");
    new Chart(equityctx, {
        type: "line",
        data: {
            datasets: [{
                label: "Realized P&L",
                data: chartPoints,
                fill: false,
                borderWidth: 2,
            }]
        },
        options: {
            scales: {
                x: {
                    type: "time",
                    time: {
                        unit: "hour",
                        tooltipFormat: "MMM d, yyyy HH:mm"
                    },
                    adapters: {
                        date: {
                            zone: 'America/New_York'
                        }
                    },
                    title: { display: true, text: 'Time (US Eastern)' }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: "Cum. P&L ($)" }
                }
            },
            plugins: {
                title: { display: true, text: "Intraday Equity Run" },
                legend: { display: false },
                tooltip: { mode: "index", intersect: false }
            },
            elements: {
                point: { radius: 0 }  // hide the dots for a smoother line
            },
            responsive: true,
            maintainAspectRatio: false
        }
    });


    const { labels: ranges, counts } = window.RETURN_HISTOGRAM;

    // generate letters A, B, … up to the number of ranges
    const letters = ranges.map((_, i) => String.fromCharCode(65 + i));

    const histoctx = document.getElementById("returnHistogram").getContext("2d");
    new Chart(histoctx, {
        type: "bar",
        data: {
            labels: letters,     // use letters here
            datasets: [{
                label: "Trades by Return (%)",
                data: counts,
                backgroundColor: "rgba(54, 162, 235, 0.5)",
                borderColor: "rgba(54, 162, 235, 1)",
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                x: { title: { display: true, text: "Class" } },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: "Frequency Distribution" }
                }
            },
            plugins: {
                title: { display: true, text: "Returns by Class" },
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        // tooltip shows letter + count + range
                        label: context => {
                            const letter = context.label;
                            const count = context.parsed.y;
                            const range = ranges[context.dataIndex];
                            return `${letter}: ${count} trades (${range})`;
                        }
                    }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        }
    });
});
