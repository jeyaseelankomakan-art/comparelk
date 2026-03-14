/**
 * compare.lk — Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {

    // Theme toggle is handled by inline script in footer.php

    // Scroll to Top
    const scrollBtn = document.createElement('button');
    scrollBtn.id = 'scrollToTop';
    scrollBtn.innerHTML = '<i class="bi bi-arrow-up"></i>';
    document.body.appendChild(scrollBtn);

    window.addEventListener('scroll', () => {
        scrollBtn.classList.toggle('show', window.scrollY > 400);
        // Sticky nav shadow
        const nav = document.getElementById('mainNav');
        if (nav) nav.style.boxShadow = window.scrollY > 10
            ? '0 4px 24px rgba(0,87,255,.12)' : '';
    });
    scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

    // Autocomplete Search
    const searchInput = document.getElementById('mainSearchInput');
    const suggestions = document.getElementById('searchSuggestions');

    if (searchInput && suggestions) {
        let debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 2) { suggestions.classList.remove('show'); return; }

            debounceTimer = setTimeout(async () => {
                try {
                    const base = (window.APP_BASE || '').replace(/\/$/, '');
                    const res = await fetch(`${base}/api/search-suggest.php?q=${encodeURIComponent(q)}`);
                    const data = await res.json();
                    if (data.length === 0) { suggestions.classList.remove('show'); return; }

                    suggestions.innerHTML = data.map(item => `
                        <div class="suggestion-item" onclick="location.href='${base}/product.php?id=${item.id}'">
                            <i class="bi bi-search text-muted"></i>
                            <div>
                                <div>${item.name}</div>
                                <small>${item.category_name}</small>
                            </div>
                        </div>
                    `).join('');
                    suggestions.classList.add('show');
                } catch (e) {
                    suggestions.classList.remove('show');
                }
            }, 280);
        });

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !suggestions.contains(e.target)) {
                suggestions.classList.remove('show');
            }
        });
    }

    // Price Chart (product detail page)
    const priceChartEl = document.getElementById('priceHistoryChart');
    if (priceChartEl && typeof chartData !== 'undefined') {
        const colors = ['#0057FF', '#FF5722', '#00C853', '#FFB300', '#9C27B0'];
        const datasets = Object.entries(chartData).map(([storeName, points], i) => ({
            label: storeName,
            data: points.map(p => ({ x: p.date, y: parseFloat(p.price) })),
            borderColor: colors[i % colors.length],
            backgroundColor: colors[i % colors.length] + '20',
            fill: false,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
        }));

        new Chart(priceChartEl, {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { family: 'Plus Jakarta Sans', size: 12 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: Rs ${Number(ctx.parsed.y).toLocaleString('en-LK', { minimumFractionDigits: 2 })}`,
                        }
                    }
                },
                scales: {
                    x: { type: 'time', time: { unit: 'day', displayFormats: { day: 'MMM d' } }, grid: { color: '#EEF1F8' } },
                    y: { ticks: { callback: v => 'Rs ' + Number(v).toLocaleString() }, grid: { color: '#EEF1F8' } }
                }
            }
        });
    }

    // Sort Products
    const sortSelect = document.getElementById('sortSelect');
    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            const url = new URL(window.location);
            url.searchParams.set('sort', this.value);
            window.location = url.toString();
        });
    }

    // Toast notifications
    const toastEl = document.getElementById('liveToast');
    if (toastEl) {
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
    }

    // Global Alert Auto-hide
    document.querySelectorAll('.alert-success, .alert-danger').forEach(alert => {
        // Only auto-hide if it's not a permanent warning/info card
        setTimeout(() => {
            alert.style.transition = 'opacity 0.6s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 600);
        }, 5000);
    });
});

// LKR Formatter
function formatLKR(amount) {
    return 'Rs ' + parseFloat(amount).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
