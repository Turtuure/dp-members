/**
 * Applications KPI strip — fetches /api/backstage/applications.php?op=stats and
 * populates KPI values + sparklines on the applications page.
 */
(function () {
  'use strict';

  if (!document.querySelector('.kpis-grid .kpi-card[data-kpi="pending"]')) return;

  var KPI_COLORS = {
    pending:            '#d97706',
    approved_30d:       '#16a34a',
    rejected_30d:       '#dc2626',
    avg_response_hours: '#64748b',
  };

  // Per-card series labels surfaced in the sparkline tooltip.
  var KPI_NAMES = {
    pending:            'Pending',
    approved_30d:       'Approved (30d)',
    rejected_30d:       'Rejected (30d)',
    avg_response_hours: 'Avg response (h)',
  };

  var KPI_SUFFIX = { avg_response_hours: 'h' };

  fetch('/api/backstage/applications.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('applications stats failed', e); });

  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });

    Object.keys(KPI_COLORS).forEach(function (id) {
      setKpi(id, data[id], KPI_SUFFIX[id] || '');
      initSpark(id, data[id] && data[id].sparkline);
    });
  }

  function setKpi(id, payload, suffix) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value) + suffix;
  }

  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id], KPI_NAMES[id]);
  }
})();
