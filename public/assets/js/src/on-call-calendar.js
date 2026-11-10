/*
 * On Call Calendar page (GPL-3.0-or-later).
 *
 * Security rules this file follows:
 * - Every string from the server (schedule, employee, route and sound file
 *   names) is put into the page with textContent, never as HTML.
 * - Writes are AJAX POSTs with a JSON body; the core's TokenManager adds the
 *   Bearer token to jQuery's $.ajax, and the controller refuses writes
 *   without it. All validation is on the server; refusals come back
 *   translated and are shown as text.
 *
 * Times: the PBX decides who is on duty by its own clock and time zone, so
 * the page shows every time in the PBX's time zone, not the browser's.
 * FullCalendar runs in that named zone, and the server's times are epoch
 * seconds or the PBX's wall-clock "Y-m-d H:i".
 */

/* global globalRootUrl, globalTranslate, $, FullCalendar */

const BASE = `${globalRootUrl}module-on-call-calendar/`;
const t = (key) => (globalTranslate && globalTranslate[key]) || key;
/** Fills %name% placeholders, as the server's translations do. */
const sub = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.split(`%${k}%`).join(String(v)), text);
const LOCALE = document.documentElement.lang || undefined;
/**
 * A text with a count: the key with the language's plural category (occ_X_one,
 * occ_X_few, …) when there is one, otherwise the key itself.
 */
const plural = (key, n, values = {}) => {
  const form = `${key}_${new Intl.PluralRules(LOCALE).select(n)}`;
  return sub(globalTranslate && globalTranslate[form] ? globalTranslate[form] : t(key), { n, ...values });
};
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
const TABS = ['calendar', 'roster', 'rotation', 'activity', 'settings'];
const weekdayName = (d) => t(`occ_Weekday${d}`);
/** Whether this person's access group may run a change action; the server checks it again. */
const can = (action) => Boolean(state.options && state.options.can && state.options.can[action]);

const state = {
  options: null,   // optionsAction
  detail: null,    // scheduleAction for the open schedule
  scheduleId: 0,
  tab: 'calendar',
  rosterId: 0,
  rosterWeek: 1,
  calendar: null,
  pendingCover: null, // "Cover now" from the home page: {scheduleId, to}, opened once that schedule's calendar is shown
  clockOffset: 0,     // PBX clock minus the browser's, in seconds
  notice: '',         // a warning to show once the next view has loaded
};

/* ------------------------------------------------------------------ DOM helpers */

const $id = (id) => document.getElementById(id);

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (v === undefined || v === null || v === false) return;
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (v === true) node.setAttribute(k, '');
    else node.setAttribute(k, v);
  });
  children.flat(Infinity).forEach((c) => {
    if (c !== null && c !== undefined && c !== false) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  });
  return node;
}

const fill = (node, ...children) => node.replaceChildren(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));

/**
 * A button. When its action returns a request (a promise), the button is
 * disabled until the request settles, so a double click can't send it twice.
 */
function button(text, onclick, cls = '', icon = '') {
  const node = el('button', {
    type: 'button',
    class: `ui ${cls} button`,
    onclick: (e) => {
      if (node.classList.contains('loading')) return;
      const result = onclick(e);
      if (result && typeof result.then === 'function') {
        node.classList.add('loading', 'disabled');
        const done = () => node.classList.remove('loading', 'disabled');
        result.then(done, done);
      }
    },
  }, icon ? el('i', { class: `${icon} icon` }) : null, text);
  return node;
}

const icon = (name) => el('i', { class: `${name} icon`, 'aria-hidden': 'true' });
const loader = () => el('div', { class: 'ui active centered inline loader occ-loader' });

/** A Semantic status label, as the cabinet uses them; colour is green, orange, red or grey. */
function tag(colour, iconName, text, href) {
  return el(href ? 'a' : 'span', { class: `ui small basic ${colour} label`, href }, iconName ? icon(iconName) : null, text);
}

/** A button with only an icon; its label is the tooltip and what screen readers say. */
function iconButton(label, onclick, iconName, cls = 'mini basic') {
  const node = button('', onclick, `${cls} icon`, iconName);
  node.setAttribute('aria-label', label);
  node.title = label;
  return node;
}

/** The icon button back to the list of schedules, as the cabinet's editors have it. */
const backButton = () => iconButton(t('occ_AllSchedules'), () => go('#/'), 'left arrow', 'basic');

/** A section heading with optional actions on its right. */
function sectionHead(title, ...actions) {
  return el('div', { class: 'occ-section-head' },
    el('h4', { class: 'ui header', text: title }),
    el('div', { class: 'occ-section-actions' }, actions));
}

/** A part of a tab: its heading, an optional note under it, and its content. */
function section(title, note, ...children) {
  return el('section', { class: 'occ-section' }, sectionHead(title), note ? el('p', { class: 'occ-note', text: note }) : null, children);
}

/** A colour from the server, used in a style only when it is a plain hex colour. */
const safeColour = (colour) => (/^#[0-9a-f]{3,8}$/i.test(String(colour)) ? colour : '#767676');

/** Up to two initials of a name, for an avatar ("Anna Petrova" → "AP", "Desk 301" → "D3"). */
function initials(name) {
  const words = String(name || '?').trim().split(/\s+/).filter(Boolean);
  if (words.length > 1) return (words[0][0] + words[words.length - 1][0]).toUpperCase();
  return (words[0] || '?').slice(0, 2).toUpperCase();
}

/**
 * A person: their initials in their colour, the name and the extension. A
 * compact person leaves the extension to the tooltip, for narrow places.
 */
function person(name, colour, extension = '', compact = false) {
  return el('span', { class: 'occ-person', style: `--occ-person: ${safeColour(colour)}`, title: extension ? `${name} (${extension})` : name },
    el('span', { class: 'occ-avatar', 'aria-hidden': 'true', text: initials(name) }),
    el('span', { class: 'occ-person-name', text: name }),
    extension && !compact ? el('span', { class: 'occ-person-detail', text: extension }) : null);
}

/**
 * A select from [value, text] pairs. A stored value that is no longer among
 * the options (a deleted sound file or destination) is shown as missing
 * rather than silently displaying the first option while keeping the old value.
 */
function select(options, value, attrs = {}) {
  const current = String(value ?? '');
  const list = current !== '' && !options.some(([v]) => String(v) === current)
    ? [[current, sub(t('occ_Missing'), { value: current })], ...options] : options;
  return el('select', { class: 'ui dropdown', ...attrs },
    list.map(([v, text]) => el('option', { value: String(v), selected: String(v) === current }, text)));
}

function field(labelText, control, hint) {
  return el('div', { class: 'field' }, el('label', { text: labelText }), control, hint ? el('div', { class: 'occ-note', text: hint }) : null);
}

function showError(text) {
  // With a dialog open, the dialog shows the refusal itself; the page's box would only scroll behind it.
  if (document.querySelector('.occ-modal.active')) return;
  const box = $id('occ-error');
  box.textContent = text;
  box.classList.remove('occ-hidden');
  box.scrollIntoView({ block: 'nearest' });
}

const clearError = () => $id('occ-error').classList.add('occ-hidden');

function flash(text) {
  const box = $id('occ-flash');
  box.textContent = text;
  box.classList.remove('occ-hidden');
  clearTimeout(flash.timer);
  flash.timer = setTimeout(() => box.classList.add('occ-hidden'), 3000);
}

const failText = (xhr, fallbackKey) => {
  const msg = xhr && xhr.responseJSON && xhr.responseJSON.message;
  return typeof msg === 'string' && msg !== '' ? msg : t(fallbackKey);
};

function get(action, data = {}) {
  return $.ajax({ url: BASE + action, method: 'GET', data, dataType: 'json' })
    .then((r) => (r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })))
    .fail((xhr) => showError(failText(xhr, 'occ_ErrLoad')));
}

/** Sends a change; shows the refusal and rejects when it fails. */
function post(action, data) {
  clearError();
  return $.ajax({ url: BASE + action, method: 'POST', data: JSON.stringify(data), contentType: 'application/json', dataType: 'json' })
    .then((r) => (r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })))
    .fail((xhr) => showError(failText(xhr, 'occ_ErrSave')));
}

/**
 * A Fomantic modal with a form. onSave gets nothing and returns a promise;
 * the dialog closes when it resolves and stays open (showing the refusal
 * inside) when it fails. Extra actions ({text, confirm, action}, such as
 * Delete) ask in a confirmation first and close this dialog when they succeed.
 */
function dialog(title, body, onSave, saveText = t('occ_Save'), extraActions = []) {
  const error = el('div', { class: 'ui negative message occ-hidden' });
  const fail = (xhr) => {
    clearError();
    error.textContent = failText(xhr, 'occ_ErrSave');
    error.classList.remove('occ-hidden');
  };
  const run = (action) => {
    error.classList.add('occ-hidden');
    return action().then(() => $(modal).modal('hide'), fail);
  };
  const extras = extraActions.map(({ text, confirm, action }) => button(text,
    () => confirmDialog(confirm, () => action().then(() => $(modal).modal('hide')), text), 'basic red occ-left'));
  const modal = el('div', { class: 'ui small modal occ-modal' },
    el('div', { class: 'header', text: title }),
    el('div', { class: 'scrolling content' }, error, el('div', { class: 'ui form' }, body)),
    el('div', { class: 'actions' },
      extras,
      button(t('occ_Cancel'), () => $(modal).modal('hide'), 'basic'),
      onSave ? button(saveText, () => run(onSave), 'primary') : null));
  return showModal(modal);
}

/**
 * Asks before something that can't be undone: the question, Cancel, and a
 * red button named after the action. A refusal is shown in the question.
 */
function confirmDialog(text, onYes, yesText) {
  const error = el('div', { class: 'ui negative message occ-hidden' });
  const modal = el('div', { class: 'ui mini modal occ-modal' },
    el('div', { class: 'content' }, error, el('p', { class: 'occ-question', text })),
    el('div', { class: 'actions' },
      button(t('occ_Cancel'), () => $(modal).modal('hide'), 'basic'),
      button(yesText, () => {
        error.classList.add('occ-hidden');
        return onYes().then(() => $(modal).modal('hide'), (xhr) => {
          clearError();
          error.textContent = failText(xhr, 'occ_ErrSave');
          error.classList.remove('occ-hidden');
        });
      }, 'negative')));
  return showModal(modal);
}

function showModal(modal) {
  document.body.append(modal);
  // allowMultiple: a confirmation opens over the dialog it came from.
  $(modal).modal({ autofocus: false, allowMultiple: true, onHidden: () => modal.remove() }).modal('show');
  return modal;
}

/* ------------------------------------------------------------------ time in the PBX's zone */

const pad = (n) => String(n).padStart(2, '0');

/** Epoch seconds as the PBX's wall clock: "YYYY-MM-DDTHH:mm:ss". */
function wall(epoch) {
  const parts = {};
  new Intl.DateTimeFormat('en-GB', {
    timeZone: state.options.timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(epoch * 1000)).forEach((p) => { parts[p.type] = p.value; });
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:00`;
}

/** A Date from FullCalendar (an instant) as the PBX's wall clock "Y-m-d H:i". */
const fromCalendar = (d) => wall(Math.round(d.getTime() / 1000)).replace('T', ' ').slice(0, 16);
/** Epoch seconds as the PBX's wall clock "Y-m-d H:i". */
const localOf = (epoch) => wall(epoch).replace('T', ' ').slice(0, 16);
/** "Y-m-d H:i" ⇄ the value of an <input type="datetime-local">. */
const toInput = (local) => local.replace(' ', 'T');
const fromInput = (value) => value.replace('T', ' ').slice(0, 16);

/** "Mon 14:00" or "Tue 3 Nov 14:00" for epoch seconds, in the PBX's zone. */
function when(epoch, withDate = false) {
  return new Date(epoch * 1000).toLocaleString(LOCALE, {
    timeZone: state.options.timezone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    ...(withDate ? { day: 'numeric', month: 'short' } : {}),
  });
}

function hours(seconds) {
  const h = Math.round(seconds / 360) / 10;
  return sub(t('occ_Hours'), { n: h.toLocaleString(LOCALE) });
}

/* ------------------------------------------------------------------ lookups */

const employeeName = (userId) => {
  const e = state.options.employees.find((x) => x.id === userId);
  return e ? `${e.name} (${e.extension})` : t('occ_UnknownPerson');
};

function destinationName(number) {
  if (!number) return '—';
  const d = state.options.destinations.find((x) => x.value === number);
  return d ? d.name : number;
}

const employeeOptions = () => state.options.employees.map((e) => [e.id, `${e.name} (${e.extension})`]);
const destinationOptions = () => state.options.destinations.map((d) => [d.value, d.name]);

/** Member ids of the open schedule → names, for calendar labels. */
function memberNames(memberIds, members) {
  return memberIds.map((id) => {
    const m = members.find((x) => x.id === id);
    return m ? (m.name || t('occ_UnknownPerson')) : '?';
  });
}

function memberColour(memberId, members) {
  const m = members.find((x) => x.id === memberId);
  return safeColour(m && m.colour);
}

/** A schedule member's employee id. */
const userOfMember = (memberId, members) => (members.find((m) => m.id === memberId) || {}).user_id;

/** An employee's colour in the open schedule; people who aren't members yet are grey. */
const colourOfUser = (userId) => safeColour(((state.detail && state.detail.members.find((m) => m.user_id === userId)) || {}).colour);

/** An employee as a person with their avatar. */
function employeePerson(userId, compact = false) {
  const e = state.options.employees.find((x) => x.id === userId);
  return e ? person(e.name, colourOfUser(userId), e.extension, compact) : person(t('occ_UnknownPerson'), '');
}

/** A member of the open schedule as a person with their avatar. */
function memberPerson(memberId, members) {
  const m = members.find((x) => x.id === memberId);
  return m ? person(m.name || t('occ_UnknownPerson'), m.colour, m.extension || '') : person('?', '');
}

/* ------------------------------------------------------------------ routing between views */

function route() {
  const hash = window.location.hash.replace(/^#\/?/, '');
  const [view, id, tab] = hash.split('/');
  clearError();
  // A dialog belongs to the view it was opened from (the browser's Back button can leave it).
  document.querySelectorAll('.occ-modal').forEach((m) => $(m).modal('hide'));
  if (state.calendar) {
    state.calendar.destroy();
    state.calendar = null;
  }
  if (view === 'schedule' && Number(id) > 0) {
    const same = state.detail && state.detail.schedule.id === Number(id);
    state.scheduleId = Number(id);
    state.tab = TABS.includes(tab) ? tab : 'calendar';
    // Switching tabs of the open schedule redraws from what is loaded; changes reload it.
    if (same) renderSchedule();
    else openSchedule();
  } else if (view === 'new' && can('createSchedule')) {
    showWizard();
  } else {
    showHome();
  }
}

const go = (hash) => {
  if (window.location.hash === hash) route();
  else window.location.hash = hash;
};

/* ------------------------------------------------------------------ home */

function showHome() {
  state.detail = null; // reopened schedules are loaded afresh
  const root = $id('occ-main');
  fill(root, loader());
  get('overview').then((data) => {
    const items = [...data.schedules].sort((a, b) => attention(a) - attention(b) || a.schedule.name.localeCompare(b.schedule.name, LOCALE));
    fill(root, el('div', { class: 'ui segment occ-panel' },
      el('div', { class: 'occ-section-head' },
        el('h4', { class: 'ui header' }, t('occ_Schedules'),
          el('span', { class: 'occ-subheader', text: sub(t('occ_PbxClock'), { time: when(data.now, true), zone: data.timezone }) })),
        el('div', { class: 'occ-section-actions' },
          can('createSchedule') ? button(t('occ_NewSchedule'), () => go('#/new'), 'primary small', 'plus') : null)),
      items.length ? el('div', { class: 'occ-grid' }, items.map(scheduleCard))
        : el('div', { class: 'occ-empty-state' },
          el('p', { class: 'occ-empty-title', text: t('occ_NoSchedules') }),
          el('p', { class: 'occ-note', text: t('occ_NoSchedulesHint') }))));
  }, () => fill(root)); // the error box says what failed
}

/** Lower comes first: schedules that need someone's attention lead the home page. */
function attention({ schedule, status, references }) {
  if (String(schedule.enabled) !== '1') return 4;
  if (status.gap_now) return 0;
  if (!references.length || status.gap_seconds > 0) return 1;
  return status.planned ? 3 : 2;
}

/** One line for a decision: who is ringing, or where calls go. */
function decisionText(decision, schedule, members) {
  if (!decision) return '—';
  if (decision.start === '1') {
    // Only the people who are rung: someone whose extension is gone is planned but skipped.
    const rung = decision.member_ids.filter((id) => (members.find((m) => m.id === id) || {}).extension);
    return memberNames(rung, members).join(', ');
  }
  if (decision.start === '2') {
    const backup = schedule.backup_type === 'queue' ? destinationName(schedule.backup_number)
      : schedule.backup_type === 'member' ? employeeName(schedule.backup_user_id) : destinationName(schedule.final_number);
    return sub(t('occ_GapGoesTo'), { target: backup });
  }
  if (decision.start === 'go') return sub(t('occ_SentTo'), { target: destinationName(decision.destination) });
  return sub(t('occ_OutsideGoesTo'), { target: destinationName(schedule.outside_number || schedule.final_number) });
}

/** A decision as people (avatars) when someone is rung, otherwise as a line of text. */
function decisionView(decision, schedule, members, withExtensions = true) {
  if (decision && decision.start === '1') {
    // Only the people who are rung: someone whose extension is gone is planned but skipped.
    const rung = decision.member_ids.map((id) => members.find((m) => m.id === id)).filter((m) => m && m.extension);
    if (rung.length) {
      return el('div', { class: 'occ-people' },
        rung.map((m) => person(m.name || t('occ_UnknownPerson'), m.colour, m.extension, !withExtensions)));
    }
  }
  return el('div', { class: 'occ-decision', text: decisionText(decision, schedule, members) });
}

function scheduleCard({ schedule, members, status, references }) {
  const now = status.now;
  const tone = now.start === '1' ? 'on' : now.start === '2' ? 'gap' : 'off';
  const href = `#/schedule/${schedule.id}/calendar`;
  const enabled = String(schedule.enabled) === '1';
  const coverage = status.gap_now ? tag('red', 'exclamation triangle', t('occ_GapNow'))
    : !status.planned ? tag('grey', 'calendar outline', t('occ_NothingPlanned'))
      : status.gap_seconds > 0 ? tag('orange', 'exclamation circle', sub(t('occ_GapsComing'), { hours: hours(status.gap_seconds) }), href)
        : tag('green', 'check circle', t('occ_Covered14'));
  const wiring = references.length
    ? el('span', { class: 'occ-meta' }, icon('sign-in alternate'), plural('occ_RoutedFrom', references.length))
    : el('span', { class: 'occ-meta occ-warn' }, icon('unlink'), t('occ_NotReachable'));

  return el('article', { class: `occ-card${enabled ? '' : ' occ-card-disabled'}` },
    el('header', { class: 'occ-card-title' },
      el('a', { class: 'occ-card-name', href, text: schedule.name }),
      el('div', { class: 'occ-sub-line' },
        `${schedule.number} · ${t(`occ_Template_${schedule.template || 'blank'}`)}`,
        enabled ? null : el('span', { class: 'ui mini grey label', text: t('occ_Disabled') }))),
    el('div', { class: `occ-now occ-now-${tone}` },
      el('div', { class: 'occ-now-head' },
        el('span', { class: 'occ-caption', text: t('occ_NowLabel') }),
        now.to ? el('span', { class: 'occ-meta', text: sub(t('occ_Until'), { time: when(now.to, true) }) }) : null),
      decisionView(now, schedule, members)),
    status.next ? el('div', { class: 'occ-next' },
      el('span', { class: 'occ-caption', text: t('occ_NextLabel') }),
      el('span', { class: 'occ-next-time', text: when(status.next.from, true) }),
      decisionView(status.next, schedule, members, false)) : null,
    el('footer', { class: 'occ-card-foot' }, coverage,
      el('div', { class: 'occ-card-row' }, wiring,
        can('addOverride') ? button(t('occ_CoverNow'), () => {
          state.pendingCover = { scheduleId: schedule.id, to: now.to };
          go(href);
        }, 'mini basic', 'user plus') : null)));
}

/* ------------------------------------------------------------------ wizard */

function showWizard() {
  state.detail = null;
  const o = state.options;
  const form = {
    template: 'shift', name: '', number: o.suggested_number, routeIds: [], ring: 20,
    backupType: o.queues.length ? 'queue' : '', backupNumber: (o.queues[0] || {}).number || '', backupUser: '', backupRing: 20, final: 'voicemail',
    people: [], handover: nextFriday(), turnDays: 7,
  };
  const root = $id('occ-main');
  const templatesBox = el('div', { class: 'occ-choices' });
  const peopleBox = el('div');
  const backupBox = el('div', { class: 'fields' });
  const oncallBox = el('div', { class: 'occ-hidden' });

  const templateIcons = { shift: 'clock outline', oncall: 'bell outline', blank: 'file outline' };
  const renderTemplates = () => fill(templatesBox, ['shift', 'oncall', 'blank'].map((tpl) => el('button', {
    type: 'button',
    class: 'occ-choice',
    'aria-pressed': form.template === tpl ? 'true' : 'false',
    onclick: () => {
      form.template = tpl;
      form.ring = tpl === 'oncall' ? 25 : 20;
      form.backupRing = form.ring;
      form.backupType = tpl === 'shift' && o.queues.length ? 'queue' : tpl === 'oncall' ? 'member' : '';
      $id('occ-w-ring').value = form.ring;
      renderTemplates();
      renderBackup();
      oncallBox.classList.toggle('occ-hidden', tpl !== 'oncall');
    },
  }, icon(templateIcons[tpl]), el('span', {},
    el('span', { class: 'occ-choice-title', text: t(`occ_Template_${tpl}`) }),
    el('span', { class: 'occ-choice-text', text: t(`occ_Template_${tpl}_Hint`) })))));

  const renderPeople = () => fill(peopleBox, peopleList(form.people, (list) => { form.people = list; renderPeople(); }));

  const renderBackup = () => {
    const typeSelect = select([['', t('occ_BackupNone')], ['member', t('occ_BackupPerson')], ['queue', t('occ_BackupQueue')]], form.backupType,
      { onchange: (e) => { form.backupType = e.target.value; renderBackup(); } });
    const target = form.backupType === 'queue'
      ? select(o.queues.map((q) => [q.number, `${q.name} (${q.number})`]), form.backupNumber, { onchange: (e) => { form.backupNumber = e.target.value; } })
      : form.backupType === 'member'
        ? select([['', '—'], ...employeeOptions()], form.backupUser, { onchange: (e) => { form.backupUser = e.target.value; } })
        : null;
    fill(backupBox,
      el('div', { class: 'six wide field' }, el('label', { text: t('occ_Backup') }), typeSelect),
      target ? el('div', { class: 'six wide field' }, el('label', { text: ' ' }), target) : null,
      form.backupType === 'member' ? el('div', { class: 'four wide field' }, el('label', { text: t('occ_RingSeconds') }),
        el('input', { type: 'number', min: 5, max: 300, value: form.backupRing, oninput: (e) => { form.backupRing = Number(e.target.value); } })) : null);
    if (form.backupType === 'queue' && !form.backupNumber && o.queues[0]) form.backupNumber = o.queues[0].number;
  };

  const routesBox = el('div', { class: 'grouped fields' }, o.routes.length ? o.routes.map((r) => el('div', { class: 'field' },
    el('div', { class: 'ui checkbox' },
      el('input', { type: 'checkbox', id: `occ-route-${r.id}`, disabled: r.linked_schedule_id !== null,
        onchange: (e) => { form.routeIds = e.target.checked ? [...form.routeIds, r.id] : form.routeIds.filter((x) => x !== r.id); } }),
      el('label', { for: `occ-route-${r.id}`, text: routeLabel(r) })))) : el('p', { class: 'occ-note', text: t('occ_NoRoutes') }));

  fill(oncallBox,
    el('h4', { class: 'ui dividing header occ-step', text: t('occ_WizardWho') }),
    el('p', { class: 'occ-note', text: t('occ_WizardWhoOncall') }),
    peopleBox,
    el('div', { class: 'fields' },
      el('div', { class: 'eight wide field' }, el('label', { text: t('occ_FirstHandover') }),
        el('input', { type: 'datetime-local', value: toInput(form.handover), oninput: (e) => { form.handover = fromInput(e.target.value); } })),
      el('div', { class: 'four wide field' }, el('label', { text: t('occ_TurnDays') }),
        el('input', { type: 'number', min: 1, max: 60, value: form.turnDays, oninput: (e) => { form.turnDays = Number(e.target.value); } }))));

  fill(root,
    el('div', { class: 'ui segment occ-panel' },
      el('div', { class: 'occ-editor-head' },
        backButton(),
        el('h3', { class: 'ui header', text: t('occ_WizardTitle') })),
      el('div', { class: 'ui form occ-wizard' },
      el('h4', { class: 'ui dividing header occ-step', text: t('occ_WizardWhat') }),
      templatesBox,
      el('div', { class: 'fields' },
        el('div', { class: 'ten wide field' }, el('label', { text: t('occ_Name') }),
          el('input', { type: 'text', maxlength: 60, placeholder: t('occ_NamePlaceholder'), oninput: (e) => { form.name = e.target.value; } })),
        el('div', { class: 'six wide field' }, el('label', { text: t('occ_Number') }),
          el('input', { type: 'text', inputmode: 'numeric', value: form.number, oninput: (e) => { form.number = e.target.value.trim(); } }))),
      el('div', { class: 'occ-note', text: t('occ_NumberHint') }),
      el('h4', { class: 'ui dividing header occ-step', text: t('occ_WizardCalls') }),
      el('p', { class: 'occ-note', text: t('occ_WizardCallsHint') }),
      routesBox,
      oncallBox,
      el('h4', { class: 'ui dividing header occ-step', text: t('occ_ChainTitle') }),
      el('p', { class: 'occ-note', text: t('occ_ChainHint') }),
      el('div', { class: 'fields' },
        el('div', { class: 'five wide field' }, el('label', { text: t('occ_RingOnDuty') }),
          el('input', { id: 'occ-w-ring', type: 'number', min: 5, max: 300, value: form.ring, oninput: (e) => { form.ring = Number(e.target.value); } }))),
      backupBox,
      field(t('occ_Final'), select(destinationOptions(), form.final, { onchange: (e) => { form.final = e.target.value; } }), t('occ_FinalHint')),
      el('div', { class: 'occ-form-actions' },
        button(t('occ_Create'), () => {
          const data = {
            name: form.name, number: form.number, template: form.template, ring_seconds: form.ring,
            backup_type: form.backupType, backup_number: form.backupNumber, backup_user_id: Number(form.backupUser) || 0,
            backup_ring_seconds: form.backupRing, final_number: form.final, route_ids: form.routeIds,
            shift_names: [t('occ_ShiftMorning'), t('occ_ShiftAfternoon')],
          };
          if (form.template === 'oncall' && form.people.length) {
            data.rotation = { user_ids: form.people, first_handover: form.handover, turn_days: form.turnDays, active_hours: o.oncall_hours };
          }
          return post('createSchedule', data).then((r) => {
            if (r.link_errors && r.link_errors.length) state.notice = r.link_errors.join(' ');
            return refreshOptions().then(() => go(`#/schedule/${r.id}/${form.template === 'oncall' ? 'rotation' : form.template === 'shift' ? 'roster' : 'settings'}`));
          });
        }, 'primary', 'check'),
        button(t('occ_Cancel'), () => go('#/'), 'basic')))));
  renderTemplates();
  renderPeople();
  renderBackup();
}

function nextFriday() {
  const now = new Date(wall(Math.floor(Date.now() / 1000) + state.clockOffset) + 'Z');
  // The coming Friday 17:00; on a Friday after 17:00, the next one.
  const add = (5 - now.getUTCDay() + 7) % 7 || (now.getUTCHours() >= 17 ? 7 : 0);
  now.setUTCDate(now.getUTCDate() + add);
  return `${now.getUTCFullYear()}-${pad(now.getUTCMonth() + 1)}-${pad(now.getUTCDate())} 17:00`;
}

function routeLabel(r) {
  const name = r.rulename || r.number || `#${r.id}`;
  return `${name}${r.number && r.rulename ? ` (${r.number})` : ''} → ${destinationName(r.extension)}`;
}

/** An ordered list of employees with up, down and remove, and a picker to add one. */
function peopleList(userIds, onChange) {
  const add = select([['', t('occ_AddPerson')], ...employeeOptions().filter(([id]) => !userIds.includes(id))], '', {
    onchange: (e) => { if (e.target.value) onChange([...userIds, Number(e.target.value)]); },
  });
  const move = (i, d) => { const l = [...userIds]; [l[i], l[i + d]] = [l[i + d], l[i]]; onChange(l); };
  return el('div', { class: 'occ-people-editor' },
    userIds.length ? el('ol', { class: 'occ-list' }, userIds.map((id, i) => el('li', {},
      employeePerson(id),
      el('span', { class: 'occ-icon-actions' },
        i > 0 ? iconButton(t('occ_MoveUp'), () => move(i, -1), 'arrow up') : null,
        i < userIds.length - 1 ? iconButton(t('occ_MoveDown'), () => move(i, 1), 'arrow down') : null,
        iconButton(t('occ_Remove'), () => onChange(userIds.filter((x) => x !== id)), 'times'))))) : null,
    el('div', { class: 'field' }, add));
}

/* ------------------------------------------------------------------ schedule view */

function openSchedule() {
  const root = $id('occ-main');
  fill(root, loader());
  loadDetail().then(() => {
    renderSchedule();
    if (state.notice) {
      showError(state.notice);
      state.notice = '';
    }
  }, () => fill(root, el('div', { class: 'occ-editor-head' }, backButton())));
}

const loadDetail = () => get('schedule', { id: state.scheduleId }).then((d) => { state.detail = d; });

function renderSchedule() {
  const { schedule } = state.detail;
  const body = el('div', { class: 'ui bottom attached segment occ-tab' });
  const tabLink = (tab) => el('a', { class: `item${state.tab === tab ? ' active' : ''}`, href: `#/schedule/${schedule.id}/${tab}` },
    tab === 'settings' ? icon('cog') : null, t(`occ_Tab_${tab}`));
  const tabs = el('div', { class: 'ui top attached tabular menu occ-tabs', onscroll: () => fadeTabs(tabs) },
    TABS.filter((tab) => tab !== 'settings').map(tabLink),
    el('div', { class: 'right menu' }, tabLink('settings')));
  fill($id('occ-main'),
    el('div', { class: 'occ-editor-head' },
      backButton(),
      el('h3', { class: 'ui header', text: `${schedule.name} · ${schedule.number}` }),
      String(schedule.enabled) === '1' ? null : el('span', { class: 'ui small grey label', text: t('occ_Disabled') })),
    tabs,
    body);
  fadeTabs(tabs);
  ({ calendar: renderCalendar, roster: renderRoster, rotation: renderRotation, settings: renderSettings, activity: renderActivity })[state.tab](body);
}

/** Fades the tab bar's right edge while more tabs are scrolled out of view, as the cabinet's other module pages do. */
function fadeTabs(tabs) {
  const overflow = tabs.scrollWidth > tabs.clientWidth + 1;
  tabs.classList.toggle('occ-overflow', overflow);
  tabs.classList.toggle('occ-at-end', overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1);
}

/** Reloads the schedule after a change and redraws the current tab. */
const refresh = () => loadDetail().then(renderSchedule);

/* ------------------------------------------------------------------ calendar tab */

function renderCalendar(body) {
  const holder = el('div', { class: 'occ-calendar' });
  const legend = (kind, key) => el('span', { class: 'occ-legend-item' }, el('span', { class: `occ-swatch occ-swatch-${kind}` }), t(key));
  fill(body,
    el('div', { class: 'occ-cal-meta' },
      el('p', { class: 'occ-note' }, icon('info circle'), can('addOverride') ? t('occ_CalendarHint') : t('occ_ReadOnlyHint')),
      el('div', { class: 'occ-legend' }, legend('plan', 'occ_LegendPlan'), legend('override', 'occ_LegendOverride'), legend('gap', 'occ_LegendGap'))),
    holder);
  const { schedule } = state.detail;
  const calendar = new FullCalendar.Calendar(holder, {
    plugins: FullCalendar.plugins,
    locales: FullCalendar.locales,
    locale: LOCALE && LOCALE.startsWith('ru') ? 'ru' : 'en',
    timeZone: state.options.timezone,
    now: () => Date.now() + state.clockOffset * 1000,
    firstDay: 1,
    // A phone is too narrow for seven day columns: it starts on the list.
    initialView: window.matchMedia('(max-width: 767px)').matches ? 'listWeek' : schedule.template === 'oncall' ? 'dayGridMonth' : 'timeGridWeek',
    headerToolbar: { start: 'prev,next today', center: 'title', end: 'dayGridMonth,timeGridWeek,listWeek' },
    toolbarTitleClass: 'occ-cal-title',
    columnEventTitleClass: 'occ-ev-title', // lets long titles wrap in the week view
    contentHeight: 620,
    allDaySlot: false,
    nowIndicator: true,
    slotDuration: '00:30:00',
    scrollTime: '07:00:00',
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
    slotHeaderFormat: { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' },
    dayMaxEvents: 3,
    selectable: can('addOverride'),
    selectMirror: true,
    events: (info, success, failure) => {
      // The PBX's dates of the visible range (the server works in its own zone).
      const from = localOf(Math.floor(info.start.getTime() / 1000)).slice(0, 10);
      const to = localOf(Math.floor(info.end.getTime() / 1000)).slice(0, 10);
      // Members are read when the events arrive: a change may have added someone.
      get('calendar', { id: schedule.id, from, to }).then((data) => success(calendarEvents(data, schedule, state.detail.members)), failure);
    },
    select: (info) => {
      calendar.unselect();
      overrideDialog({ start_at: fromCalendar(info.start), end_at: fromCalendar(info.end) });
    },
    eventClick: (info) => {
      const p = info.event.extendedProps;
      if (!can('addOverride') && !can('updateOverride')) return;
      if (p.override) overrideDialog(p.override);
      else if (p.segment) segmentDialog(p.segment);
    },
    eventDrop: (info) => moveOverride(info),
    eventResize: (info) => moveOverride(info),
  });
  state.calendar = calendar;
  calendar.render();
  const pending = state.pendingCover;
  state.pendingCover = null;
  if (pending && pending.scheduleId === schedule.id) {
    // From now (the PBX's clock) to the end of the current stretch, or an hour if that has passed meanwhile.
    const now = Math.floor(Date.now() / 1000) + state.clockOffset;
    coverDialog(now, pending.to > now ? pending.to : now + 3600);
  }
}

/**
 * The calendar's events: planned stretches (lightly tinted in the person's
 * colour), overrides (solid, draggable), and gaps (striped background with a
 * label). Times outside covered hours are left empty.
 */
function calendarEvents(data, schedule, members) {
  const events = [];
  data.segments.forEach((s, i) => {
    if (s.start === 'out') return;
    const range = { start: s.from * 1000, end: s.to * 1000 };
    const gap = { ...range, display: 'background', className: 'occ-ev-gap', color: 'transparent' };
    if (s.decided_by === 'override') {
      // The override is drawn from its own row; mark it when nobody in it can be rung.
      if (s.start === '2') events.push({ ...gap, id: `ogap-${i}` });
      return;
    }
    if (s.start === '2') {
      events.push({ ...gap, id: `gap-${i}` });
      events.push({ ...range, id: `gapl-${i}`, title: decisionText(s, schedule, members), editable: false, className: 'occ-ev-gap-label',
        color: 'transparent', contrastColor: 'var(--occ-bad)', extendedProps: { segment: s } });
      return;
    }
    const colour = memberColour(s.member_ids[0], members);
    events.push({
      ...range, id: `seg-${i}`, title: decisionText(s, schedule, members), editable: false,
      color: tint(colour), contrastColor: 'var(--occ-text)', extendedProps: { segment: s, colour },
    });
  });
  data.overrides.forEach((o) => {
    const colour = o.kind === 'elsewhere' ? 'var(--occ-muted)' : memberColour(o.members[0], members);
    const who = o.kind === 'elsewhere' ? destinationName(o.destination) : memberNames(o.members, members).join(', ');
    events.push({
      id: `ovr-${o.id}`, start: toInput(o.start_at), end: toInput(o.end_at), title: `${t(`occ_Kind_${o.kind}`)}: ${who}${o.note ? ` · ${o.note}` : ''}`,
      color: colour, contrastColor: '#fff', editable: can('updateOverride'), extendedProps: { override: o },
    });
  });
  return events;
}

/** A person's colour, lightened for planned (not overridden) stretches. */
const tint = (colour) => `color-mix(in srgb, ${colour} 22%, white)`;

function moveOverride(info) {
  const o = info.event.extendedProps.override;
  post('updateOverride', {
    id: state.scheduleId, override_id: o.id,
    override: { start_at: fromCalendar(info.event.start), end_at: fromCalendar(info.event.end || info.event.start) },
  }).then(calendarChanged, () => info.revert());
}

/** A planned stretch was clicked: cover it, swap it or send its calls elsewhere. */
function segmentDialog(s) {
  const range = { start_at: localOf(s.from), end_at: localOf(s.to) };
  const { members } = state.detail;
  const userIds = s.member_ids.map((m) => userOfMember(m, members)).filter(Boolean);
  const modal = dialog(`${when(s.from, true)} – ${when(s.to, true)}`,
    el('div', {},
      el('p', { text: decisionText(s, state.detail.schedule, members) }),
      el('div', { class: 'ui vertical fluid buttons' },
        button(t('occ_CoverEllipsis'), () => { $(modal).modal('hide'); overrideDialog({ ...range, kind: 'cover' }); }, 'basic', 'user'),
        userIds.length === 1 ? button(t('occ_SwapEllipsis'), () => { $(modal).modal('hide'); swapDialog(range, userIds[0]); }, 'basic', 'exchange') : null,
        button(t('occ_ElsewhereEllipsis'), () => { $(modal).modal('hide'); overrideDialog({ ...range, kind: 'elsewhere' }); }, 'basic', 'share'))),
    null);
}

/** Cover from now until the end of the current stretch (the home page's "Cover now"). */
function coverDialog(fromEpoch, toEpoch) {
  overrideDialog({ kind: 'cover', start_at: localOf(fromEpoch), end_at: localOf(toEpoch) });
}

/** Adds an override, or edits one (with an id). */
function overrideDialog(override) {
  const existing = Boolean(override.id);
  const { schedule, members } = state.detail;
  const form = {
    kind: override.kind || 'cover',
    start: override.start_at, end: override.end_at,
    users: existing ? (override.members || []).map((m) => userOfMember(m, members)).filter(Boolean) : [],
    destination: override.destination || state.detail.elsewhere_targets[0] || '',
    note: override.note || '',
  };
  const whoBox = el('div');
  const renderWho = () => fill(whoBox, form.kind === 'elsewhere'
    ? field(t('occ_SendTo'), select(state.detail.elsewhere_targets.map((n) => [n, destinationName(n)]), form.destination,
      { onchange: (e) => { form.destination = e.target.value; } }), t('occ_SendToHint'))
    : field(t('occ_WhoAnswers'), peopleList(form.users, (list) => { form.users = list; renderWho(); }), t('occ_RingTogether')));
  const kinds = existing ? [[form.kind, t(`occ_Kind_${form.kind}`)]]
    : [['cover', t('occ_Kind_cover')], ['elsewhere', t('occ_Kind_elsewhere')]];
  const body = el('div', {},
    field(t('occ_OverrideKind'), select(kinds, form.kind, { disabled: existing, onchange: (e) => { form.kind = e.target.value; renderWho(); } })),
    el('div', { class: 'two fields' },
      field(t('occ_From'), el('input', { type: 'datetime-local', value: toInput(form.start), oninput: (e) => { form.start = fromInput(e.target.value); } })),
      field(t('occ_To'), el('input', { type: 'datetime-local', value: toInput(form.end), oninput: (e) => { form.end = fromInput(e.target.value); } }))),
    whoBox,
    field(t('occ_Note'), el('input', { type: 'text', maxlength: 200, value: form.note, oninput: (e) => { form.note = e.target.value; } })));
  renderWho();
  const payload = () => ({ kind: form.kind, start_at: form.start, end_at: form.end, user_ids: form.users, destination: form.destination, note: form.note });
  const done = calendarChanged;
  dialog(existing ? t(`occ_Kind_${form.kind}`) : t('occ_NewOverride'), body,
    () => (existing
      ? post('updateOverride', { id: schedule.id, override_id: override.id, override: payload() })
      : post('addOverride', { id: schedule.id, override: payload() })).then(done),
    t('occ_Save'),
    existing ? [{ text: t('occ_DeleteOverride'), confirm: t('occ_DeleteOverrideConfirm'), action: () => post('deleteOverride', { id: schedule.id, override_id: override.id }).then(done) }] : []);
}

/** Swaps a planned stretch with another person's stretch. */
function swapDialog(rangeA, userA) {
  const form = { userB: '', startB: '', endB: '', note: '' };
  const body = el('div', {},
    el('p', { text: sub(t('occ_SwapIntro'), { who: employeeName(userA), from: rangeA.start_at, to: rangeA.end_at }) }),
    field(t('occ_SwapWith'), select([['', '—'], ...employeeOptions().filter(([id]) => id !== userA)], '', { onchange: (e) => { form.userB = e.target.value; } })),
    el('div', { class: 'two fields' },
      field(t('occ_TheirShiftFrom'), el('input', { type: 'datetime-local', oninput: (e) => { form.startB = fromInput(e.target.value); } })),
      field(t('occ_To'), el('input', { type: 'datetime-local', oninput: (e) => { form.endB = fromInput(e.target.value); } }))),
    el('div', { class: 'occ-note', text: t('occ_SwapHint') }),
    field(t('occ_Note'), el('input', { type: 'text', maxlength: 200, oninput: (e) => { form.note = e.target.value; } })));
  dialog(t('occ_Kind_swap'), body, () => post('addSwap', {
    id: state.scheduleId,
    swap: { user_a: userA, start_a: rangeA.start_at, end_a: rangeA.end_at, user_b: Number(form.userB), start_b: form.startB, end_b: form.endB, note: form.note },
  }).then(calendarChanged));
}

/** After an override change: reload the schedule (it may have new members), then the calendar's events. */
function calendarChanged() {
  flash(t('occ_Saved'));
  return loadDetail().then(() => { if (state.calendar) state.calendar.refetchEvents(); });
}

/* ------------------------------------------------------------------ roster tab */

function renderRoster(body) {
  const { schedule, rosters, shift_types: shifts, members } = state.detail;
  if (!rosters.find((r) => r.id === state.rosterId)) {
    state.rosterId = rosters.length ? rosters[0].id : 0;
    state.rosterWeek = 1;
  }
  const roster = rosters.find((r) => r.id === state.rosterId);
  const addRoster = () => rosterDialog({ valid_from: state.options.today, valid_until: '', cycle_weeks: 1 });

  if (!roster) {
    fill(body, el('p', { text: t('occ_NoRoster') }), can('saveRoster') ? button(t('occ_AddRoster'), addRoster, 'primary', 'plus') : null);
    return;
  }
  if (state.rosterWeek > roster.cycle_weeks) state.rosterWeek = 1;
  const week = state.rosterWeek;
  const usersIn = (shiftId, weekday) => roster.cells.filter((c) => c.shift_type_id === shiftId && c.week === week && c.weekday === weekday)
    .map((c) => userOfMember(c.member_id, members)).filter(Boolean);

  const editable = can('setCell');
  const rows = shifts.map((shift, i) => el('tr', {},
    el('th', { class: 'occ-shift', scope: 'row' },
      el('div', { class: 'occ-shift-name', text: shift.name }),
      el('div', { class: 'occ-shift-time' }, icon('clock outline'), `${shift.start}–${shift.end}`),
      can('saveShiftType') ? el('div', { class: 'occ-icon-actions' },
        i > 0 ? iconButton(t('occ_MoveUp'), () => orderShift(i, -1), 'arrow up') : null,
        i < shifts.length - 1 ? iconButton(t('occ_MoveDown'), () => orderShift(i, 1), 'arrow down') : null,
        iconButton(t('occ_EditShiftType'), () => shiftDialog(shift), 'pencil'),
        iconButton(t('occ_DeleteShift'), () => confirmDialog(sub(t('occ_DeleteShiftConfirm'), { name: shift.name }),
          () => post('deleteShiftType', { id: schedule.id, shift_type_id: shift.id }).then(refresh), t('occ_DeleteShift')), 'trash')) : null),
    WEEKDAYS.map((d) => {
      if (!shift.weekdays.includes(d)) return el('td', { class: 'occ-cell occ-cell-off' });
      const users = usersIn(shift.id, d);
      const open = () => cellDialog(roster, shift, week, d, users);
      const cls = ['occ-cell', editable && 'occ-cell-editable', !users.length && 'occ-cell-empty'].filter(Boolean).join(' ');
      return el('td', {
        class: cls,
        title: editable ? t('occ_EditCell') : null,
        tabindex: editable ? '0' : null,
        onclick: editable ? open : null,
        onkeydown: editable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } } : null,
      }, users.length ? el('div', { class: 'occ-people occ-people-stack' }, users.map((u) => employeePerson(u, true)))
        : el('span', { class: 'occ-cell-empty-text', text: t('occ_EmptyCell') }));
    })));

  fill(body,
    rosters.length > 1 ? el('div', { class: 'ui secondary menu occ-submenu' }, rosters.map((r) => el('a', {
      class: `item${r.id === roster.id ? ' active' : ''}`, onclick: () => { state.rosterId = r.id; state.rosterWeek = 1; renderSchedule(); },
      text: rosterLabel(r),
    }))) : null,
    sectionHead(rosterLabel(roster),
      can('saveRoster') ? button(t('occ_EditRoster'), () => rosterDialog(roster), 'small basic', 'pencil') : null,
      can('saveShiftType') ? button(t('occ_AddShiftType'), () => shiftDialog({ name: '', start: '08:00', end: '17:00', weekdays: [1, 2, 3, 4, 5] }), 'small basic', 'plus') : null,
      can('saveRoster') ? button(t('occ_AddSeasonalRoster'), addRoster, 'small basic', 'calendar plus') : null),
    roster.cycle_weeks > 1 ? el('div', { class: 'ui secondary menu occ-submenu' }, Array.from({ length: roster.cycle_weeks }, (_, i) => el('a', {
      class: `item${week === i + 1 ? ' active' : ''}`, onclick: () => { state.rosterWeek = i + 1; renderSchedule(); },
      text: sub(t('occ_WeekN'), { n: i + 1 }),
    }))) : null,
    shifts.length ? el('div', { class: 'occ-table-wrap' }, el('table', { class: 'occ-roster' },
      el('thead', {}, el('tr', {}, el('th', { scope: 'col', text: t('occ_ShiftType') }), WEEKDAYS.map((d) => el('th', { scope: 'col', text: weekdayName(d) })))),
      el('tbody', {}, rows))) : el('p', { class: 'occ-note', text: t('occ_NoShiftTypes') }),
    el('p', { class: 'occ-note occ-below', text: t('occ_RosterHint') }));

  function orderShift(i, d) {
    const ids = shifts.map((s) => s.id);
    [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
    post('orderShiftTypes', { id: schedule.id, ids }).then(refresh);
  }
}

function rosterLabel(r) {
  const until = r.valid_until ? sub(t('occ_RosterUntil'), { date: r.valid_until }) : t('occ_RosterNoEnd');
  const cycle = r.cycle_weeks > 1 ? ` · ${plural('occ_CycleWeeks', r.cycle_weeks)}` : '';
  return `${sub(t('occ_RosterFrom'), { date: r.valid_from })} ${until}${cycle}`;
}

function cellDialog(roster, shift, week, weekday, users) {
  let list = [...users];
  const box = el('div');
  const render = () => fill(box, peopleList(list, (l) => { list = l; render(); }));
  render();
  dialog(`${shift.name} · ${weekdayName(weekday)}${roster.cycle_weeks > 1 ? ` · ${sub(t('occ_WeekN'), { n: week })}` : ''}`,
    el('div', {}, el('p', { class: 'occ-note', text: t('occ_RingTogether') }), box),
    () => post('setCell', { id: state.scheduleId, roster_id: roster.id, shift_type_id: shift.id, week, weekday, user_ids: list }).then(refresh));
}

function shiftDialog(shift) {
  const form = { ...shift, weekdays: [...shift.weekdays] };
  const days = el('div', { class: 'inline fields' }, WEEKDAYS.map((d) => el('div', { class: 'field' }, el('div', { class: 'ui checkbox' },
    el('input', { type: 'checkbox', id: `occ-wd-${d}`, checked: form.weekdays.includes(d),
      onchange: (e) => { form.weekdays = e.target.checked ? [...form.weekdays, d] : form.weekdays.filter((x) => x !== d); } }),
    el('label', { for: `occ-wd-${d}`, text: weekdayName(d) })))));
  dialog(shift.id ? t('occ_EditShiftType') : t('occ_AddShiftType'), el('div', {},
    field(t('occ_Name'), el('input', { type: 'text', maxlength: 60, value: form.name, oninput: (e) => { form.name = e.target.value; } })),
    el('div', { class: 'two fields' },
      field(t('occ_Starts'), el('input', { type: 'time', value: form.start, oninput: (e) => { form.start = e.target.value; } })),
      field(t('occ_Ends'), el('input', { type: 'time', value: form.end === '24:00' ? '00:00' : form.end, oninput: (e) => { form.end = e.target.value; } }))),
    el('div', { class: 'occ-note', text: t('occ_OvernightHint') }),
    el('label', { class: 'occ-caption', text: t('occ_Weekdays') }), days),
  () => post('saveShiftType', { id: state.scheduleId, shift: form }).then(refresh));
}

function rosterDialog(roster) {
  const form = { ...roster };
  dialog(roster.id ? t('occ_RosterDates') : t('occ_AddRoster'), el('div', {},
    el('div', { class: 'two fields' },
      field(t('occ_ValidFrom'), el('input', { type: 'date', value: form.valid_from, oninput: (e) => { form.valid_from = e.target.value; } })),
      field(t('occ_ValidUntil'), el('input', { type: 'date', value: form.valid_until, oninput: (e) => { form.valid_until = e.target.value; } }), t('occ_ValidUntilHint'))),
    field(t('occ_Cycle'), select([1, 2, 3, 4].map((n) => [n, plural('occ_CycleWeeks', n)]), form.cycle_weeks,
      { onchange: (e) => { form.cycle_weeks = Number(e.target.value); } }), t('occ_CycleHint'))),
  () => post('saveRoster', { id: state.scheduleId, roster: form }).then((r) => { state.rosterId = r.id; return refresh(); }),
  t('occ_Save'),
  roster.id ? [{ text: t('occ_DeleteRoster'), confirm: t('occ_DeleteRosterConfirm'), action: () => post('deleteRoster', { id: state.scheduleId, roster_id: roster.id }).then(refresh) }] : []);
}

/* ------------------------------------------------------------------ rotation tab */

function renderRotation(body) {
  const { rotations, members } = state.detail;
  const add = () => rotationForm(body, {
    user_ids: [], first_handover: nextFriday(), turn_days: 7, active_hours: state.options.oncall_hours, valid_until: '',
  });
  if (!rotations.length) {
    fill(body, el('p', { text: t('occ_NoRotation') }), can('saveRotation') ? button(t('occ_AddRotation'), add, 'primary', 'plus') : null);
    return;
  }
  fill(body,
    rotations.map((r) => el('section', { class: 'occ-section' },
      el('div', { class: 'occ-section-head' },
        el('div', { class: 'occ-chain' }, r.members.flatMap((m, i) => [i ? icon('angle right occ-chain-arrow') : null, memberPerson(m, members)])),
        can('saveRotation') ? button(t('occ_Edit'), () => rotationForm(body, { ...r, user_ids: r.members.map((m) => userOfMember(m, members)).filter(Boolean) }), 'mini basic', 'pencil') : null),
      el('p', { class: 'occ-note', text: plural('occ_RotationSummary', r.turn_days, { at: r.first_handover }) }),
      preview(r))),
    can('saveRotation') ? el('div', { class: 'occ-actions' }, button(t('occ_AddRotation'), add, 'basic', 'plus')) : null);
}

/** The current turn and the next handovers of a rotation, in the PBX's wall clock. */
function preview(r) {
  const { members } = state.detail;
  if (!r.members.length) return null;
  const first = new Date(`${toInput(r.first_handover)}:00Z`);
  const now = new Date(`${wall(Math.floor(Date.now() / 1000) + state.clockOffset)}Z`);
  const step = r.turn_days * 86400000;
  const last = r.valid_until ? new Date(`${r.valid_until}T23:59:00Z`) : null;
  let k = Math.max(0, Math.floor((now - first) / step));
  const rows = [];
  for (let i = 0; i < 8; i++, k++) {
    const start = new Date(first.getTime() + k * step);
    if (last && start > last) break;
    const current = i === 0 && start <= now;
    rows.push(el('li', { class: current ? 'occ-current' : null },
      el('span', { class: 'occ-timeline-when', text: start.toLocaleString(LOCALE, { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }) }),
      memberPerson(r.members[k % r.members.length], members),
      current ? tag('green', null, t('occ_CurrentTurn')) : null));
  }
  return el('div', { class: 'occ-below' },
    el('div', { class: 'occ-caption', text: t('occ_Handovers') }),
    el('ol', { class: 'occ-timeline' }, rows));
}

function rotationForm(body, rotation) {
  const form = { ...rotation, active_hours: (rotation.active_hours || []).map((w) => ({ ...w, weekdays: [...w.weekdays] })) };
  const peopleBox = el('div');
  const hoursBox = el('div');
  const renderPeople = () => fill(peopleBox, peopleList(form.user_ids, (l) => { form.user_ids = l; renderPeople(); }));
  const renderHours = () => fill(hoursBox,
    form.active_hours.length ? form.active_hours.map((w, i) => el('div', { class: 'ui segment occ-window' },
      el('div', { class: 'inline fields' }, WEEKDAYS.map((d) => el('div', { class: 'field' }, el('div', { class: 'ui checkbox' },
        el('input', { type: 'checkbox', id: `occ-ah-${i}-${d}`, checked: w.weekdays.includes(d),
          onchange: (e) => { w.weekdays = e.target.checked ? [...w.weekdays, d] : w.weekdays.filter((x) => x !== d); } }),
        el('label', { for: `occ-ah-${i}-${d}`, text: weekdayName(d) }))))),
      el('div', { class: 'inline fields' },
        field(t('occ_Starts'), el('input', { type: 'time', value: w.start, oninput: (e) => { w.start = e.target.value; } })),
        field(t('occ_Ends'), el('input', { type: 'time', value: w.end, oninput: (e) => { w.end = e.target.value; } })),
        iconButton(t('occ_Remove'), () => { form.active_hours.splice(i, 1); renderHours(); }, 'trash'))))
      : el('p', { class: 'occ-note', text: t('occ_AlwaysActive') }),
    el('div', {},
      button(t('occ_AddWindow'), () => { form.active_hours.push({ weekdays: [1, 2, 3, 4, 5], start: '18:00', end: '08:00' }); renderHours(); }, 'mini basic', 'plus'),
      button(t('occ_PresetAfterHours'), () => { form.active_hours = state.options.oncall_hours.map((w) => ({ ...w, weekdays: [...w.weekdays] })); renderHours(); }, 'mini basic'),
      button(t('occ_PresetAlways'), () => { form.active_hours = []; renderHours(); }, 'mini basic')));
  fill(body, el('div', { class: 'ui form' },
    sectionHead(t('occ_RotationPeople')),
    peopleBox,
    el('div', { class: 'three fields' },
      field(t('occ_FirstHandover'), el('input', { type: 'datetime-local', value: toInput(form.first_handover), oninput: (e) => { form.first_handover = fromInput(e.target.value); } }), t('occ_FirstHandoverHint')),
      field(t('occ_TurnDays'), el('input', { type: 'number', min: 1, max: 60, value: form.turn_days, oninput: (e) => { form.turn_days = Number(e.target.value); } })),
      field(t('occ_ValidUntil'), el('input', { type: 'date', value: form.valid_until, oninput: (e) => { form.valid_until = e.target.value; } }), t('occ_ValidUntilHint'))),
    sectionHead(t('occ_ActiveHours')),
    el('p', { class: 'occ-note', text: t('occ_ActiveHoursHint') }),
    hoursBox,
    el('div', { class: 'occ-form-actions' },
      button(t('occ_Save'), () => post('saveRotation', { id: state.scheduleId, rotation: form }).then(() => { flash(t('occ_Saved')); refresh(); }), 'primary'),
      button(t('occ_Cancel'), () => renderSchedule(), 'basic'),
      form.id ? button(t('occ_DeleteRotation'), () => confirmDialog(t('occ_DeleteRotationConfirm'),
        () => post('deleteRotation', { id: state.scheduleId, rotation_id: form.id }).then(refresh), t('occ_DeleteRotation')), 'basic red') : null)));
  renderPeople();
  renderHours();
}

/* ------------------------------------------------------------------ settings tab */

function renderSettings(body) {
  const d = state.detail;
  const s = d.schedule;
  const o = state.options;
  const form = {
    name: s.name, enabled: String(s.enabled) === '1', ring_seconds: s.ring_seconds, backup_type: s.backup_type, backup_number: s.backup_number,
    backup_user_id: s.backup_user_id || '', backup_ring_seconds: s.backup_ring_seconds, final_number: s.final_number,
    outside_number: s.outside_number, greeting_sound_id: s.greeting_sound_id || '', callerid_prefix: s.callerid_prefix || '',
  };
  const backupBox = el('div', { class: 'fields' });
  const renderBackup = () => fill(backupBox,
    el('div', { class: 'six wide field' }, el('label', { text: t('occ_Backup') }),
      select([['', t('occ_BackupNone')], ['member', t('occ_BackupPerson')], ['queue', t('occ_BackupQueue')]], form.backup_type,
        { onchange: (e) => { form.backup_type = e.target.value; renderBackup(); } })),
    form.backup_type === 'queue' ? el('div', { class: 'six wide field' }, el('label', { text: ' ' }),
      select([['', '—'], ...o.queues.map((q) => [q.number, `${q.name} (${q.number})`])], form.backup_number, { onchange: (e) => { form.backup_number = e.target.value; } }),
      el('div', { class: 'occ-note', text: t('occ_QueueBackupHint') })) : null,
    form.backup_type === 'member' ? el('div', { class: 'six wide field' }, el('label', { text: ' ' }),
      select([['', '—'], ...employeeOptions()], form.backup_user_id, { onchange: (e) => { form.backup_user_id = e.target.value; } })) : null,
    form.backup_type === 'member' ? el('div', { class: 'four wide field' }, el('label', { text: t('occ_RingSeconds') }),
      el('input', { type: 'number', min: 5, max: 300, value: form.backup_ring_seconds, oninput: (e) => { form.backup_ring_seconds = Number(e.target.value); } })) : null);
  renderBackup();

  const testFrom = select([['', t('occ_TestCallFrom')], ...o.employees.map((e) => [e.extension, `${e.name} (${e.extension})`])], '', {});
  const linked = d.routes.filter((r) => r.linked_schedule_id === s.id);
  const free = d.routes.filter((r) => r.linked_schedule_id === null && r.extension !== s.number);
  const routeAdd = select([['', t('occ_LinkRoutePick')], ...free.map((r) => [r.id, routeLabel(r)])], '', {});
  const others = d.references.filter((r) => !(r.kind === 'route' && linked.some((l) => l.id === r.id)));
  const shortTimeout = d.routes.filter((r) => r.extension === s.number && r.timeout > 0 && r.timeout < d.needed_timeout);

  const rules = [...d.rosters.map((r) => ({ type: 'roster', id: r.id, priority: r.priority, label: `${t('occ_Roster')}: ${rosterLabel(r)}`, rule: r })),
    ...d.rotations.map((r) => ({ type: 'rotation', id: r.id, priority: r.priority, label: `${t('occ_Rotation')}: ${memberNames(r.members, d.members).join(' → ')}`, rule: r }))]
    .sort((a, b) => a.priority - b.priority);
  const moveRule = (i, dir) => {
    const list = [...rules];
    [list[i], list[i + dir]] = [list[i + dir], list[i]];
    post('orderRules', { id: s.id, rules: list.map((r) => ({ type: r.type, id: r.id })) }).then(refresh);
  };

  const destinations = destinationOptions().filter(([v]) => v !== s.number);
  fill(body,
    el('div', { class: 'ui form' },
      section(t('occ_General'), null,
        el('div', { class: 'fields' },
          el('div', { class: 'ten wide field' }, el('label', { text: t('occ_Name') }),
            el('input', { type: 'text', maxlength: 60, value: form.name, oninput: (e) => { form.name = e.target.value; } })),
          el('div', { class: 'six wide field' }, el('label', { text: t('occ_Number') }), el('input', { type: 'text', value: s.number, disabled: true }))),
        el('div', { class: 'field' }, el('div', { class: 'ui toggle checkbox' },
          el('input', { type: 'checkbox', id: 'occ-enabled', checked: form.enabled, onchange: (e) => { form.enabled = e.target.checked; } }),
          el('label', { for: 'occ-enabled', text: t('occ_EnabledLabel') })),
          el('div', { class: 'occ-note', text: sub(t('occ_EnabledHint'), { number: s.number }) }))),
      section(t('occ_ChainTitle'), t('occ_ChainHint'),
        el('ol', { class: 'occ-steps' },
          el('li', {}, el('div', { class: 'fields' }, el('div', { class: 'five wide field' }, el('label', { text: t('occ_RingOnDuty') }),
            el('input', { type: 'number', min: 5, max: 300, value: form.ring_seconds, oninput: (e) => { form.ring_seconds = Number(e.target.value); } })))),
          el('li', {}, backupBox),
          el('li', {}, field(t('occ_Final'), select(destinations, form.final_number, { onchange: (e) => { form.final_number = e.target.value; } }), t('occ_FinalHint')))),
        field(t('occ_Outside'), select([['', t('occ_SameAsFinal')], ...destinations], form.outside_number,
          { onchange: (e) => { form.outside_number = e.target.value; } }), t('occ_OutsideHint'))),
      section(t('occ_CallerSection'), null,
        el('div', { class: 'two fields' },
          field(t('occ_Greeting'), select([['', t('occ_NoGreeting')], ...o.sounds.map((x) => [x.id, x.name])], form.greeting_sound_id,
            { onchange: (e) => { form.greeting_sound_id = e.target.value; } }), t('occ_GreetingHint')),
          field(t('occ_CallerIdPrefix'), el('input', { type: 'text', maxlength: 20, value: form.callerid_prefix,
            oninput: (e) => { form.callerid_prefix = e.target.value; } }), t('occ_CallerIdPrefixHint')))),
      can('updateSchedule') ? el('div', { class: 'occ-settings-save' },
        button(t('occ_Save'), () => post('updateSchedule', { id: s.id, ...form, backup_user_id: Number(form.backup_user_id) || 0 })
          .then(() => { flash(t('occ_Saved')); refreshOptions().then(refresh); }), 'primary')) : el('p', { class: 'occ-note', text: t('occ_ReadOnlyHint') })),
    el('div', { class: 'ui divider occ-settings-divider' }),

    can('testCall') ? section(t('occ_TestCall'), t('occ_TestCallHint'),
      el('div', { class: 'ui action input occ-inline-action' }, testFrom,
        button(t('occ_TestCallStart'), () => testFrom.value && post('testCall', { id: s.id, extension: testFrom.value })
          .then(() => flash(sub(t('occ_TestCallStarted'), { ext: testFrom.value }))), 'basic', 'phone'))) : null,

    section(t('occ_LinkedRoutes'), sub(t('occ_LinkedRoutesHint'), { n: d.needed_timeout }),
      linked.length ? el('table', { class: 'ui very basic table occ-table' }, el('tbody', {}, linked.map((r) => el('tr', {},
        el('td', {}, icon('sign-in alternate occ-muted-icon'), routeLabel(r)),
        el('td', {}, r.changed_outside ? tag('orange', 'exclamation circle', t('occ_ChangedOutside')) : null),
        el('td', { class: 'right aligned' }, can('unlinkRoute') && button(t('occ_Unlink'), () => post('unlinkRoute', { id: s.id, route_id: r.id })
          .then((res) => { flash(res.restored ? t('occ_Restored') : t('occ_ForgotLink')); refreshOptions().then(refresh); }), 'mini basic', 'unlink')))))) : el('p', { class: 'occ-empty', text: t('occ_NoLinkedRoutes') }),
      can('linkRoute') && el('div', { class: 'ui action input occ-inline-action' }, routeAdd,
        button(t('occ_Link'), () => routeAdd.value && post('linkRoute', { id: s.id, route_id: Number(routeAdd.value) })
          .then(() => { flash(t('occ_Saved')); refreshOptions().then(refresh); }), 'basic', 'linkify')),
      shortTimeout.length ? el('div', { class: 'ui warning message', text: sub(t('occ_ShortTimeout'), { n: d.needed_timeout, routes: shortTimeout.map(routeLabel).join('; ') }) }) : null,
      others.length ? el('div', { class: 'occ-below' },
        el('p', { class: 'occ-note', text: t('occ_OtherReferences') }),
        el('ul', { class: 'occ-plain-list' }, others.map((r) => el('li', { text: `${t(`occ_RefKind_${r.kind}`)} "${r.name}"` })))) : null),

    rules.length ? section(t('occ_RuleBackups'), t('occ_RuleBackupsHint'), rules.map((r) => ruleBackupRow(r, s))) : null,

    rules.length > 1 ? section(t('occ_RuleOrder'), t('occ_RuleOrderHint'),
      el('ol', { class: 'occ-list' }, rules.map((r, i) => el('li', {}, el('span', { text: r.label }),
        can('orderRules') ? el('span', { class: 'occ-icon-actions' },
          i > 0 ? iconButton(t('occ_MoveUp'), () => moveRule(i, -1), 'arrow up') : null,
          i < rules.length - 1 ? iconButton(t('occ_MoveDown'), () => moveRule(i, 1), 'arrow down') : null) : null)))) : null,

    can('deleteSchedule') ? section(t('occ_DangerZone'), null,
      button(t('occ_DeleteSchedule'), () => confirmDialog(sub(t('occ_DeleteScheduleConfirm'), { name: s.name, number: s.number }),
        () => post('deleteSchedule', { id: s.id }).then(() => { flash(t('occ_Deleted')); refreshOptions().then(() => go('#/')); }), t('occ_DeleteSchedule')), 'basic red', 'trash')) : null);
}

/** One roster's or rotation's own backup, under Settings. */
function ruleBackupRow(r, schedule) {
  const o = state.options;
  const form = {
    backup_type: r.rule.backup_type || '', backup_number: r.rule.backup_number || (o.queues[0] || {}).number || '',
    backup_user_id: r.rule.backup_user_id || '', backup_ring_seconds: r.rule.backup_ring_seconds || 20,
  };
  const editable = can('setRuleBackup');
  const box = el('div', { class: 'fields' });
  const render = () => fill(box,
    el('div', { class: 'six wide field' },
      select([['', t('occ_BackupSameAsSchedule')], ['none', t('occ_BackupNone')], ['member', t('occ_BackupPerson')], ['queue', t('occ_BackupQueue')]],
        form.backup_type, { disabled: !editable, onchange: (e) => { form.backup_type = e.target.value; render(); } })),
    form.backup_type === 'queue' ? el('div', { class: 'five wide field' },
      select(o.queues.map((q) => [q.number, `${q.name} (${q.number})`]), form.backup_number,
        { disabled: !editable, onchange: (e) => { form.backup_number = e.target.value; } })) : null,
    form.backup_type === 'member' ? el('div', { class: 'five wide field' },
      select([['', '—'], ...employeeOptions()], form.backup_user_id, { disabled: !editable, onchange: (e) => { form.backup_user_id = e.target.value; } })) : null,
    form.backup_type === 'member' ? el('div', { class: 'two wide field' },
      el('input', { type: 'number', min: 5, max: 300, value: form.backup_ring_seconds, disabled: !editable,
        title: t('occ_RingSeconds'), oninput: (e) => { form.backup_ring_seconds = Number(e.target.value); } })) : null,
    editable ? el('div', { class: 'three wide field' }, button(t('occ_Save'), () => post('setRuleBackup', {
      id: schedule.id, rule: r.type, rule_id: r.id, backup_type: form.backup_type, backup_number: form.backup_number,
      backup_user_id: Number(form.backup_user_id) || 0, backup_ring_seconds: form.backup_ring_seconds,
    }).then(() => { flash(t('occ_Saved')); refresh(); }), 'basic')) : null);
  render();
  return el('div', { class: 'ui form occ-rule-backup' }, el('label', { class: 'occ-caption', text: r.label }), box);
}

/* ------------------------------------------------------------------ activity tab */

function renderActivity(body) {
  const reportBox = el('section', { class: 'occ-section' });
  const logBox = el('div', {}, loader());
  fill(body, reportBox, section(t('occ_ChangeLog'), null, logBox));
  renderReport(reportBox);
  get('audit', { id: state.scheduleId }).then((data) => {
    const history = auditHistory(data.entries);
    fill(logBox, data.entries.length ? el('table', { class: 'ui very basic table occ-table occ-audit' },
      el('thead', {}, el('tr', {}, el('th', { text: t('occ_When') }), el('th', { text: t('occ_Who') }), el('th', { text: t('occ_Change') }))),
      el('tbody', {}, data.entries.map((e) => el('tr', {},
        el('td', { text: when(e.at, true) }),
        el('td', { text: e.actor || '—' }),
        el('td', {}, el('div', { text: t(`occ_Audit_${e.action.replace('.', '_')}`) }), auditDetail(e, history))))))
      : el('p', { class: 'occ-empty', text: t('occ_NoActivity') }));
  }, () => fill(logBox));
}

/** The activity report: how calls ended and who answered, for a date range (default: the last 30 days). */
function renderReport(box) {
  const today = state.options.today;
  const shift = (iso, days) => { const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10); };
  const range = { from: shift(today, -29), to: today };
  const result = el('div');
  const load = () => get('report', { id: state.scheduleId, from: range.from, to: shift(range.to, 1) }).then((data) => {
    const r = data.report;
    const share = (n) => (r.calls ? `${Math.round((100 * n) / r.calls)}%` : '');
    const secs = (s) => (s === null || s === undefined ? '—' : sub(t('occ_Seconds'), { n: s }));
    // KPI cards as the cabinet's other dashboards draw them: label, value, then a muted line.
    const tile = (kind, value, label, note = '') => el('div', { class: 'occ-kpi' },
      el('div', { class: 'occ-kpi-label' }, kind ? el('span', { class: `occ-dot occ-dot-${kind}`, 'aria-hidden': 'true' }) : null, label),
      el('div', { class: `occ-kpi-value${value === '—' ? ' occ-empty-value' : ''}`, text: value }),
      note ? el('div', { class: 'occ-kpi-sub', text: note }) : null);
    // The person's colour in this schedule, when they are a member.
    const colourOf = (p) => (state.detail.members.find((m) => p.extension && m.extension === p.extension) || {}).colour;
    fill(result,
      el('div', { class: 'occ-kpis' },
        tile('', String(r.calls), t('occ_ReportCalls')),
        ['on_duty', 'other', 'voicemail', 'unanswered'].map((k) => tile(k, String(r.outcomes[k] || 0), t(`occ_Outcome_${k}`), share(r.outcomes[k] || 0))),
        tile('', secs(r.median_wait), t('occ_MedianWaitColumn'))),
      r.people.length ? el('table', { class: 'ui very basic table occ-table' },
        el('thead', {}, el('tr', {}, [t('occ_Person'), t('occ_HoursOnDuty'), t('occ_Answered'), t('occ_AnsweredOnDuty'), t('occ_MedianWaitColumn')]
          .map((h, i) => el('th', { class: i ? 'occ-num' : null, text: h })))),
        el('tbody', {}, r.people.map((p) => el('tr', {},
          el('td', {}, person(p.name || t('occ_UnknownPerson'), colourOf(p), p.extension || '')),
          el('td', { class: 'occ-num', text: p.hours.toLocaleString(LOCALE) }),
          el('td', { class: 'occ-num', text: String(p.answered) }),
          el('td', { class: 'occ-num', text: String(p.answered_on_duty) }),
          el('td', { class: 'occ-num', text: secs(p.median_wait) })))))
        : el('p', { class: 'occ-empty', text: t('occ_NoReportPeople') }),
      data.calls.length ? el('div', { class: 'occ-actions' }, button(t('occ_ExportCsv'), () => downloadCsv(data.calls, range), 'mini basic', 'download')) : null);
  });
  // A period preset and the dates, as on the cabinet's other report pages; any change reloads.
  const fromInputEl = el('input', { type: 'date', value: range.from, 'aria-label': t('occ_From'),
    onchange: (e) => { range.from = e.target.value; preset.value = 'custom'; load(); } });
  const toInputEl = el('input', { type: 'date', value: range.to, 'aria-label': t('occ_To'),
    onchange: (e) => { range.to = e.target.value; preset.value = 'custom'; load(); } });
  const preset = select([['7', t('occ_PresetLast7')], ['30', t('occ_PresetLast30')], ['90', t('occ_PresetLast90')], ['custom', t('occ_PresetCustom')]], '30', {
    'aria-label': t('occ_Period'),
    onchange: (e) => {
      if (e.target.value === 'custom') return;
      range.from = shift(today, 1 - Number(e.target.value));
      range.to = today;
      fromInputEl.value = range.from;
      toInputEl.value = range.to;
      load();
    },
  });
  fill(box,
    sectionHead(t('occ_Report'), el('div', { class: 'ui form occ-range' }, preset, fromInputEl,
      el('span', { class: 'occ-range-sep', 'aria-hidden': 'true', text: '–' }), toInputEl)),
    el('p', { class: 'occ-note', text: t('occ_ReportHint') }),
    result);
  load();
}

/** The calls of the report as a CSV file (times in the PBX's zone). */
function downloadCsv(calls, range) {
  const cell = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const lines = [[t('occ_When'), t('occ_CsvRouting'), t('occ_OnDuty'), t('occ_CsvOutcome'), t('occ_CsvAnsweredBy'), t('occ_CsvWait')].map(cell).join(',')];
  calls.forEach((c) => lines.push([wall(c.started_at).replace('T', ' '), t(`occ_Start_${c.start}`), c.on_duty, t(`occ_Outcome_${c.outcome}`),
    c.answered_by || '', c.wait_seconds ?? ''].map(cell).join(',')));
  const blob = new Blob([`\ufeff${lines.join('\r\n')}\r\n`], { type: 'text/csv;charset=utf-8' });
  const link = el('a', { href: URL.createObjectURL(blob), download: `on-call-${state.detail.schedule.number}-${range.from}-${range.to}.csv` });
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
}

/** "1,2,3" or [1, 2, 3] as a list of numbers. */
const idList = (v) => (Array.isArray(v) ? v : String(v ?? '').split(',')).map(Number).filter(Boolean);

/** Rotation active hours (JSON) as "Mon Tue 18:00–08:00; Sat Sun 00:00–00:00". */
function windowsText(v) {
  let list = v;
  try { if (typeof v === 'string') list = JSON.parse(v || '[]'); } catch (err) { return String(v); }
  if (!Array.isArray(list) || !list.length) return t('occ_PresetAlways');
  return list.map((w) => `${idList(w.weekdays).map(weekdayName).join(' ')} ${w.start}–${w.end}`).join('; ');
}

/**
 * The fields of an audit entry people can read, in the page's own words:
 * [label key, how to show a value]. Ids, order and bookkeeping are left out.
 */
function auditFields(action, history) {
  const rule = /^(roster|rotation|rule)\./.test(action);
  const backupType = (v) => (!v ? t(rule ? 'occ_BackupSameAsSchedule' : 'occ_BackupNone')
    : { none: t('occ_BackupNone'), member: t('occ_BackupPerson'), queue: t('occ_BackupQueue') }[v] || v);
  const members = (v) => idList(v).map((id) => {
    const m = state.detail.members.find((x) => x.id === id);
    if (m) return m.name || t('occ_UnknownPerson');
    return history.memberUsers[id] ? employeeName(history.memberUsers[id]) : t('occ_UnknownPerson');
  }).join(', ');
  const employees = (v) => idList(v).map(employeeName).join(', ');
  return {
    name: ['occ_Name'],
    number: ['occ_Number'],
    enabled: ['occ_Status', (v) => (String(v) === '1' ? t('occ_EnabledLabel') : t('occ_Disabled'))],
    ring_seconds: ['occ_RingOnDuty'],
    backup_type: ['occ_Backup', backupType],
    backup_number: ['occ_Backup', destinationName],
    backup_user_id: ['occ_Backup', (v) => (v ? employeeName(Number(v)) : '')],
    backup_ring_seconds: ['occ_RingSeconds'],
    final_number: ['occ_Final', destinationName],
    outside_number: ['occ_Outside', (v) => (v ? destinationName(v) : t('occ_SameAsFinal'))],
    greeting_sound_id: ['occ_Greeting', (v) => (v ? ((state.options.sounds.find((x) => String(x.id) === String(v)) || {}).name || v) : t('occ_NoGreeting'))],
    callerid_prefix: ['occ_CallerIdPrefix'],
    start_time: ['occ_Starts'],
    end_time: ['occ_Ends'],
    weekdays: ['occ_Weekdays', (v) => idList(v).map(weekdayName).join(' ')],
    valid_from: ['occ_ValidFrom'],
    valid_until: ['occ_ValidUntil'],
    cycle_weeks: ['occ_Cycle', (v) => plural('occ_CycleWeeks', Number(v))],
    kind: ['occ_OverrideKind', (v) => t(`occ_Kind_${v}`)],
    start_at: ['occ_From'],
    end_at: ['occ_To'],
    members: [action.startsWith('rotation.') ? 'occ_RotationPeople' : 'occ_WhoAnswers', members],
    first_handover: ['occ_FirstHandover'],
    turn_days: ['occ_TurnDays'],
    active_hours: ['occ_ActiveHours', windowsText],
    destination: ['occ_SendTo', destinationName],
    note: ['occ_Note'],
    user_ids: ['occ_WhoAnswers', employees],
    user_id: ['occ_Person', (v) => employeeName(Number(v))],
    extension: ['occ_Destination', destinationName],
    timeout: ['occ_Timeout'],
  };
}

/**
 * Names the log needs that may be gone from the schedule since: who a removed
 * member was, and what a deleted shift was called.
 */
function auditHistory(entries) {
  const memberUsers = {};
  const shiftNames = {};
  entries.forEach((e) => [e.before, e.after].forEach((row) => {
    if (!row || typeof row !== 'object' || Array.isArray(row)) return;
    if (e.action === 'member.delete' && row.id) memberUsers[row.id] = row.user_id;
    if (e.action.startsWith('shift_type.') && row.id && row.name) shiftNames[row.id] = row.name;
  }));
  return { memberUsers, shiftNames };
}

/**
 * What an audit entry changed, one line per field: "Label: before → after",
 * just the value for something added or deleted. A roster cell names its
 * shift and weekday first.
 */
function auditDetail(e, history) {
  const plain = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : null);
  const before = plain(e.before);
  const after = plain(e.after);
  if (!before && !after) return null;
  const fields = auditFields(e.action, history);
  const show = (key, v) => {
    if (v === undefined) return '—';
    const text = fields[key][1] ? fields[key][1](v) : v;
    return text === null || text === undefined || text === '' ? '—' : String(text);
  };
  const lines = [];
  if (e.action === 'roster.cell') {
    const cell = after || before;
    const shift = (state.detail.shift_types.find((s) => s.id === cell.shift_type_id) || {}).name || history.shiftNames[cell.shift_type_id] || '?';
    lines.push(`${shift} · ${weekdayName(cell.weekday)}${cell.week > 1 ? ` · ${sub(t('occ_WeekN'), { n: cell.week })}` : ''}`);
  }
  if (e.action.startsWith('route.')) {
    const id = (after || before).route_id;
    const r = state.detail.routes.find((x) => x.id === id);
    lines.push(r ? (r.rulename || r.number || `#${id}`) : `#${id}`);
  }
  Object.keys(fields).forEach((key) => {
    const was = before ? before[key] : undefined;
    const now = after ? after[key] : undefined;
    if (was === undefined && now === undefined) return;
    if (before && after && JSON.stringify(was) === JSON.stringify(now)) return;
    const label = t(fields[key][0]);
    if (before && after) lines.push(`${label}: ${show(key, was)} → ${show(key, now)}`);
    // Added or deleted: the queue or person names the backup, so its type would say it twice.
    else if (key === 'backup_type' && ['member', 'queue'].includes(after ? now : was)) return;
    else if (show(key, after ? now : was) !== '—') lines.push(`${label}: ${show(key, after ? now : was)}`);
  });
  return lines.length ? el('div', { class: 'occ-note' }, lines.slice(0, 8).map((text) => el('div', { text }))) : null;
}

/* ------------------------------------------------------------------ start */

const refreshOptions = () => get('options').then((o) => {
  state.options = o;
  state.clockOffset = o.now - Math.floor(Date.now() / 1000);
});

$(() => {
  refreshOptions().then(() => {
    window.addEventListener('hashchange', route);
    window.addEventListener('resize', () => { const tabs = document.querySelector('.occ-tabs'); if (tabs) fadeTabs(tabs); });
    route();
  });
});
