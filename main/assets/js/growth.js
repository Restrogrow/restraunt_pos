/* ── Growth Module: Loyalty, Segmentation, Referrals, Reports ── */

function growthCur() {
  return window.globalCurrencySymbol || '₹';
}

function growthBuildLoyaltyPageUrl() {
  var rid = document.querySelector('meta[name="restaurant-id"]')?.content || window.restaurant_id || '';
  if (window.restaurantCustomDomain && window.restaurantEmbedEnabled) {
    var domain = window.restaurantCustomDomain.replace(/^https?:\/\//, '');
    return window.location.protocol + '//' + domain + '/loyalty';
  } else if (window.restaurantWebsiteSlug) {
    var siteRoot = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/'));
    return siteRoot + '/' + encodeURIComponent(window.restaurantWebsiteSlug) + '/loyalty';
  }
  var basePath = window.location.origin + window.location.pathname.substring(0, window.location.pathname.indexOf('/main/') + 6) + 'website/loyalty.php';
  return basePath + '?restaurant_id=' + encodeURIComponent(rid);
}

/** Renders the same stat-card component the Analytics page uses, so Growth
 *  pages match the rest of the admin instead of inventing new styling. */
function growthStatCard(icon, gradient, label, value, sub) {
  return `
    <div class="analytics-stat-card">
      <div class="stat-icon" style="background:${gradient};"><span class="material-symbols-rounded">${icon}</span></div>
      <div class="stat-info">
        <div class="stat-label">${label}</div>
        <div class="stat-value">${value}</div>
        ${sub ? `<div class="stat-sub">${sub}</div>` : ''}
      </div>
    </div>
  `;
}

/** A compact colored pill matching how status badges are styled elsewhere
 *  in the admin (e.g. order status) — the `badge`/`status-badge` classes
 *  carry no base CSS of their own, so the padding/radius must be inline. */
function growthPill(text, bg, fg) {
  return `<span style="display:inline-block;background:${bg};color:${fg || '#fff'};padding:4px 10px;border-radius:12px;font-weight:600;font-size:0.8rem;">${text}</span>`;
}

/* ── Loyalty Settings ── */
async function loadGrowthSettings() {
  try {
    const res = await fetch('../api/get_growth_overview.php');
    const data = await res.json();
    if (!data.success) { showSweetAlert(data.message || 'Failed to load settings', 'error'); return; }

    const s = data.settings || {};
    document.getElementById('gsLoyaltyEnabled').checked = !!Number(s.loyalty_enabled);
    document.getElementById('gsEarnAmountThreshold').value = s.earn_amount_threshold ?? 100;
    document.getElementById('gsEarnPointsPerAmount').value = s.earn_points_per_amount ?? 1;
    document.getElementById('gsRedeemValuePerPoint').value = s.redeem_value_per_point ?? 0.5;
    document.getElementById('gsMinRedeemPoints').value = s.min_redeem_points ?? 100;
    document.getElementById('gsReferralEnabled').checked = !!Number(s.referral_enabled);
    document.getElementById('gsReferrerRewardPoints').value = s.referrer_reward_points ?? 50;
    document.getElementById('gsReferredRewardPoints').value = s.referred_reward_points ?? 20;
    document.getElementById('gsLapsedDaysThreshold').value = s.lapsed_days_threshold ?? 30;
    document.getElementById('gsHighSpenderThreshold').value = s.high_spender_threshold ?? 5000;

    const overviewEl = document.getElementById('growthOverviewCards');
    if (overviewEl) {
      const t = data.totals || {};
      overviewEl.innerHTML =
        growthStatCard('toll', 'linear-gradient(135deg,#3b82f6,#1d4ed8)', 'Points Issued', t.points_issued ?? 0, 'All-time') +
        growthStatCard('redeem', 'linear-gradient(135deg,#10b981,#059669)', 'Points Redeemed', t.points_redeemed ?? 0, 'All-time') +
        growthStatCard('group', 'linear-gradient(135deg,#8b5cf6,#7c3aed)', 'Active Members', t.active_members ?? 0, 'Earned or redeemed at least once') +
        growthStatCard('group_add', 'linear-gradient(135deg,#f59e0b,#d97706)', 'Referral Bonus Points', t.referral_points_issued ?? 0, 'All-time');
    }

    renderTiersTable(data.tiers || []);

    const qrImg = document.getElementById('loyaltyQrImage');
    if (qrImg) {
      const loyaltyUrl = growthBuildLoyaltyPageUrl();
      qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(loyaltyUrl);
    }
  } catch (e) {
    console.error('loadGrowthSettings error', e);
    showSweetAlert('Failed to load growth settings', 'error');
  }
}

async function saveGrowthSettings() {
  const btn = document.getElementById('btnSaveGrowthSettings');
  const originalText = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

  const fd = new FormData();
  fd.append('action', 'save_settings');
  fd.append('loyalty_enabled', document.getElementById('gsLoyaltyEnabled').checked ? '1' : '0');
  fd.append('earn_amount_threshold', document.getElementById('gsEarnAmountThreshold').value);
  fd.append('earn_points_per_amount', document.getElementById('gsEarnPointsPerAmount').value);
  fd.append('redeem_value_per_point', document.getElementById('gsRedeemValuePerPoint').value);
  fd.append('min_redeem_points', document.getElementById('gsMinRedeemPoints').value);
  fd.append('referral_enabled', document.getElementById('gsReferralEnabled').checked ? '1' : '0');
  fd.append('referrer_reward_points', document.getElementById('gsReferrerRewardPoints').value);
  fd.append('referred_reward_points', document.getElementById('gsReferredRewardPoints').value);
  fd.append('lapsed_days_threshold', document.getElementById('gsLapsedDaysThreshold').value);
  fd.append('high_spender_threshold', document.getElementById('gsHighSpenderThreshold').value);

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    showSweetAlert(data.message || (data.success ? 'Saved' : 'Failed to save'), data.success ? 'success' : 'error');
  } catch (e) {
    showSweetAlert('Failed to save growth settings', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = originalText; }
  }
}

/* ── Verify Redemption ── */
async function lookupRedemption() {
  const input = document.getElementById('redemptionCodeInput');
  const code = input.value.trim().toUpperCase();
  const resultEl = document.getElementById('redemptionResult');
  if (!code) { showSweetAlert('Enter a redemption code', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'lookup_redemption');
  fd.append('code', code);

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) {
      resultEl.innerHTML = `<div style="color:#dc2626;font-size:0.9rem;">${escapeHtml(data.message || 'Not found')}</div>`;
      return;
    }

    const r = data.redemption;
    const cur = growthCur();
    const used = r.status === 'used';
    resultEl.innerHTML = `
      <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
          <div style="font-weight:600;">${escapeHtml(r.customer_name || '-')} <span style="color:#999;font-weight:400;">(${escapeHtml(r.phone || '-')})</span></div>
          <div style="font-size:0.9rem;color:#333;margin-top:4px;">${r.points} points &middot; <strong>${cur}${Number(r.discount_value).toFixed(2)}</strong> discount</div>
          <div style="margin-top:6px;">${used ? growthPill('Already Used', '#fee2e2', '#dc2626') : growthPill('Pending', '#fef3c7', '#92400e')}</div>
        </div>
        ${used ? '' : `<button class="btn btn-primary" onclick="markRedemptionUsed('${code}')">Mark as Used</button>`}
      </div>
    `;
  } catch (e) {
    resultEl.innerHTML = `<div style="color:#dc2626;font-size:0.9rem;">Failed to look up code</div>`;
  }
}

async function markRedemptionUsed(code) {
  const fd = new FormData();
  fd.append('action', 'mark_redemption_used');
  fd.append('code', code);

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    showSweetAlert(data.message || (data.success ? 'Marked as used' : 'Failed'), data.success ? 'success' : 'error');
    if (data.success) lookupRedemption();
  } catch (e) {
    showSweetAlert('Failed to mark redemption used', 'error');
  }
}

/* ── Loyalty Tiers ── */
function renderTiersTable(tiers) {
  const tbody = document.getElementById('tiersTbody');
  if (!tbody) return;
  if (!tiers.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">No tiers yet. Add one to reward your top spenders.</td></tr>';
    return;
  }
  const cur = growthCur();
  tbody.innerHTML = tiers.map(t => `
    <tr>
      <td>${escapeHtml(t.tier_name)}</td>
      <td>${cur}${Number(t.min_total_spent).toFixed(2)}</td>
      <td><span class="material-symbols-rounded" style="font-size:18px;vertical-align:middle;color:#d97706;">${escapeHtml(t.icon || 'star')}</span></td>
      <td>${t.sort_order ?? 0}</td>
      <td>
        <button class="btn" style="padding:6px 14px;font-size:0.8rem;" onclick='openTierModal(${JSON.stringify(t).replace(/'/g, "&#39;")})'>Edit</button>
        <button class="btn btn-danger" style="padding:6px 14px;font-size:0.8rem;" onclick="deleteTier(${t.id})">Delete</button>
      </td>
    </tr>
  `).join('');
}

function openTierModal(data) {
  const modal = document.getElementById('tierModal');
  if (!modal) return;
  document.getElementById('tierId').value = data && data.id ? data.id : '';
  document.getElementById('tierName').value = data ? (data.tier_name || '') : '';
  document.getElementById('tierMinSpent').value = data ? (data.min_total_spent || 0) : 0;
  document.getElementById('tierIcon').value = data ? (data.icon || 'star') : 'star';
  document.getElementById('tierSortOrder').value = data ? (data.sort_order || 0) : 0;
  document.getElementById('tierModalTitle').textContent = data && data.id ? 'Edit Tier' : 'New Tier';
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
}

async function saveTier() {
  const id = document.getElementById('tierId').value;
  const fd = new FormData();
  fd.append('action', id ? 'update_tier' : 'add_tier');
  if (id) fd.append('id', id);
  fd.append('tier_name', document.getElementById('tierName').value.trim());
  fd.append('min_total_spent', document.getElementById('tierMinSpent').value);
  fd.append('icon', document.getElementById('tierIcon').value.trim() || 'star');
  fd.append('sort_order', document.getElementById('tierSortOrder').value);

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      closeModal('tierModal');
      showSweetAlert(data.message || 'Tier saved', 'success');
      loadGrowthSettings();
    } else {
      showSweetAlert(data.message || 'Failed to save tier', 'error');
    }
  } catch (e) {
    showSweetAlert('Failed to save tier', 'error');
  }
}

async function deleteTier(id) {
  const confirmed = window.Swal
    ? (await Swal.fire({ title: 'Delete this tier?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#e74c3c' })).isConfirmed
    : confirm('Delete this tier?');
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'delete_tier');
  fd.append('id', id);

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    showSweetAlert(data.message || (data.success ? 'Tier deleted' : 'Failed to delete tier'), data.success ? 'success' : 'error');
    if (data.success) loadGrowthSettings();
  } catch (e) {
    showSweetAlert('Failed to delete tier', 'error');
  }
}

/* ── Customer Segments ── */
let growthSegmentsCache = [];
let growthActiveSegmentFilter = '';

const GROWTH_SEGMENT_LABELS = { new: 'New', repeat: 'Repeat', high_spender: 'High Spender', lapsed: 'Lapsed' };
const GROWTH_SEGMENT_ICONS = { new: 'person_add', repeat: 'autorenew', high_spender: 'paid', lapsed: 'schedule' };
const GROWTH_SEGMENT_GRADIENTS = {
  new: 'linear-gradient(135deg,#3b82f6,#1d4ed8)',
  repeat: 'linear-gradient(135deg,#10b981,#059669)',
  high_spender: 'linear-gradient(135deg,#f59e0b,#d97706)',
  lapsed: 'linear-gradient(135deg,#ef4444,#b91c1c)',
};
const GROWTH_SEGMENT_PILL_COLORS = { new: '#dbeafe,#1e40af', repeat: '#d1fae5,#065f46', high_spender: '#fef3c7,#92400e', lapsed: '#fee2e2,#dc2626' };

function growthSegmentPill(segment) {
  const [bg, fg] = (GROWTH_SEGMENT_PILL_COLORS[segment] || '#e5e7eb,#374151').split(',');
  return growthPill(GROWTH_SEGMENT_LABELS[segment] || segment, bg, fg);
}

async function loadCustomerSegments(segment) {
  growthActiveSegmentFilter = segment || '';
  try {
    const url = '../api/get_customer_segments.php' + (segment ? '?segment=' + encodeURIComponent(segment) : '');
    const res = await fetch(url);
    const data = await res.json();
    if (!data.success) { showSweetAlert(data.message || 'Failed to load segments', 'error'); return; }

    growthSegmentsCache = data.customers || [];
    renderSegmentSummaryCards(data.counts || {}, data.revenue || {});
    renderSegmentFilterTabs();
    renderSegmentsTable(growthSegmentsCache);
  } catch (e) {
    console.error('loadCustomerSegments error', e);
    showSweetAlert('Failed to load customer segments', 'error');
  }
}

function renderSegmentSummaryCards(counts, revenue) {
  const el = document.getElementById('segmentSummaryCards');
  if (!el) return;
  const cur = growthCur();
  el.innerHTML = Object.keys(GROWTH_SEGMENT_LABELS).map(seg =>
    growthStatCard(
      GROWTH_SEGMENT_ICONS[seg],
      GROWTH_SEGMENT_GRADIENTS[seg],
      GROWTH_SEGMENT_LABELS[seg],
      counts[seg] ?? 0,
      cur + Number(revenue[seg] ?? 0).toFixed(2) + ' revenue'
    )
  ).join('');
}

function renderSegmentFilterTabs() {
  const el = document.getElementById('segmentFilterTabs');
  if (!el) return;
  const tabs = [{ key: '', label: 'All' }].concat(Object.keys(GROWTH_SEGMENT_LABELS).map(k => ({ key: k, label: GROWTH_SEGMENT_LABELS[k] })));
  el.innerHTML = tabs.map(t => `
    <button class="btn ${growthActiveSegmentFilter === t.key ? 'btn-primary' : ''}" style="padding:6px 14px;font-size:0.8rem;" onclick="loadCustomerSegments('${t.key}')">${t.label}</button>
  `).join('');
}

function renderSegmentsTable(customers) {
  const tbody = document.getElementById('segmentsTbody');
  if (!tbody) return;
  if (!customers.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">No customers in this segment.</td></tr>';
    return;
  }
  const cur = growthCur();
  tbody.innerHTML = customers.map(c => `
    <tr>
      <td>${escapeHtml(c.customer_name || '-')}</td>
      <td>${escapeHtml(c.phone || '-')}</td>
      <td>${growthSegmentPill(c.segment)}</td>
      <td>${c.total_visits ?? 0}</td>
      <td>${cur}${Number(c.total_spent || 0).toFixed(2)}</td>
      <td>${c.last_visit_date && c.last_visit_date !== '0000-00-00' ? c.last_visit_date : '-'}</td>
      <td>${c.loyalty_points_balance ?? 0}</td>
      <td><button class="btn" style="padding:6px 14px;font-size:0.8rem;" onclick="openAdjustPointsModal(${c.id}, '${escapeHtml(c.customer_name || '').replace(/'/g, "\\'")}')">Adjust Points</button></td>
    </tr>
  `).join('');
}

function openAdjustPointsModal(customerId, customerName) {
  const modal = document.getElementById('adjustPointsModal');
  if (!modal) return;
  document.getElementById('adjustCustomerId').value = customerId;
  document.getElementById('adjustCustomerName').textContent = customerName || '';
  document.getElementById('adjustPointsDelta').value = '';
  document.getElementById('adjustPointsNote').value = '';
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
}

async function submitAdjustPoints() {
  const fd = new FormData();
  fd.append('action', 'adjust_points');
  fd.append('customer_id', document.getElementById('adjustCustomerId').value);
  fd.append('points_delta', document.getElementById('adjustPointsDelta').value);
  fd.append('note', document.getElementById('adjustPointsNote').value.trim() || 'Manual adjustment');

  try {
    const res = await fetch('../controllers/growth_operations.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
      closeModal('adjustPointsModal');
      showSweetAlert(data.message || 'Points adjusted', 'success');
      loadCustomerSegments(growthActiveSegmentFilter);
    } else {
      showSweetAlert(data.message || 'Failed to adjust points', 'error');
    }
  } catch (e) {
    showSweetAlert('Failed to adjust points', 'error');
  }
}

/* ── Referrals ── */
async function loadReferrals() {
  try {
    const res = await fetch('../api/get_referrals.php');
    const data = await res.json();
    if (!data.success) { showSweetAlert(data.message || 'Failed to load referrals', 'error'); return; }

    const summary = data.summary || {};
    const summaryEl = document.getElementById('referralSummaryCards');
    if (summaryEl) {
      summaryEl.innerHTML =
        growthStatCard('group_add', 'linear-gradient(135deg,#3b82f6,#1d4ed8)', 'Total Referrals', summary.total ?? 0, null) +
        growthStatCard('check_circle', 'linear-gradient(135deg,#10b981,#059669)', 'Completed', summary.completed ?? 0, null) +
        growthStatCard('percent', 'linear-gradient(135deg,#8b5cf6,#7c3aed)', 'Conversion Rate', (summary.conversion_rate ?? 0) + '%', null);
    }

    const leaderboardTbody = document.getElementById('referralLeaderboardTbody');
    const leaderboard = data.leaderboard || [];
    if (leaderboardTbody) {
      leaderboardTbody.innerHTML = leaderboard.length
        ? leaderboard.map(r => `
          <tr>
            <td>${escapeHtml(r.customer_name || '-')}</td>
            <td>${escapeHtml(r.phone || '-')}</td>
            <td>${r.total_referrals ?? 0}</td>
            <td>${r.completed_referrals ?? 0}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">No referrals yet.</td></tr>';
    }

    const referralsTbody = document.getElementById('referralsTbody');
    const referrals = data.referrals || [];
    if (referralsTbody) {
      referralsTbody.innerHTML = referrals.length
        ? referrals.map(r => `
          <tr>
            <td>${escapeHtml(r.referrer_name || '-')} (${escapeHtml(r.referrer_phone || '-')})</td>
            <td>${escapeHtml(r.referred_name || '-')} (${escapeHtml(r.referred_phone || '-')})</td>
            <td>${escapeHtml(r.referral_code)}</td>
            <td>${r.status === 'completed' ? growthPill('Completed', '#d1fae5', '#065f46') : growthPill('Pending', '#fef3c7', '#92400e')}</td>
            <td>${r.created_at ? new Date(r.created_at).toLocaleDateString() : '-'}</td>
          </tr>
        `).join('')
        : '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">No referrals yet.</td></tr>';
    }
  } catch (e) {
    console.error('loadReferrals error', e);
    showSweetAlert('Failed to load referrals', 'error');
  }
}

/* ── Reports ── */
async function loadGrowthReports() {
  try {
    const res = await fetch('../api/get_growth_reports.php');
    const data = await res.json();
    if (!data.success) { showSweetAlert(data.message || 'Failed to load reports', 'error'); return; }

    const cur = growthCur();
    const loyalty = data.loyalty || {};
    const loyaltyEl = document.getElementById('loyaltyRoiCards');
    if (loyaltyEl) {
      loyaltyEl.innerHTML =
        growthStatCard('loyalty', 'linear-gradient(135deg,#10b981,#059669)', 'Revenue from Members', cur + Number(loyalty.member_revenue ?? 0).toFixed(2), (loyalty.member_count ?? 0) + ' members') +
        growthStatCard('person', 'linear-gradient(135deg,#3b82f6,#1d4ed8)', 'Revenue from Non-Members', cur + Number(loyalty.non_member_revenue ?? 0).toFixed(2), (loyalty.non_member_count ?? 0) + ' customers') +
        growthStatCard('redeem', 'linear-gradient(135deg,#f59e0b,#d97706)', 'Redemption Cost', cur + Number(loyalty.redemption_cost ?? 0).toFixed(2), 'Value of points redeemed');
    }

    const referrals = data.referrals || {};
    const referralEl = document.getElementById('referralRevenueCards');
    if (referralEl) {
      referralEl.innerHTML =
        growthStatCard('payments', 'linear-gradient(135deg,#10b981,#059669)', 'Revenue from Referred Customers', cur + Number(referrals.revenue ?? 0).toFixed(2), null) +
        growthStatCard('receipt_long', 'linear-gradient(135deg,#8b5cf6,#7c3aed)', 'Orders from Referred Customers', referrals.order_count ?? 0, null);
    }

    const segments = data.segments || { counts: {}, revenue: {} };
    const segTbody = document.getElementById('segmentRevenueTbody');
    if (segTbody) {
      segTbody.innerHTML = Object.keys(GROWTH_SEGMENT_LABELS).map(seg => `
        <tr>
          <td>${growthSegmentPill(seg)}</td>
          <td>${segments.counts[seg] ?? 0}</td>
          <td>${cur}${Number(segments.revenue[seg] ?? 0).toFixed(2)}</td>
        </tr>
      `).join('');
    }
  } catch (e) {
    console.error('loadGrowthReports error', e);
    showSweetAlert('Failed to load growth reports', 'error');
  }
}
