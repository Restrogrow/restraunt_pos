/* ── Growth Module: Loyalty, Segmentation, Referrals, Reports ── */

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

/* ── Loyalty Tiers ── */
function renderTiersTable(tiers) {
  const tbody = document.getElementById('tiersTbody');
  if (!tbody) return;
  if (!tiers.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">No tiers yet. Add one to reward your top spenders.</td></tr>';
    return;
  }
  tbody.innerHTML = tiers.map(t => `
    <tr>
      <td>${escapeHtml(t.tier_name)}</td>
      <td>${Number(t.min_total_spent).toFixed(2)}</td>
      <td>${escapeHtml(t.icon || 'star')}</td>
      <td>${t.sort_order ?? 0}</td>
      <td>
        <button class="btn btn-sm" onclick='openTierModal(${JSON.stringify(t).replace(/'/g, "&#39;")})'>Edit</button>
        <button class="btn btn-sm btn-danger" onclick="deleteTier(${t.id})">Delete</button>
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
const GROWTH_SEGMENT_COLORS = { new: '#1565c0', repeat: '#2e7d32', high_spender: '#c62828', lapsed: '#f57f17' };

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
  el.innerHTML = Object.keys(GROWTH_SEGMENT_LABELS).map(seg => `
    <div style="flex:1;min-width:140px;padding:14px;border-radius:10px;background:#f9f9f9;border-left:4px solid ${GROWTH_SEGMENT_COLORS[seg]};">
      <div style="font-size:22px;font-weight:700;">${counts[seg] ?? 0}</div>
      <div style="font-size:12px;color:#666;">${GROWTH_SEGMENT_LABELS[seg]}</div>
      <div style="font-size:11px;color:#999;margin-top:4px;">${(revenue[seg] ?? 0).toFixed(2)} revenue</div>
    </div>
  `).join('');
}

function renderSegmentFilterTabs() {
  const el = document.getElementById('segmentFilterTabs');
  if (!el) return;
  const tabs = [{ key: '', label: 'All' }].concat(Object.keys(GROWTH_SEGMENT_LABELS).map(k => ({ key: k, label: GROWTH_SEGMENT_LABELS[k] })));
  el.innerHTML = tabs.map(t => `
    <button class="btn btn-sm ${growthActiveSegmentFilter === t.key ? 'btn-primary' : ''}" onclick="loadCustomerSegments('${t.key}')">${t.label}</button>
  `).join('');
}

function renderSegmentsTable(customers) {
  const tbody = document.getElementById('segmentsTbody');
  if (!tbody) return;
  if (!customers.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">No customers in this segment.</td></tr>';
    return;
  }
  tbody.innerHTML = customers.map(c => `
    <tr>
      <td>${escapeHtml(c.customer_name || '-')}</td>
      <td>${escapeHtml(c.phone || '-')}</td>
      <td><span class="badge" style="background:${GROWTH_SEGMENT_COLORS[c.segment]};color:#fff;">${GROWTH_SEGMENT_LABELS[c.segment] || c.segment}</span></td>
      <td>${c.total_visits ?? 0}</td>
      <td>${Number(c.total_spent || 0).toFixed(2)}</td>
      <td>${c.last_visit_date || '-'}</td>
      <td>${c.loyalty_points_balance ?? 0}</td>
      <td><button class="btn btn-sm" onclick="openAdjustPointsModal(${c.id}, '${escapeHtml(c.customer_name || '').replace(/'/g, "\\'")}')">Adjust Points</button></td>
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
      summaryEl.innerHTML = `
        <div style="flex:1;min-width:140px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:22px;font-weight:700;">${summary.total ?? 0}</div>
          <div style="font-size:12px;color:#666;">Total Referrals</div>
        </div>
        <div style="flex:1;min-width:140px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:22px;font-weight:700;">${summary.completed ?? 0}</div>
          <div style="font-size:12px;color:#666;">Completed</div>
        </div>
        <div style="flex:1;min-width:140px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:22px;font-weight:700;">${summary.conversion_rate ?? 0}%</div>
          <div style="font-size:12px;color:#666;">Conversion Rate</div>
        </div>
      `;
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
            <td><span class="badge" style="background:${r.status === 'completed' ? '#2e7d32' : '#f57f17'};color:#fff;">${r.status}</span></td>
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

    const loyalty = data.loyalty || {};
    const loyaltyEl = document.getElementById('loyaltyRoiCards');
    if (loyaltyEl) {
      loyaltyEl.innerHTML = `
        <div style="flex:1;min-width:160px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:20px;font-weight:700;">${(loyalty.member_revenue ?? 0).toFixed(2)}</div>
          <div style="font-size:12px;color:#666;">Revenue from Loyalty Members (${loyalty.member_count ?? 0})</div>
        </div>
        <div style="flex:1;min-width:160px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:20px;font-weight:700;">${(loyalty.non_member_revenue ?? 0).toFixed(2)}</div>
          <div style="font-size:12px;color:#666;">Revenue from Non-Members (${loyalty.non_member_count ?? 0})</div>
        </div>
        <div style="flex:1;min-width:160px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:20px;font-weight:700;">${(loyalty.redemption_cost ?? 0).toFixed(2)}</div>
          <div style="font-size:12px;color:#666;">Redemption Cost</div>
        </div>
      `;
    }

    const referrals = data.referrals || {};
    const referralEl = document.getElementById('referralRevenueCards');
    if (referralEl) {
      referralEl.innerHTML = `
        <div style="flex:1;min-width:160px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:20px;font-weight:700;">${(referrals.revenue ?? 0).toFixed(2)}</div>
          <div style="font-size:12px;color:#666;">Revenue from Referred Customers</div>
        </div>
        <div style="flex:1;min-width:160px;padding:14px;border-radius:10px;background:#f9f9f9;">
          <div style="font-size:20px;font-weight:700;">${referrals.order_count ?? 0}</div>
          <div style="font-size:12px;color:#666;">Orders from Referred Customers</div>
        </div>
      `;
    }

    const segments = data.segments || { counts: {}, revenue: {} };
    const segTbody = document.getElementById('segmentRevenueTbody');
    if (segTbody) {
      segTbody.innerHTML = Object.keys(GROWTH_SEGMENT_LABELS).map(seg => `
        <tr>
          <td>${GROWTH_SEGMENT_LABELS[seg]}</td>
          <td>${segments.counts[seg] ?? 0}</td>
          <td>${(segments.revenue[seg] ?? 0).toFixed(2)}</td>
        </tr>
      `).join('');
    }
  } catch (e) {
    console.error('loadGrowthReports error', e);
    showSweetAlert('Failed to load growth reports', 'error');
  }
}
