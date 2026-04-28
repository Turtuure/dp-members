/**
 * Members KPI strip — fetches /api/backstage/members.php?op=stats and populates
 * KPI values + sparklines on the members page.
 */
(function () {
  'use strict';

  if (!document.querySelector('.kpis-grid .kpi-card[data-kpi="total_members"]')) return;

  var KPI_COLORS = {
    total_members: '#3b82f6',
    new_members:   '#16a34a',
    supporters:    '#a855f7',
    inactive:      '#d97706',
  };

  // Per-card series labels surfaced in the sparkline tooltip.
  var KPI_NAMES = {
    total_members: 'Total members',
    new_members:   'New members',
    supporters:    'Supporters',
    inactive:      'Inactive',
  };

  fetch('/api/backstage/members.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('members stats failed', e); });

  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });

    Object.keys(KPI_COLORS).forEach(function (id) {
      setKpi(id, data[id]);
      initSpark(id, data[id] && data[id].sparkline);
    });
  }

  function setKpi(id, payload) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value);
  }

  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id], KPI_NAMES[id]);
  }
})();
