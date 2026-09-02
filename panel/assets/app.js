/**
 * Lottery Control Panel — vanilla JS SPA (no build step, no dependencies).
 * Talks to /api/Admin?action=... with a Bearer session token.
 */
(() => {
'use strict';

const API = '/api/Admin';
const store = {
  token: localStorage.getItem('lot_admin_token') || '',
  view: 'dashboard',
  games: [],
  timer: null,
  clockTimer: null,
};

/* ------------------------------------------------------------ helpers */
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));
const money = (v) => '₹' + Number(v ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const signed = (v) => {
  const n = Number(v ?? 0);
  return `<span class="${n >= 0 ? 'pos' : 'neg'}">${n >= 0 ? '+' : '−'}${money(Math.abs(n)).slice(1)}</span>`;
};
const clamp = (s, n = 42) => (String(s ?? '').length > n ? String(s).slice(0, n) + '…' : String(s ?? ''));

function toast(message, kind = 'ok') {
  const el = $('#toast');
  el.className = 'toast ' + kind;
  el.textContent = message;
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.add('hidden'), 4200);
}

async function api(action, params = {}, method = 'GET') {
  const opts = { method, headers: {} };
  let url = `${API}?action=${encodeURIComponent(action)}`;

  if (store.token) opts.headers['Authorization'] = 'Bearer ' + store.token;

  if (method === 'POST') {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(params);
  } else {
    const qs = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined)
    ).toString();
    if (qs) url += '&' + qs;
  }

  const res = await fetch(url, opts);
  let body;
  try { body = await res.json(); } catch (e) { throw new Error('Bad response from server (HTTP ' + res.status + ')'); }

  if (body.code !== 0) {
    if (body.msgCode === 'AUTH_REQUIRED' && action !== 'login') logout(true);
    const err = new Error(body.msg || 'Request failed');
    err.msgCode = body.msgCode;
    throw err;
  }
  return body.data;
}

/* -------------------------------------------------------------- login */
function showLogin(message) {
  $('#app').classList.add('hidden');
  $('#login').classList.remove('hidden');
  if (message) $('#login-error').textContent = message;
  stopTimers();
  api('ping').then((d) => { $('#login-version').textContent = d.version; }).catch(() => {});
}

function logout(expired) {
  store.token = '';
  localStorage.removeItem('lot_admin_token');
  showLogin(expired ? 'Session expired, please sign in again.' : '');
}

$('#login-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#login-error').textContent = '';
  try {
    const data = await api('login', {
      user: $('#login-user').value.trim(),
      password: $('#login-pass').value,
    }, 'POST');
    store.token = data.token;
    localStorage.setItem('lot_admin_token', data.token);
    $('#login-pass').value = '';
    boot();
  } catch (e) {
    $('#login-error').textContent = e.message;
  }
});

/* ------------------------------------------------------------ chrome */
$('#btn-logout').addEventListener('click', () => logout(false));
$('#btn-refresh').addEventListener('click', () => render(true));
$('#btn-worker').addEventListener('click', async (event) => {
  event.target.disabled = true;
  try {
    const r = await api('runworkerpass', {}, 'POST');
    toast(`Worker pass done — ${r.settledBets} bets settled, ${r.followBets} copy bets placed`);
    render(true);
  } catch (e) { toast(e.message, 'err'); }
  event.target.disabled = false;
});

$$('#nav button').forEach((button) => button.addEventListener('click', () => {
  $$('#nav button').forEach((b) => b.classList.toggle('active', b === button));
  store.view = button.dataset.view;
  $('#view-title').textContent = button.textContent.trim();
  render(true);
}));

$('#modal-close').addEventListener('click', () => $('#modal').classList.add('hidden'));
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') $('#modal').classList.add('hidden'); });

function modal(title, html) {
  $('#modal-title').textContent = title;
  $('#modal-body').innerHTML = html;
  $('#modal').classList.remove('hidden');
}

function stopTimers() {
  if (store.timer) clearInterval(store.timer);
  if (store.clockTimer) clearInterval(store.clockTimer);
  store.timer = store.clockTimer = null;
}

/* ------------------------------------------------------- render layer */
const views = {};

async function render(showLoader) {
  if (showLoader) $('#view').innerHTML = '<div class="loading">Loading…</div>';
  try {
    await views[store.view]();
  } catch (e) {
    $('#view').innerHTML = `<div class="card"><div class="empty">${esc(e.message)}</div></div>`;
  }
}

function table(columns, rows, emptyText = 'Nothing here yet') {
  if (!rows.length) return `<div class="empty">${esc(emptyText)}</div>`;
  return `<div class="table-wrap"><table>
    <thead><tr>${columns.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((row) => `<tr>${columns.map((c) => `<td class="${c.num ? 'num' : ''}">${c.render(row)}</td>`).join('')}</tr>`).join('')}</tbody>
  </table></div>`;
}

function pager(data, onPage) {
  const id = 'pg' + Math.random().toString(36).slice(2, 8);
  setTimeout(() => {
    const prev = $('#' + id + '-prev');
    const next = $('#' + id + '-next');
    if (prev) prev.onclick = () => onPage(data.pageNo - 1);
    if (next) next.onclick = () => onPage(data.pageNo + 1);
  });
  return `<div class="pager">
    <span class="muted small">${data.totalCount} rows · page ${data.pageNo}/${Math.max(1, data.totalPage)}</span>
    <button class="btn small" id="${id}-prev" ${data.pageNo <= 1 ? 'disabled' : ''}>‹ Prev</button>
    <button class="btn small" id="${id}-next" ${data.pageNo >= data.totalPage ? 'disabled' : ''}>Next ›</button>
  </div>`;
}

function statusPill(status) {
  const map = { won: 'ok', lost: 'bad', pending: 'warn', active: 'ok', stopped: '', completed: '' };
  return `<span class="pill ${map[status] ?? ''}">${esc(status)}</span>`;
}

/** Coloured result chip for any family. */
function resultChip(row) {
  if (row.family === 'WinGo' || row.family === 'TrxWinGo') {
    const colors = row.colors || [];
    let cls = 'plain';
    if (colors.includes('violet') && colors.includes('red')) cls = 'mix-red';
    else if (colors.includes('violet') && colors.includes('green')) cls = 'mix-green';
    else if (colors.includes('red')) cls = 'red';
    else if (colors.includes('green')) cls = 'green';
    return `<span class="balls"><span class="ball ${cls}">${row.number}</span></span>
            <span class="muted small"> ${esc(row.size || '')}/${esc(row.parity || '')}</span>`;
  }
  if (row.family === 'K3') {
    return `<span class="balls">${(row.dice || []).map((d) => `<span class="ball plain">${d}</span>`).join('')}</span>
            <span class="muted small"> sum ${row.sum} · ${esc(row.size || '')}</span>`;
  }
  if (row.family === 'D5') {
    return `<span class="balls">${String(row.code || '').split('').map((d) => `<span class="ball plain">${d}</span>`).join('')}</span>
            <span class="muted small"> sum ${row.sum}</span>`;
  }
  if (row.family === 'MotoRace') {
    return `<span class="balls">${(row.podium || []).map((d, i) => `<span class="ball ${i === 0 ? 'green' : 'plain'}">${d}</span>`).join('')}</span>
            <span class="muted small"> champion ${row.champion}</span>`;
  }
  return '<span class="muted">—</span>';
}

function gameOptions(selected, allowAll) {
  const opts = store.games.map((g) => `<option value="${esc(g.gameCode)}" ${g.gameCode === selected ? 'selected' : ''}>${esc(g.gameCode)}</option>`).join('');
  return (allowAll ? '<option value="">All games</option>' : '') + opts;
}

/* ---------------------------------------------------------- dashboard */
views.dashboard = async () => {
  const d = await api('dashboard', { days: 7 });
  const maxStake = Math.max(1, ...d.series.map((s) => Number(s.stake)));

  $('#view').innerHTML = `
    <div class="grid k4">
      ${statCard('Stake today', money(d.today.stake), `${d.today.bets} bets · ${d.today.players} players`)}
      ${statCard('Payout today', money(d.today.payout), `tax ${money(d.today.tax)}`)}
      ${statCard('GGR today', money(d.today.ggr), `margin ${d.today.margin}%`)}
      ${statCard('Player balances', money(d.users.balance), `${d.users.total} users · ${d.users.blocked} blocked`)}
    </div>

    <div class="grid k2">
      <div class="card">
        <h3>Last 7 days</h3>
        ${d.series.length ? d.series.map((s) => `
          <div style="margin-bottom:9px">
            <div class="small" style="display:flex;justify-content:space-between">
              <span class="muted">${esc(s.day)}</span>
              <span>${money(s.stake)} <span class="muted">/</span> ${signed(s.ggr)}</span>
            </div>
            <div class="bar"><span style="width:${Math.round((Number(s.stake) / maxStake) * 100)}%"></span></div>
          </div>`).join('') : '<div class="empty">No bets yet</div>'}
      </div>

      <div class="card">
        <h3>Today by game</h3>
        ${table([
          { label: 'Game', render: (r) => esc(r.gameCode) },
          { label: 'Bets', num: true, render: (r) => r.bets },
          { label: 'Stake', num: true, render: (r) => money(r.stake) },
          { label: 'GGR', num: true, render: (r) => signed(r.ggr) },
        ], d.perGame, 'No bets today')}
      </div>
    </div>

    <div class="grid k2">
      <div class="card">
        <div class="card-head"><h3>Latest results</h3><span class="muted small">${d.recentResults.length} rounds</span></div>
        ${table([
          { label: 'Game', render: (r) => esc(r.gameCode) },
          { label: 'Issue', render: (r) => `<span class="muted small">${esc(r.issueNumber)}</span>` },
          { label: 'Result', render: (r) => resultChip(r) },
          { label: 'Source', render: (r) => `<span class="pill ${r.source === 'override' ? 'warn' : ''}">${esc(r.source)}</span>` },
        ], d.recentResults, 'No draws yet')}
      </div>

      <div class="card">
        <h3>Operations</h3>
        <div class="grid k2" style="gap:10px">
          ${miniStat('Pending bets', d.pendingBets)}
          ${miniStat('Active follows', d.activeFollows)}
          ${miniStat('Queued overrides', d.openOverrides)}
          ${miniStat('Schema', d.system.schemaVersion + '/' + d.system.latestSchema)}
        </div>
        <p class="muted small mt">
          Worker: ${d.system.workerHealthy
            ? `<span class="pill ok">healthy</span> last draw ${esc(d.system.lastResultAt ?? '—')}`
            : `<span class="pill bad">stale</span> last draw ${esc(d.system.lastResultAt ?? 'never')}`}
        </p>
        <p class="muted small">All-time stake ${money(d.allTime.stake)} · payout ${money(d.allTime.payout)} · GGR ${signed(d.allTime.ggr)}</p>
      </div>
    </div>`;

  updateWorkerPill(d.system);
};

const statCard = (label, value, sub) => `
  <div class="card stat">
    <div class="label">${esc(label)}</div>
    <div class="value">${value}</div>
    <div class="sub">${esc(sub)}</div>
  </div>`;

const miniStat = (label, value) => `
  <div style="background:var(--card-2);border-radius:9px;padding:10px 12px">
    <div class="muted small">${esc(label)}</div><div style="font-size:18px;font-weight:650">${esc(value)}</div>
  </div>`;

/* --------------------------------------------------------- live games */
views.games = async () => {
  const d = await api('games');
  store.games = d.list;

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head">
        <h3>Live rounds</h3>
        <span class="muted small">server ${esc(d.serverTime)} · auto-refresh 5s</span>
      </div>
      ${table([
        { label: 'Game', render: (r) => `<strong>${esc(r.gameCode)}</strong><div class="muted small">${esc(r.name)}</div>` },
        { label: 'Current issue', render: (r) => `<span class="small">${esc(r.currentIssue.issueNumber)}</span>` },
        { label: 'Closes in', num: true, render: (r) => `<span class="countdown" data-end="${r.currentIssue.remaining}">${r.currentIssue.remaining}s</span>` },
        { label: 'Betting', render: (r) => r.currentIssue.bettingOpen ? '<span class="pill ok">open</span>' : '<span class="pill warn">locked</span>' },
        { label: 'Bets', num: true, render: (r) => r.liveBets },
        { label: 'Stake', num: true, render: (r) => money(r.liveStake) },
        { label: 'Override', render: (r) => r.pendingOverride
            ? `<span class="pill warn">${esc(r.pendingOverride.value)} (${esc(r.pendingOverride.mode)})</span>`
            : '<span class="muted small">—</span>' },
        { label: '', render: (r) => `
            <div class="btn-row">
              <button class="btn small" data-exposure="${esc(r.gameCode)}">Risk</button>
              <button class="btn small" data-settle="${esc(r.gameCode)}">Settle</button>
            </div>` },
      ], d.list)}
    </div>`;

  $$('[data-exposure]').forEach((b) => b.onclick = () => openExposure(b.dataset.exposure));
  $$('[data-settle]').forEach((b) => b.onclick = async () => {
    try {
      const r = await api('settle', { gameCode: b.dataset.settle }, 'POST');
      toast(`Settled ${r.reports?.length ?? 1} issue(s) for ${b.dataset.settle}`);
      render(false);
    } catch (e) { toast(e.message, 'err'); }
  });

  stopTimers();
  store.timer = setInterval(() => {
    $$('.countdown').forEach((el) => {
      const left = Math.max(0, Number(el.dataset.end) - 1);
      el.dataset.end = left;
      el.textContent = left + 's';
    });
  }, 1000);
  setTimeout(() => { if (store.view === 'games') render(false); }, 15000);
};

async function openExposure(gameCode) {
  modal(`Risk exposure — ${gameCode}`, '<div class="loading">Calculating…</div>');
  try {
    const d = await api('exposure', { gameCode });
    const worst = d.outcomes[d.outcomes.length - 1];
    $('#modal-body').innerHTML = `
      <p class="muted small">Issue <code>${esc(d.issueNumber)}</code> · ${d.bets} pending bets · ${d.players} players · stake ${money(d.stake)}</p>
      ${d.note ? `<p class="muted small">${esc(d.note)}</p>` : ''}
      <div class="grid k2">
        <div>
          <h3 class="muted small">Stake by selection</h3>
          ${table([
            { label: 'Type', render: (r) => esc(r.betType) },
            { label: 'Selection', render: (r) => esc(r.selection) },
            { label: 'Stake', num: true, render: (r) => money(r.stake) },
          ], d.selections, 'No pending bets')}
        </div>
        <div>
          <h3 class="muted small">House profit per outcome ${worst ? `(worst: ${esc(worst.outcome)} ${signed(worst.profit)})` : ''}</h3>
          ${table([
            { label: 'Outcome', render: (r) => `<strong>${esc(r.outcome)}</strong>` },
            { label: 'Payout', num: true, render: (r) => money(r.payout) },
            { label: 'Profit', num: true, render: (r) => signed(r.profit) },
            { label: '', render: (r) => `<button class="btn small" data-force="${esc(r.override)}">Force</button>` },
          ], d.outcomes.slice(0, 20), 'Simulation unavailable')}
        </div>
      </div>`;

    $$('[data-force]').forEach((b) => b.onclick = async () => {
      if (!confirm(`Force result "${b.dataset.force}" for ${gameCode} issue ${d.issueNumber}?`)) return;
      try {
        await api('setoverride', { gameCode, issueNumber: d.issueNumber, value: b.dataset.force, mode: 'oneshot' }, 'POST');
        toast('Override queued for ' + d.issueNumber);
        $('#modal').classList.add('hidden');
        render(false);
      } catch (e) { toast(e.message, 'err'); }
    });
  } catch (e) {
    $('#modal-body').innerHTML = `<div class="empty">${esc(e.message)}</div>`;
  }
}

/* ------------------------------------------------------------ results */
views.results = async (state = {}) => {
  const filters = { gameCode: state.gameCode ?? '', pageNo: state.pageNo ?? 1, pageSize: 20 };
  const [data, overrides] = await Promise.all([api('results', filters), api('overrides')]);

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head"><h3>Result override</h3></div>
      <div class="form-grid">
        <label>Game <select id="ov-game">${gameOptions(filters.gameCode || store.games[0]?.gameCode)}</select></label>
        <label>Issue number <input id="ov-issue" placeholder="blank = next round (legacy)"></label>
        <label>Value <input id="ov-value" placeholder="WinGo 7 · K3 1,3,6 · D5 12345"></label>
        <label>Mode <select id="ov-mode"><option value="oneshot">one-shot (exact issue)</option><option value="legacy">legacy (next round)</option></select></label>
        <label>Note <input id="ov-note" placeholder="reason (audited)"></label>
      </div>
      <div class="btn-row mt">
        <button class="btn primary" id="ov-save">Queue override</button>
        <button class="btn danger" id="ov-cancel">Cancel pending</button>
      </div>
      <div class="mt">
        ${table([
          { label: 'Game', render: (r) => esc(r.game_code) },
          { label: 'Issue', render: (r) => esc(r.issue_number) },
          { label: 'Value', render: (r) => `<strong>${esc(r.override_value)}</strong>` },
          { label: 'Mode', render: (r) => esc(r.mode) },
          { label: 'Note', render: (r) => esc(clamp(r.note, 40)) },
          { label: 'Queued', render: (r) => `<span class="muted small">${esc(r.created_at)}</span>` },
        ], overrides.list, 'No pending overrides')}
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Draw history</h3>
        <div class="filters">
          <div class="field"><select id="f-game">${gameOptions(filters.gameCode, true)}</select></div>
        </div>
      </div>
      ${table([
        { label: 'Game', render: (r) => esc(r.gameCode) },
        { label: 'Issue', render: (r) => `<span class="small">${esc(r.issueNumber)}</span>` },
        { label: 'Result', render: (r) => resultChip(r) },
        { label: 'Source', render: (r) => `<span class="pill ${r.source === 'override' ? 'warn' : r.source === 'remote' ? 'ok' : ''}">${esc(r.source)}</span>` },
        { label: 'Bets', num: true, render: (r) => r.bets },
        { label: 'Stake', num: true, render: (r) => money(r.stake) },
        { label: 'Payout', num: true, render: (r) => money(r.payout) },
        { label: 'GGR', num: true, render: (r) => signed(r.ggr) },
        { label: 'Drawn', render: (r) => `<span class="muted small">${esc(r.drawnAt)}</span>` },
      ], data.list, 'No results yet')}
      ${pager(data, (p) => views.results({ ...filters, pageNo: p }))}
    </div>`;

  $('#f-game').onchange = (e) => views.results({ gameCode: e.target.value, pageNo: 1 });
  $('#ov-save').onclick = async () => {
    try {
      await api('setoverride', {
        gameCode: $('#ov-game').value,
        issueNumber: $('#ov-issue').value.trim(),
        value: $('#ov-value').value.trim(),
        mode: $('#ov-mode').value,
        note: $('#ov-note').value.trim(),
      }, 'POST');
      toast('Override queued');
      views.results(filters);
    } catch (e) { toast(e.message, 'err'); }
  };
  $('#ov-cancel').onclick = async () => {
    try {
      const r = await api('canceloverride', { gameCode: $('#ov-game').value, issueNumber: $('#ov-issue').value.trim() }, 'POST');
      toast(r.cancelled ? 'Override cancelled' : 'Nothing to cancel', r.cancelled ? 'ok' : 'err');
      views.results(filters);
    } catch (e) { toast(e.message, 'err'); }
  };
};

/* --------------------------------------------------------------- bets */
views.bets = async (state = {}) => {
  const filters = {
    gameCode: state.gameCode ?? '', status: state.status ?? '', userId: state.userId ?? '',
    issueNumber: state.issueNumber ?? '', source: state.source ?? '',
    pageNo: state.pageNo ?? 1, pageSize: 20,
  };
  const data = await api('bets', filters);

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head">
        <h3>Bets</h3>
        <span class="muted small">stake ${money(data.totals.stake)} · payout ${money(data.totals.payout)} · GGR ${signed(data.totals.ggr)}</span>
      </div>
      <div class="filters">
        <div class="field"><label>Game</label><select id="f-game">${gameOptions(filters.gameCode, true)}</select></div>
        <div class="field"><label>Status</label><select id="f-status">
          ${['', 'pending', 'won', 'lost'].map((s) => `<option value="${s}" ${s === filters.status ? 'selected' : ''}>${s || 'Any'}</option>`).join('')}
        </select></div>
        <div class="field"><label>Source</label><select id="f-source">
          ${['', 'manual', 'follow'].map((s) => `<option value="${s}" ${s === filters.source ? 'selected' : ''}>${s || 'Any'}</option>`).join('')}
        </select></div>
        <div class="field"><label>User ID</label><input id="f-user" value="${esc(filters.userId)}" placeholder="e.g. 1001"></div>
        <div class="field"><label>Issue</label><input id="f-issue" value="${esc(filters.issueNumber)}" placeholder="17 digits"></div>
        <button class="btn primary" id="f-apply">Apply</button>
      </div>
      <div class="mt">
        ${table([
          { label: 'Bet no', render: (r) => `<span class="small">${esc(r.betNo)}</span>` },
          { label: 'User', render: (r) => `<button class="btn small ghost" data-user="${r.userId}">#${r.userId}</button>` },
          { label: 'Game', render: (r) => esc(r.gameCode) },
          { label: 'Issue', render: (r) => `<span class="small muted">${esc(r.issueNumber)}</span>` },
          { label: 'Bet', render: (r) => `${esc(r.betType)} <strong>${esc(r.betContent)}</strong> <span class="muted small">x${r.multiplier}</span>` },
          { label: 'Stake', num: true, render: (r) => money(r.stake) },
          { label: 'Payout', num: true, render: (r) => money(r.payout) },
          { label: 'P/L', num: true, render: (r) => signed(r.profit) },
          { label: 'Status', render: (r) => statusPill(r.status) },
          { label: 'Placed', render: (r) => `<span class="muted small">${esc(r.createdAt)}</span>` },
        ], data.list, 'No bets match these filters')}
      </div>
      ${pager(data, (p) => views.bets({ ...filters, pageNo: p }))}
    </div>`;

  $('#f-apply').onclick = () => views.bets({
    gameCode: $('#f-game').value, status: $('#f-status').value, source: $('#f-source').value,
    userId: $('#f-user').value.trim(), issueNumber: $('#f-issue').value.trim(), pageNo: 1,
  });
  $$('[data-user]').forEach((b) => b.onclick = () => openUser(b.dataset.user));
};

/* -------------------------------------------------------------- users */
views.users = async (state = {}) => {
  const filters = { search: state.search ?? '', pageNo: state.pageNo ?? 1, pageSize: 20 };
  const data = await api('users', filters);

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head"><h3>Create user</h3></div>
      <div class="form-grid">
        <label>Mobile <input id="nu-mobile" placeholder="9876543210"></label>
        <label>Nickname <input id="nu-nick" placeholder="optional"></label>
        <label>Opening balance <input id="nu-balance" type="number" min="0" step="0.01" value="0"></label>
      </div>
      <button class="btn primary mt" id="nu-save">Create user</button>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Users</h3>
        <div class="filters">
          <div class="field"><input id="f-search" value="${esc(filters.search)}" placeholder="mobile or id"></div>
          <button class="btn" id="f-go">Search</button>
        </div>
      </div>
      ${table([
        { label: 'ID', render: (r) => r.userId },
        { label: 'Mobile', render: (r) => esc(r.mobile) },
        { label: 'Balance', num: true, render: (r) => money(r.balance) },
        { label: 'Staked', num: true, render: (r) => money(r.totalStake) },
        { label: 'Won', num: true, render: (r) => money(r.totalPayout) },
        { label: 'VIP', render: (r) => `<span class="pill">L${r.level}</span> <span class="muted small">${money(r.experience)}</span>` },
        { label: 'Status', render: (r) => r.status === 1 ? '<span class="pill ok">active</span>' : '<span class="pill bad">blocked</span>' },
        { label: '', render: (r) => `<div class="btn-row">
            <button class="btn small" data-user="${r.userId}">Manage</button>
            <button class="btn small ${r.status === 1 ? 'danger' : ''}" data-toggle="${r.userId}" data-status="${r.status === 1 ? 0 : 1}">${r.status === 1 ? 'Block' : 'Unblock'}</button>
          </div>` },
      ], data.list, 'No users yet')}
      ${pager(data, (p) => views.users({ ...filters, pageNo: p }))}
    </div>`;

  $('#f-go').onclick = () => views.users({ search: $('#f-search').value.trim(), pageNo: 1 });
  $('#nu-save').onclick = async () => {
    try {
      const r = await api('createuser', {
        mobile: $('#nu-mobile').value.trim(),
        nickname: $('#nu-nick').value.trim(),
        balance: $('#nu-balance').value || '0',
      }, 'POST');
      toast('User #' + r.userId + ' created');
      modal('User #' + r.userId + ' created', `
        <p class="muted small">Share this JWT with the client app to authenticate as the new user.</p>
        <textarea rows="4" readonly>${esc(r.token)}</textarea>`);
      views.users(filters);
    } catch (e) { toast(e.message, 'err'); }
  };
  $$('[data-user]').forEach((b) => b.onclick = () => openUser(b.dataset.user));
  $$('[data-toggle]').forEach((b) => b.onclick = async () => {
    try {
      await api('setuserstatus', { userId: b.dataset.toggle, status: b.dataset.status }, 'POST');
      toast('User status updated');
      views.users(filters);
    } catch (e) { toast(e.message, 'err'); }
  });
};

async function openUser(userId) {
  modal('User #' + userId, '<div class="loading">Loading…</div>');
  try {
    const d = await api('user', { userId });
    $('#modal-body').innerHTML = `
      <div class="grid k4">
        ${statCard('Balance', money(d.user.balance), d.user.mobile)}
        ${statCard('Staked', money(d.user.totalStake), 'lifetime')}
        ${statCard('Won', money(d.user.totalPayout), 'lifetime')}
        ${statCard('VIP', 'L' + d.vip.level, d.vip.experience + ' exp')}
      </div>

      <div class="card mt">
        <h3>Wallet adjustment</h3>
        <div class="form-grid">
          <label>Direction <select id="wa-dir"><option value="credit">credit (add)</option><option value="debit">debit (remove)</option></select></label>
          <label>Amount <input id="wa-amount" type="number" min="0.01" step="0.01" placeholder="100.00"></label>
          <label>Remark <input id="wa-remark" placeholder="reason (audited)"></label>
        </div>
        <div class="btn-row mt">
          <button class="btn primary" id="wa-go">Apply</button>
          <button class="btn" id="vip-backfill">Backfill VIP</button>
        </div>
      </div>

      <div class="card mt"><h3>Recent bets</h3>
        ${table([
          { label: 'Game', render: (r) => esc(r.gameCode) },
          { label: 'Bet', render: (r) => `${esc(r.betType)} ${esc(r.betContent)}` },
          { label: 'Stake', num: true, render: (r) => money(r.stake) },
          { label: 'Payout', num: true, render: (r) => money(r.payout) },
          { label: 'Status', render: (r) => statusPill(r.status) },
        ], d.bets, 'No bets')}
      </div>

      <div class="card mt"><h3>Recent ledger</h3>
        ${table([
          { label: 'Dir', render: (r) => r.direction === 'credit' ? '<span class="pos">+</span>' : '<span class="neg">−</span>' },
          { label: 'Amount', num: true, render: (r) => money(r.amount) },
          { label: 'Balance', num: true, render: (r) => money(r.balance_after) },
          { label: 'Type', render: (r) => esc(r.ref_type) },
          { label: 'Remark', render: (r) => esc(clamp(r.remark, 30)) },
          { label: 'When', render: (r) => `<span class="muted small">${esc(r.created_at)}</span>` },
        ], d.ledger, 'No ledger entries')}
      </div>`;

    $('#wa-go').onclick = async () => {
      try {
        const r = await api('adjustwallet', {
          userId, direction: $('#wa-dir').value,
          amount: $('#wa-amount').value, remark: $('#wa-remark').value.trim(),
        }, 'POST');
        toast('New balance ' + money(r.balance));
        openUser(userId);
      } catch (e) { toast(e.message, 'err'); }
    };
    $('#vip-backfill').onclick = async () => {
      try {
        const r = await api('backfillvip', { userId }, 'POST');
        toast(r.backfilled ? 'Backfilled to ' + r.experience + ' exp' : 'Already backfilled');
        openUser(userId);
      } catch (e) { toast(e.message, 'err'); }
    };
  } catch (e) {
    $('#modal-body').innerHTML = `<div class="empty">${esc(e.message)}</div>`;
  }
}

/* ------------------------------------------------------------- ledger */
views.ledger = async (state = {}) => {
  const filters = { userId: state.userId ?? '', pageNo: state.pageNo ?? 1, pageSize: 30 };
  const data = await api('ledger', filters);

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head">
        <h3>Wallet ledger</h3>
        <div class="filters">
          <div class="field"><input id="f-user" value="${esc(filters.userId)}" placeholder="filter by user id"></div>
          <button class="btn" id="f-go">Filter</button>
        </div>
      </div>
      ${table([
        { label: 'ID', render: (r) => r.id },
        { label: 'User', render: (r) => `#${r.user_id}` },
        { label: 'Dir', render: (r) => r.direction === 'credit' ? '<span class="pos">credit</span>' : '<span class="neg">debit</span>' },
        { label: 'Amount', num: true, render: (r) => money(r.amount) },
        { label: 'Before', num: true, render: (r) => money(r.balance_before) },
        { label: 'After', num: true, render: (r) => money(r.balance_after) },
        { label: 'Type', render: (r) => esc(r.ref_type) },
        { label: 'Ref', render: (r) => `<span class="small muted">${esc(clamp(r.ref_id, 22))}</span>` },
        { label: 'When', render: (r) => `<span class="muted small">${esc(r.created_at)}</span>` },
      ], data.list, 'Ledger is empty')}
      ${pager(data, (p) => views.ledger({ ...filters, pageNo: p }))}
    </div>`;

  $('#f-go').onclick = () => views.ledger({ userId: $('#f-user').value.trim(), pageNo: 1 });
};

/* ------------------------------------------------------- copy trading */
views.plans = async (state = {}) => {
  const [plans, follows] = await Promise.all([
    api('plans'),
    api('follows', { status: state.status ?? '', pageNo: state.pageNo ?? 1, pageSize: 20 }),
  ]);

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head"><h3>Create / update plan</h3><span class="muted small">plan code is the key — reusing one updates it</span></div>
      <div class="form-grid">
        <label>Plan code <input id="pl-code" placeholder="wingo1m-bigsmall-big"></label>
        <label>Name <input id="pl-name" placeholder="BigSmall · Big"></label>
        <label>Game <select id="pl-game">${gameOptions('WinGo_1M')}</select></label>
        <label>Bet type <input id="pl-type" placeholder="size"></label>
        <label>Bet content <input id="pl-content" placeholder="big"></label>
        <label>Min amount <input id="pl-min" type="number" min="1" step="0.01" value="1"></label>
        <label>Sort <input id="pl-sort" type="number" value="10"></label>
        <label>State <select id="pl-state"><option value="1">enabled</option><option value="0">disabled</option></select></label>
        <label>Description <input id="pl-desc" placeholder="shown to players"></label>
      </div>
      <button class="btn primary mt" id="pl-save">Save plan</button>
    </div>

    <div class="card">
      <h3>Plans</h3>
      ${table([
        { label: 'Code', render: (r) => `<strong>${esc(r.plan_code)}</strong>` },
        { label: 'Name', render: (r) => esc(r.name) },
        { label: 'Game', render: (r) => esc(r.game_code) },
        { label: 'Bet', render: (r) => `${esc(r.bet_type)} <strong>${esc(r.bet_content)}</strong>` },
        { label: 'Min', num: true, render: (r) => money(r.min_amount) },
        { label: 'State', render: (r) => Number(r.state) === 1 ? '<span class="pill ok">enabled</span>' : '<span class="pill">disabled</span>' },
        { label: '', render: (r) => `<div class="btn-row">
            <button class="btn small" data-edit='${esc(JSON.stringify(r))}'>Edit</button>
            <button class="btn small danger" data-del="${esc(r.plan_code)}">Delete</button>
          </div>` },
      ], plans.list, 'No plans configured')}
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Subscriptions</h3>
        <div class="filters"><div class="field"><select id="f-status">
          ${['', 'active', 'stopped', 'completed'].map((s) => `<option value="${s}" ${s === (state.status ?? '') ? 'selected' : ''}>${s || 'Any status'}</option>`).join('')}
        </select></div></div>
      </div>
      ${table([
        { label: 'ID', render: (r) => r.followId },
        { label: 'User', render: (r) => `<button class="btn small ghost" data-user="${r.userId}">#${r.userId}</button> <span class="muted small">${esc(r.mobile ?? '')}</span>` },
        { label: 'Plan', render: (r) => esc(r.planCode) },
        { label: 'Game', render: (r) => esc(r.gameCode) },
        { label: 'Amount', num: true, render: (r) => `${money(r.amount)} <span class="muted small">x${r.multiplier}</span>` },
        { label: 'Rounds', num: true, render: (r) => `${r.completedRounds}/${r.totalRounds || '∞'}` },
        { label: 'Status', render: (r) => statusPill(r.status) },
        { label: '', render: (r) => r.status === 'active' ? `<button class="btn small danger" data-stop="${r.followId}">Stop</button>` : '' },
      ], follows.list, 'No subscriptions')}
      ${pager(follows, (p) => views.plans({ ...state, pageNo: p }))}
    </div>`;

  $('#f-status').onchange = (e) => views.plans({ status: e.target.value, pageNo: 1 });
  $('#pl-save').onclick = async () => {
    try {
      await api('saveplan', {
        planCode: $('#pl-code').value.trim(), name: $('#pl-name').value.trim(),
        gameCode: $('#pl-game').value, betType: $('#pl-type').value.trim(),
        betContent: $('#pl-content').value.trim(), minAmount: $('#pl-min').value,
        sort: $('#pl-sort').value, state: $('#pl-state').value,
        description: $('#pl-desc').value.trim(),
      }, 'POST');
      toast('Plan saved');
      views.plans(state);
    } catch (e) { toast(e.message, 'err'); }
  };
  $$('[data-edit]').forEach((b) => b.onclick = () => {
    const p = JSON.parse(b.dataset.edit);
    $('#pl-code').value = p.plan_code; $('#pl-name').value = p.name;
    $('#pl-game').value = p.game_code; $('#pl-type').value = p.bet_type;
    $('#pl-content').value = p.bet_content; $('#pl-min').value = p.min_amount;
    $('#pl-sort').value = p.sort; $('#pl-state').value = p.state;
    $('#pl-desc').value = p.description ?? '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $$('[data-del]').forEach((b) => b.onclick = async () => {
    if (!confirm('Delete plan ' + b.dataset.del + '?')) return;
    try {
      const r = await api('deleteplan', { planCode: b.dataset.del }, 'POST');
      toast(r.deleted ? 'Plan deleted' : 'Plan disabled (has active subscribers)');
      views.plans(state);
    } catch (e) { toast(e.message, 'err'); }
  });
  $$('[data-stop]').forEach((b) => b.onclick = async () => {
    try { await api('stopfollow', { followId: b.dataset.stop }, 'POST'); toast('Subscription stopped'); views.plans(state); }
    catch (e) { toast(e.message, 'err'); }
  });
  $$('[data-user]').forEach((b) => b.onclick = () => openUser(b.dataset.user));
};


/* ------------------------------------------------------ feed domains */
views.domains = async (state = {}) => {
  const filters = { search: state.search ?? '', pageNo: state.pageNo ?? 1, pageSize: 20 };
  const data = await api('domains', filters);
  const s = data.summary;

  $('#view').innerHTML = `
    <div class="grid k4">
      ${statCard('Whitelisted domains', s.total, s.active + ' active')}
      ${statCard('Feed requests', Number(s.requests).toLocaleString('en-IN'), 'all time')}
      ${statCard('Blocked attempts', Number(s.blocked).toLocaleString('en-IN'), 'not whitelisted / disabled')}
      ${statCard('Per-domain limit', '600/min', 'override per domain below')}
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Whitelist a customer domain</h3>
        <span class="muted small">only these domains can read your result feed</span>
      </div>
      <div class="form-grid">
        <label>Domain <input id="dm-domain" placeholder="client-site.com"></label>
        <label>Label <input id="dm-label" placeholder="Client name"></label>
        <label>Games <input id="dm-games" placeholder="blank = all games, or WinGo_1M,K3_1M"></label>
        <label>Rate limit /min <input id="dm-rate" type="number" min="0" step="10" value="0" placeholder="0 = default"></label>
        <label>Expires <input id="dm-expires" placeholder="YYYY-MM-DD HH:MM:SS (optional)"></label>
        <label>Note <input id="dm-note" placeholder="plan / contact"></label>
        <label>Token check URL <input id="dm-validate" placeholder="https://their-site.com/api/User/GetUserInfo"></label>
        <label>Method <select id="dm-method"><option value="POST">POST</option><option value="GET">GET</option></select></label>
        <label>Cache seconds <input id="dm-ttl" type="number" min="30" step="30" value="300"></label>
        <label>Their JWT secret <input id="dm-secret" placeholder="only if they sign their own JWTs"></label>
      </div>
      <p class="muted small mt">“Token check URL” lets players log in with the token their own site already gave them —
      we ask that endpoint who the token belongs to. Leave blank if they use PartnerLogin instead.</p>
      <input type="hidden" id="dm-id" value="">
      <div class="btn-row mt">
        <button class="btn primary" id="dm-save">Save domain</button>
        <button class="btn ghost" id="dm-clear">Clear form</button>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>Domains</h3>
        <div class="filters">
          <div class="field"><input id="f-search" value="${esc(filters.search)}" placeholder="search domain"></div>
          <button class="btn" id="f-go">Search</button>
        </div>
      </div>
      ${table([
        { label: 'Domain', render: (r) => `<strong>${esc(r.domain)}</strong><div class="muted small">${esc(r.label ?? '')}</div>` },
        { label: 'API key', render: (r) => `<code class="small">${esc(r.apiKey.slice(0, 10))}…</code>
            <button class="btn small ghost" data-copy="${esc(r.apiKey)}">copy</button>` },
        { label: 'Games', render: (r) => r.games.length ? esc(r.games.join(', ')) : '<span class="muted small">all</span>' },
        { label: 'Login', render: (r) => r.validateUrl
            ? '<span class="pill ok">token check</span>'
            : (r.hasPlayerSecret ? '<span class="pill ok">their JWT</span>' : '<span class="muted small">PartnerLogin</span>') },
        { label: 'Requests', num: true, render: (r) => Number(r.requests).toLocaleString('en-IN') },
        { label: 'Blocked', num: true, render: (r) => Number(r.blocked).toLocaleString('en-IN') },
        { label: 'Last seen', render: (r) => `<span class="muted small">${esc(r.lastSeenAt ?? 'never')}</span>` },
        { label: 'Status', render: (r) => r.status === 1 ? '<span class="pill ok">allowed</span>' : '<span class="pill bad">blocked</span>' },
        { label: '', render: (r) => `<div class="btn-row">
            <button class="btn small" data-edit-domain='${esc(JSON.stringify(r))}'>Edit</button>
            <button class="btn small" data-usage="${r.id}">Usage</button>
            <button class="btn small ${r.status === 1 ? 'danger' : ''}" data-toggle-domain="${r.id}" data-status="${r.status === 1 ? 0 : 1}">${r.status === 1 ? 'Block' : 'Allow'}</button>
            <button class="btn small" data-rotate="${r.id}">Rotate key</button>
            <button class="btn small danger" data-del-domain="${r.id}">Delete</button>
          </div>` },
      ], data.list, 'No domains yet — add one above, otherwise nobody can read the feed')}
      ${pager(data, (p) => views.domains({ ...filters, pageNo: p }))}
    </div>`;

  $('#f-go').onclick = () => views.domains({ search: $('#f-search').value.trim(), pageNo: 1 });
  $('#dm-clear').onclick = () => views.domains(filters);
  $('#dm-save').onclick = async () => {
    try {
      const r = await api('savedomain', {
        id: $('#dm-id').value || 0,
        domain: $('#dm-domain').value.trim(),
        label: $('#dm-label').value.trim(),
        games: $('#dm-games').value.trim(),
        rateLimit: $('#dm-rate').value || 0,
        expiresAt: $('#dm-expires').value.trim(),
        note: $('#dm-note').value.trim(),
        validateUrl: $('#dm-validate').value.trim(),
        validateMethod: $('#dm-method').value,
        validateTtl: $('#dm-ttl').value || 300,
        playerSecret: $('#dm-secret').value.trim(),
      }, 'POST');
      toast('Saved ' + r.domain);
      modal('Feed access for ' + r.domain, `
        <p class="muted small">Give the customer these details. Requests from any other domain are rejected.</p>
        <div class="card"><h3>Base URL</h3><code>${esc(location.origin)}</code></div>
        <div class="card mt"><h3>API key (server-to-server)</h3><code>${esc(r.apiKey)}</code></div>
        <div class="card mt"><h3>Example</h3>
          <textarea rows="3" readonly>${esc(location.origin)}/WinGo/WinGo_1M/GetHistoryIssuePage.json</textarea>
          <textarea rows="3" readonly>curl -H "X-Api-Key: ${esc(r.apiKey)}" "${esc(location.origin)}/api/Feed?action=History&gameCode=WinGo_1M"</textarea>
        </div>`);
      views.domains(filters);
    } catch (e) { toast(e.message, 'err'); }
  };
  $$('[data-copy]').forEach((b) => b.onclick = () => {
    navigator.clipboard?.writeText(b.dataset.copy);
    toast('API key copied');
  });
  $$('[data-edit-domain]').forEach((b) => b.onclick = () => {
    const d = JSON.parse(b.dataset.editDomain);
    $('#dm-id').value = d.id; $('#dm-domain').value = d.domain; $('#dm-label').value = d.label ?? '';
    $('#dm-games').value = d.games.join(','); $('#dm-rate').value = d.rateLimit;
    $('#dm-expires').value = d.expiresAt ?? ''; $('#dm-note').value = d.note ?? '';
    $('#dm-validate').value = d.validateUrl ?? ''; $('#dm-method').value = d.validateMethod ?? 'POST';
    $('#dm-ttl').value = d.validateTtl ?? 300;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $$('[data-toggle-domain]').forEach((b) => b.onclick = async () => {
    try { await api('setdomainstatus', { id: b.dataset.toggleDomain, status: b.dataset.status }, 'POST'); toast('Updated'); views.domains(filters); }
    catch (e) { toast(e.message, 'err'); }
  });
  $$('[data-rotate]').forEach((b) => b.onclick = async () => {
    if (!confirm('Rotate the API key? The old key stops working immediately.')) return;
    try { const r = await api('rotatedomainkey', { id: b.dataset.rotate }, 'POST'); toast('New key: ' + r.apiKey); views.domains(filters); }
    catch (e) { toast(e.message, 'err'); }
  });
  $$('[data-del-domain]').forEach((b) => b.onclick = async () => {
    if (!confirm('Delete this domain? It will lose access immediately.')) return;
    try { await api('deletedomain', { id: b.dataset.delDomain }, 'POST'); toast('Deleted'); views.domains(filters); }
    catch (e) { toast(e.message, 'err'); }
  });
  $$('[data-usage]').forEach((b) => b.onclick = async () => {
    modal('Usage', '<div class="loading">Loading…</div>');
    try {
      const d = await api('domainusage', { id: b.dataset.usage, days: 14 });
      $('#modal-body').innerHTML = `
        <p class="muted small">${esc(d.domain.domain)} · ${Number(d.domain.requests).toLocaleString('en-IN')} requests total</p>
        ${table([
          { label: 'Day', render: (r) => esc(r.day) },
          { label: 'Requests', num: true, render: (r) => r.requests },
          { label: 'Blocked', num: true, render: (r) => r.blocked },
        ], d.usage, 'No traffic yet')}`;
    } catch (e) { $('#modal-body').innerHTML = `<div class="empty">${esc(e.message)}</div>`; }
  });
};

/* --------------------------------------------------------- feed info */
views.feed = async () => {
  const d = await api('feedinfo');

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head">
        <h3>Your feed</h3>
        ${d.upstream.enabled
          ? `<span class="pill ok">upstream: ${esc(d.upstream.profile)}</span>`
          : '<span class="pill warn">upstream off — local generator</span>'}
      </div>
      <p class="muted small">Hand these URLs to customers. Only whitelisted domains (see the Domains tab) get data;
      everyone else receives <code>403 DOMAIN_NOT_ALLOWED</code>.</p>
      <div class="grid k2">
        <div>
          <h3 class="muted small">Base URL</h3><code>${esc(d.baseUrl)}</code>
          <h3 class="muted small mt">Game list</h3><code>${esc(d.gameList)}</code>
          <h3 class="muted small mt">Public board</h3><code>${esc(d.board)}</code>
        </div>
        <div>
          <h3 class="muted small">Upstream source (never exposed to customers)</h3>
          <code>${esc(d.upstream.baseUrl)}</code>
          <div class="muted small mt">sample: ${esc(d.upstream.sample)}</div>
          <div class="muted small">feed limit: ${d.rateLimit} req/min per domain</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Endpoints per game</h3>
      ${table([
        { label: 'Game', render: (r) => `<strong>${esc(r.gameCode)}</strong>` },
        { label: 'Issue prefix', render: (r) => `<code>${esc(r.issuePrefix)}</code>` },
        { label: 'History', render: (r) => `<code class="small">${esc(r.history)}</code>
            <button class="btn small ghost" data-copy="${esc(r.history)}">copy</button>` },
        { label: 'Current issue', render: (r) => `<code class="small">${esc(r.issue)}</code>` },
      ], d.games)}
    </div>`;

  $$('[data-copy]').forEach((b) => b.onclick = () => { navigator.clipboard?.writeText(b.dataset.copy); toast('URL copied'); });
};

/* ---------------------------------------------------------------- vip */
views.vip = async () => {
  const d = await api('vip', { limit: 25 });

  $('#view').innerHTML = `
    <div class="grid k2">
      <div class="card">
        <h3>Level thresholds</h3>
        ${table([
          { label: 'Level', render: (r) => 'L' + r.level },
          { label: 'Experience required', num: true, render: (r) => Number(r.experience).toLocaleString('en-IN') },
          { label: 'Players', num: true, render: (r) => (d.distribution.find((x) => x.level === r.level)?.players ?? 0) },
        ], d.levels)}
      </div>
      <div class="card">
        <h3>Top players by experience</h3>
        ${table([
          { label: 'User', render: (r) => `<button class="btn small ghost" data-user="${r.userId}">#${r.userId}</button> <span class="muted small">${esc(r.mobile ?? '')}</span>` },
          { label: 'Level', render: (r) => `<span class="pill">L${r.level}</span>` },
          { label: 'Experience', num: true, render: (r) => money(r.experience) },
          { label: 'Backfilled', render: (r) => r.backfilled ? '<span class="pill ok">yes</span>' : '<span class="pill">no</span>' },
        ], d.top, 'No VIP data yet')}
      </div>
    </div>`;

  $$('[data-user]').forEach((b) => b.onclick = () => openUser(b.dataset.user));
};

/* -------------------------------------------------------------- audit */
views.audit = async (state = {}) => {
  const data = await api('auditlog', { pageNo: state.pageNo ?? 1, pageSize: 30 });

  $('#view').innerHTML = `
    <div class="card">
      <h3>Admin audit log</h3>
      ${table([
        { label: 'When', render: (r) => `<span class="muted small">${esc(r.created_at)}</span>` },
        { label: 'Actor', render: (r) => esc(r.actor) },
        { label: 'Action', render: (r) => `<span class="pill">${esc(r.action)}</span>` },
        { label: 'Target', render: (r) => esc(r.target ?? '—') },
        { label: 'Detail', render: (r) => `<span class="small muted">${esc(clamp(r.detail, 70))}</span>` },
        { label: 'IP', render: (r) => `<span class="small muted">${esc(r.ip ?? '')}</span>` },
      ], data.list, 'No admin actions recorded')}
      ${pager(data, (p) => views.audit({ pageNo: p }))}
    </div>`;
};

/* ------------------------------------------------------------- system */
views.system = async () => {
  const s = await api('system');
  const rows = [
    ['Version', s.version], ['Environment', s.env], ['Timezone', s.timezone],
    ['Server time', s.serverTime], ['Database driver', s.driver],
    ['Schema version', s.schemaVersion + ' (latest ' + s.latestSchema + ')'],
    ['Games configured', s.games], ['Draw provider', s.drawBaseUrl],
    ['Force remote draw', s.forceRemote ? 'yes' : 'no (local HMAC fallback active)'],
    ['Payout tax', (s.payoutTaxRate * 100).toFixed(2) + '%'],
    ['Stake limits', s.minStake + ' – ' + s.maxStake],
    ['Rate limit', s.rateLimit + ' req/min'],
    ['Request signing', s.requireSign ? 'required' : 'disabled'],
    ['Last draw', s.lastResultAt ?? '—'], ['Last settlement', s.lastSettledAt ?? '—'],
    ['Worker lag', s.workerLagSecs === null ? '—' : s.workerLagSecs + 's'],
  ];

  $('#view').innerHTML = `
    <div class="card">
      <div class="card-head">
        <h3>System</h3>
        ${s.workerHealthy ? '<span class="pill ok">worker healthy</span>' : '<span class="pill bad">worker stale</span>'}
      </div>
      <table><tbody>
        ${rows.map(([k, v]) => `<tr><td class="muted">${esc(k)}</td><td><strong>${esc(v)}</strong></td></tr>`).join('')}
      </tbody></table>
    </div>
    <div class="card">
      <h3>Maintenance</h3>
      <p class="muted small">Settlement also happens lazily on API reads, so these are safe to run any time.</p>
      <div class="btn-row">
        <button class="btn primary" id="sys-worker">Run worker pass now</button>
      </div>
    </div>`;

  $('#sys-worker').onclick = async () => {
    try {
      const r = await api('runworkerpass', {}, 'POST');
      toast(`Settled ${r.settledBets} bets, placed ${r.followBets} copy bets`);
      views.system();
    } catch (e) { toast(e.message, 'err'); }
  };

  updateWorkerPill(s);
};

function updateWorkerPill(system) {
  const pill = $('#worker-pill');
  pill.className = 'pill ' + (system.workerHealthy ? 'ok' : 'bad');
  pill.textContent = system.workerHealthy ? 'worker healthy' : 'worker stale';
}

/* --------------------------------------------------------------- boot */
async function boot() {
  try {
    const games = await api('games');
    store.games = games.list;
  } catch (e) {
    if (e.msgCode === 'AUTH_REQUIRED') return;
    toast(e.message, 'err');
  }

  $('#login').classList.add('hidden');
  $('#app').classList.remove('hidden');
  render(true);

  stopTimers();
  store.clockTimer = setInterval(() => {
    $('#clock').textContent = new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }) + ' IST';
  }, 1000);
}

if (store.token) boot(); else showLogin('');

})();
