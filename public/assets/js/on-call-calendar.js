/* On Call Calendar for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */
(() => {
  // public/assets/js/src/on-call-calendar.js
  var BASE = `${globalRootUrl}module-on-call-calendar/`;
  var t = (key) => globalTranslate && globalTranslate[key] || key;
  var sub = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.split(`%${k}%`).join(String(v)), text);
  var LOCALE = document.documentElement.lang || void 0;
  var WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
  var TABS = ["calendar", "roster", "rotation", "settings", "activity"];
  var weekdayName = (d) => t(`occ_Weekday${d}`);
  var can = (action) => Boolean(state.options && state.options.can && state.options.can[action]);
  var state = {
    options: null,
    // optionsAction
    detail: null,
    // scheduleAction for the open schedule
    scheduleId: 0,
    tab: "calendar",
    rosterId: 0,
    rosterWeek: 1,
    calendar: null,
    pendingCover: null,
    // "Cover now" from the home page: {scheduleId, to}, opened once that schedule's calendar is shown
    clockOffset: 0,
    // PBX clock minus the browser's, in seconds
    notice: ""
    // a warning to show once the next view has loaded
  };
  var $id = (id) => document.getElementById(id);
  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === void 0 || v === null || v === false) return;
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
      else if (v === true) node.setAttribute(k, "");
      else node.setAttribute(k, v);
    });
    children.flat().forEach((c) => {
      if (c !== null && c !== void 0 && c !== false) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    });
    return node;
  }
  var fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c !== null && c !== void 0 && c !== false));
  function button(text, onclick, cls = "", icon = "") {
    const node = el("button", {
      type: "button",
      class: `ui ${cls} button`,
      onclick: (e) => {
        if (node.classList.contains("loading")) return;
        const result = onclick(e);
        if (result && typeof result.then === "function") {
          node.classList.add("loading", "disabled");
          const done = () => node.classList.remove("loading", "disabled");
          result.then(done, done);
        }
      }
    }, icon ? el("i", { class: `${icon} icon` }) : null, text);
    return node;
  }
  function select(options, value, attrs = {}) {
    const current = String(value != null ? value : "");
    const list = current !== "" && !options.some(([v]) => String(v) === current) ? [[current, sub(t("occ_Missing"), { value: current })], ...options] : options;
    return el(
      "select",
      { class: "ui dropdown", ...attrs },
      list.map(([v, text]) => el("option", { value: String(v), selected: String(v) === current }, text))
    );
  }
  function field(labelText, control, hint) {
    return el("div", { class: "field" }, el("label", { text: labelText }), control, hint ? el("div", { class: "occ-hint", text: hint }) : null);
  }
  function showError(text) {
    if (document.querySelector(".occ-modal.active")) return;
    const box = $id("occ-error");
    box.textContent = text;
    box.classList.remove("occ-hidden");
    box.scrollIntoView({ block: "nearest" });
  }
  var clearError = () => $id("occ-error").classList.add("occ-hidden");
  function flash(text) {
    const box = $id("occ-flash");
    box.textContent = text;
    box.classList.remove("occ-hidden");
    clearTimeout(flash.timer);
    flash.timer = setTimeout(() => box.classList.add("occ-hidden"), 3e3);
  }
  var failText = (xhr, fallbackKey) => {
    const msg = xhr && xhr.responseJSON && xhr.responseJSON.message;
    return typeof msg === "string" && msg !== "" ? msg : t(fallbackKey);
  };
  function get(action, data = {}) {
    return $.ajax({ url: BASE + action, method: "GET", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })).fail((xhr) => showError(failText(xhr, "occ_ErrLoad")));
  }
  function post(action, data) {
    clearError();
    return $.ajax({ url: BASE + action, method: "POST", data: JSON.stringify(data), contentType: "application/json", dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })).fail((xhr) => showError(failText(xhr, "occ_ErrSave")));
  }
  function dialog(title, body, onSave, saveText = t("occ_Save"), extraActions = []) {
    const error = el("div", { class: "ui negative message occ-hidden" });
    const fail = (xhr) => {
      clearError();
      error.textContent = failText(xhr, "occ_ErrSave");
      error.classList.remove("occ-hidden");
    };
    const run = (action) => {
      error.classList.add("occ-hidden");
      return action().then(() => $(modal).modal("hide"), fail);
    };
    const extras = extraActions.map(({ text, action }) => {
      let armed = false;
      const node = button(text, () => {
        if (!armed) {
          armed = true;
          node.lastChild.textContent = t("occ_ConfirmDelete");
          return null;
        }
        return run(action);
      }, "basic red");
      return node;
    });
    const modal = el(
      "div",
      { class: "ui small modal occ-modal" },
      el("div", { class: "header", text: title }),
      el("div", { class: "scrolling content" }, error, el("div", { class: "ui form" }, body)),
      el(
        "div",
        { class: "actions" },
        extras,
        button(t("occ_Cancel"), () => $(modal).modal("hide"), "basic"),
        onSave ? button(saveText, () => run(onSave), "primary") : null
      )
    );
    document.body.append(modal);
    $(modal).modal({ autofocus: false, allowMultiple: true, onHidden: () => modal.remove() }).modal("show");
    return modal;
  }
  function confirmDialog(text, onYes, yesText = t("occ_Delete")) {
    return dialog(t("occ_AreYouSure"), el("p", { text }), onYes, yesText);
  }
  var pad = (n) => String(n).padStart(2, "0");
  function wall(epoch) {
    const parts = {};
    new Intl.DateTimeFormat("en-GB", {
      timeZone: state.options.timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23"
    }).formatToParts(new Date(epoch * 1e3)).forEach((p) => {
      parts[p.type] = p.value;
    });
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:00`;
  }
  var fromCalendar = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
  var toInput = (local) => local.replace(" ", "T");
  var fromInput = (value) => value.replace("T", " ").slice(0, 16);
  function when(epoch, withDate = false) {
    return new Date(epoch * 1e3).toLocaleString(LOCALE, {
      timeZone: state.options.timezone,
      weekday: "short",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
      ...withDate ? { day: "numeric", month: "short" } : {}
    });
  }
  function hours(seconds) {
    const h = Math.round(seconds / 360) / 10;
    return sub(t("occ_Hours"), { n: h.toLocaleString(LOCALE) });
  }
  var employeeName = (userId) => {
    const e = state.options.employees.find((x) => x.id === userId);
    return e ? `${e.name} (${e.extension})` : t("occ_UnknownPerson");
  };
  function destinationName(number) {
    if (!number) return "\u2014";
    const d = state.options.destinations.find((x) => x.value === number);
    return d ? d.name : number;
  }
  var employeeOptions = () => state.options.employees.map((e) => [e.id, `${e.name} (${e.extension})`]);
  var destinationOptions = () => state.options.destinations.map((d) => [d.value, d.name]);
  function memberNames(memberIds, members) {
    return memberIds.map((id) => {
      const m = members.find((x) => x.id === id);
      return m ? m.name || t("occ_UnknownPerson") : "?";
    });
  }
  function memberColour(memberId, members) {
    const m = members.find((x) => x.id === memberId);
    return m && m.colour || "#767676";
  }
  var userOfMember = (memberId, members) => (members.find((m) => m.id === memberId) || {}).user_id;
  function route() {
    const hash = window.location.hash.replace(/^#\/?/, "");
    const [view, id, tab] = hash.split("/");
    clearError();
    if (state.calendar) {
      state.calendar.destroy();
      state.calendar = null;
    }
    if (view === "schedule" && Number(id) > 0) {
      const same = state.detail && state.detail.schedule.id === Number(id);
      state.scheduleId = Number(id);
      state.tab = TABS.includes(tab) ? tab : "calendar";
      if (same) renderSchedule();
      else openSchedule();
    } else if (view === "new" && can("createSchedule")) {
      showWizard();
    } else {
      showHome();
    }
  }
  var go = (hash) => {
    if (window.location.hash === hash) route();
    else window.location.hash = hash;
  };
  function showHome() {
    state.detail = null;
    const root = $id("occ-main");
    fill(root, el("div", { class: "ui active centered inline loader" }));
    get("overview").then((data) => {
      const cards = data.schedules.map(scheduleCard);
      fill(
        root,
        el(
          "div",
          { class: "occ-toolbar" },
          can("createSchedule") ? button(t("occ_NewSchedule"), () => go("#/new"), "primary", "plus") : el("span"),
          el("span", { class: "occ-clock", text: sub(t("occ_PbxClock"), { time: when(data.now, true), zone: data.timezone }) })
        ),
        cards.length ? el("div", { class: "ui stackable two cards occ-cards" }, cards) : el(
          "div",
          { class: "ui placeholder segment" },
          el("div", { class: "ui icon header" }, el("i", { class: "calendar alternate outline icon" }), t("occ_NoSchedules")),
          el("p", { class: "occ-center", text: t("occ_NoSchedulesHint") }),
          can("createSchedule") ? el("div", { class: "occ-center" }, button(t("occ_NewSchedule"), () => go("#/new"), "primary", "plus")) : null
        )
      );
    });
  }
  function decisionText(decision, schedule, members) {
    if (!decision) return "\u2014";
    if (decision.start === "1") {
      const rung = decision.member_ids.filter((id) => (members.find((m) => m.id === id) || {}).extension);
      return memberNames(rung, members).join(", ");
    }
    if (decision.start === "2") {
      const backup = schedule.backup_type === "queue" ? destinationName(schedule.backup_number) : schedule.backup_type === "member" ? employeeName(schedule.backup_user_id) : destinationName(schedule.final_number);
      return sub(t("occ_GapGoesTo"), { target: backup });
    }
    if (decision.start === "go") return sub(t("occ_SentTo"), { target: destinationName(decision.destination) });
    return sub(t("occ_OutsideGoesTo"), { target: destinationName(schedule.outside_number || schedule.final_number) });
  }
  function scheduleCard({ schedule, members, status, references }) {
    const now = status.now;
    const nowClass = now.start === "1" ? "occ-now-on" : now.start === "2" ? "occ-now-gap" : "occ-now-off";
    const coverage = status.gap_now ? el("span", { class: "ui red label", text: t("occ_GapNow") }) : !status.planned ? el("span", { class: "ui grey label", text: t("occ_NothingPlanned") }) : status.gap_seconds > 0 ? el("a", { class: "ui orange label", href: `#/schedule/${schedule.id}/calendar`, text: sub(t("occ_GapsComing"), { hours: hours(status.gap_seconds) }) }) : el("span", { class: "ui green label", text: t("occ_Covered14") });
    const wiring = references.length ? el("span", { class: "ui basic label", text: sub(t("occ_RoutedFrom"), { n: references.length }) }) : el("span", { class: "ui basic orange label", text: t("occ_NotReachable") });
    return el(
      "div",
      { class: "card" },
      el(
        "div",
        { class: "content" },
        el("div", { class: "header" }, el("a", { href: `#/schedule/${schedule.id}/calendar`, text: `${schedule.name} \xB7 ${schedule.number}` })),
        el("div", { class: "meta" }, t(`occ_Template_${schedule.template || "blank"}`), String(schedule.enabled) === "1" ? null : ` \xB7 ${t("occ_Disabled")}`),
        el(
          "div",
          { class: `description occ-now ${nowClass}` },
          el("div", { class: "occ-label", text: t("occ_NowLabel") }),
          el("div", { text: decisionText(now, schedule, members) }),
          now.to ? el("div", { class: "occ-hint", text: sub(t("occ_Until"), { time: when(now.to, true) }) }) : null
        ),
        status.next ? el(
          "div",
          { class: "occ-next" },
          el("span", { class: "occ-label", text: t("occ_NextLabel") }),
          " ",
          sub(t("occ_NextAt"), { time: when(status.next.from, true), who: decisionText(status.next, schedule, members) })
        ) : null
      ),
      el(
        "div",
        { class: "extra content" },
        coverage,
        wiring,
        el(
          "span",
          { class: "right floated" },
          can("addOverride") ? button(t("occ_CoverNow"), () => {
            state.pendingCover = { scheduleId: schedule.id, to: now.to };
            go(`#/schedule/${schedule.id}/calendar`);
          }, "mini basic") : null
        )
      )
    );
  }
  function showWizard() {
    state.detail = null;
    const o = state.options;
    const form = {
      template: "shift",
      name: "",
      number: o.suggested_number,
      routeIds: [],
      ring: 20,
      backupType: o.queues.length ? "queue" : "",
      backupNumber: (o.queues[0] || {}).number || "",
      backupUser: "",
      backupRing: 20,
      final: "voicemail",
      people: [],
      handover: nextFriday(),
      turnDays: 7
    };
    const root = $id("occ-main");
    const templatesBox = el("div", { class: "ui three stackable cards occ-templates" });
    const peopleBox = el("div");
    const backupBox = el("div", { class: "fields" });
    const oncallBox = el("div", { class: "occ-hidden" });
    const renderTemplates = () => fill(templatesBox, ["shift", "oncall", "blank"].map((tpl) => el("a", {
      class: `card ${form.template === tpl ? "occ-selected" : ""}`,
      onclick: () => {
        form.template = tpl;
        form.ring = tpl === "oncall" ? 25 : 20;
        form.backupRing = form.ring;
        form.backupType = tpl === "shift" && o.queues.length ? "queue" : tpl === "oncall" ? "member" : "";
        $id("occ-w-ring").value = form.ring;
        renderTemplates();
        renderBackup();
        oncallBox.classList.toggle("occ-hidden", tpl !== "oncall");
      }
    }, el(
      "div",
      { class: "content" },
      el("div", { class: "header", text: t(`occ_Template_${tpl}`) }),
      el("div", { class: "description", text: t(`occ_Template_${tpl}_Hint`) })
    ))));
    const renderPeople = () => fill(peopleBox, peopleList(form.people, (list) => {
      form.people = list;
      renderPeople();
    }));
    const renderBackup = () => {
      const typeSelect = select(
        [["", t("occ_BackupNone")], ["member", t("occ_BackupPerson")], ["queue", t("occ_BackupQueue")]],
        form.backupType,
        { onchange: (e) => {
          form.backupType = e.target.value;
          renderBackup();
        } }
      );
      const target = form.backupType === "queue" ? select(o.queues.map((q) => [q.number, `${q.name} (${q.number})`]), form.backupNumber, { onchange: (e) => {
        form.backupNumber = e.target.value;
      } }) : form.backupType === "member" ? select([["", "\u2014"], ...employeeOptions()], form.backupUser, { onchange: (e) => {
        form.backupUser = e.target.value;
      } }) : null;
      fill(
        backupBox,
        el("div", { class: "six wide field" }, el("label", { text: t("occ_Backup") }), typeSelect),
        target ? el("div", { class: "six wide field" }, el("label", { text: "\xA0" }), target) : null,
        form.backupType === "member" ? el(
          "div",
          { class: "four wide field" },
          el("label", { text: t("occ_RingSeconds") }),
          el("input", { type: "number", min: 5, max: 300, value: form.backupRing, oninput: (e) => {
            form.backupRing = Number(e.target.value);
          } })
        ) : null
      );
      if (form.backupType === "queue" && !form.backupNumber && o.queues[0]) form.backupNumber = o.queues[0].number;
    };
    const routesBox = el("div", { class: "grouped fields" }, o.routes.length ? o.routes.map((r) => el(
      "div",
      { class: "field" },
      el(
        "div",
        { class: "ui checkbox" },
        el("input", {
          type: "checkbox",
          id: `occ-route-${r.id}`,
          disabled: r.linked_schedule_id !== null,
          onchange: (e) => {
            form.routeIds = e.target.checked ? [...form.routeIds, r.id] : form.routeIds.filter((x) => x !== r.id);
          }
        }),
        el("label", { for: `occ-route-${r.id}`, text: routeLabel(r) })
      )
    )) : el("p", { class: "occ-hint", text: t("occ_NoRoutes") }));
    fill(
      oncallBox,
      el("h4", { class: "ui header", text: t("occ_WizardWho") }),
      el("p", { class: "occ-hint", text: t("occ_WizardWhoOncall") }),
      peopleBox,
      el(
        "div",
        { class: "fields" },
        el(
          "div",
          { class: "eight wide field" },
          el("label", { text: t("occ_FirstHandover") }),
          el("input", { type: "datetime-local", value: toInput(form.handover), oninput: (e) => {
            form.handover = fromInput(e.target.value);
          } })
        ),
        el(
          "div",
          { class: "four wide field" },
          el("label", { text: t("occ_TurnDays") }),
          el("input", { type: "number", min: 1, max: 60, value: form.turnDays, oninput: (e) => {
            form.turnDays = Number(e.target.value);
          } })
        )
      )
    );
    fill(
      root,
      el("div", { class: "occ-toolbar" }, button(t("occ_Back"), () => go("#/"), "basic", "left arrow")),
      el(
        "div",
        { class: "ui form segment" },
        el("h3", { class: "ui header", text: t("occ_WizardTitle") }),
        el("h4", { class: "ui header", text: t("occ_WizardWhat") }),
        templatesBox,
        el(
          "div",
          { class: "fields" },
          el(
            "div",
            { class: "ten wide field" },
            el("label", { text: t("occ_Name") }),
            el("input", { type: "text", maxlength: 60, placeholder: t("occ_NamePlaceholder"), oninput: (e) => {
              form.name = e.target.value;
            } })
          ),
          el(
            "div",
            { class: "six wide field" },
            el("label", { text: t("occ_Number") }),
            el("input", { type: "text", inputmode: "numeric", value: form.number, oninput: (e) => {
              form.number = e.target.value.trim();
            } })
          )
        ),
        el("div", { class: "occ-hint", text: t("occ_NumberHint") }),
        el("h4", { class: "ui header", text: t("occ_WizardCalls") }),
        el("p", { class: "occ-hint", text: t("occ_WizardCallsHint") }),
        routesBox,
        oncallBox,
        el("h4", { class: "ui header", text: t("occ_WizardNoAnswer") }),
        el(
          "div",
          { class: "fields" },
          el(
            "div",
            { class: "four wide field" },
            el("label", { text: t("occ_RingSeconds") }),
            el("input", { id: "occ-w-ring", type: "number", min: 5, max: 300, value: form.ring, oninput: (e) => {
              form.ring = Number(e.target.value);
            } })
          )
        ),
        backupBox,
        field(t("occ_Final"), select(destinationOptions(), form.final, { onchange: (e) => {
          form.final = e.target.value;
        } }), t("occ_FinalHint")),
        el(
          "div",
          { class: "occ-actions" },
          button(t("occ_Create"), () => {
            const data = {
              name: form.name,
              number: form.number,
              template: form.template,
              ring_seconds: form.ring,
              backup_type: form.backupType,
              backup_number: form.backupNumber,
              backup_user_id: Number(form.backupUser) || 0,
              backup_ring_seconds: form.backupRing,
              final_number: form.final,
              route_ids: form.routeIds,
              shift_names: [t("occ_ShiftMorning"), t("occ_ShiftAfternoon")]
            };
            if (form.template === "oncall" && form.people.length) {
              data.rotation = { user_ids: form.people, first_handover: form.handover, turn_days: form.turnDays, active_hours: o.oncall_hours };
            }
            return post("createSchedule", data).then((r) => {
              if (r.link_errors && r.link_errors.length) state.notice = r.link_errors.join(" ");
              return refreshOptions().then(() => go(`#/schedule/${r.id}/${form.template === "oncall" ? "rotation" : form.template === "shift" ? "roster" : "settings"}`));
            });
          }, "primary", "check")
        )
      )
    );
    renderTemplates();
    renderPeople();
    renderBackup();
  }
  function nextFriday() {
    const now = /* @__PURE__ */ new Date(wall(Math.floor(Date.now() / 1e3) + state.clockOffset) + "Z");
    const add = (5 - now.getUTCDay() + 7) % 7 || (now.getUTCHours() >= 17 ? 7 : 0);
    now.setUTCDate(now.getUTCDate() + add);
    return `${now.getUTCFullYear()}-${pad(now.getUTCMonth() + 1)}-${pad(now.getUTCDate())} 17:00`;
  }
  function routeLabel(r) {
    const name = r.rulename || r.number || `#${r.id}`;
    return `${name}${r.number && r.rulename ? ` (${r.number})` : ""} \u2192 ${destinationName(r.extension)}`;
  }
  function peopleList(userIds, onChange) {
    const add = select([["", t("occ_AddPerson")], ...employeeOptions().filter(([id]) => !userIds.includes(id))], "", {
      onchange: (e) => {
        if (e.target.value) onChange([...userIds, Number(e.target.value)]);
      }
    });
    const move = (i, d) => {
      const l = [...userIds];
      [l[i], l[i + d]] = [l[i + d], l[i]];
      onChange(l);
    };
    return el(
      "div",
      { class: "occ-people" },
      el("ol", { class: "occ-people-list" }, userIds.map((id, i) => el(
        "li",
        {},
        el("span", { text: employeeName(id) }),
        el(
          "span",
          { class: "occ-row-actions" },
          i > 0 ? button("", () => move(i, -1), "mini basic icon", "arrow up") : null,
          i < userIds.length - 1 ? button("", () => move(i, 1), "mini basic icon", "arrow down") : null,
          button("", () => onChange(userIds.filter((x) => x !== id)), "mini basic icon", "times")
        )
      ))),
      el("div", { class: "field" }, add)
    );
  }
  function openSchedule() {
    const root = $id("occ-main");
    fill(root, el("div", { class: "ui active centered inline loader" }));
    loadDetail().then(() => {
      renderSchedule();
      if (state.notice) {
        showError(state.notice);
        state.notice = "";
      }
    });
  }
  var loadDetail = () => get("schedule", { id: state.scheduleId }).then((d) => {
    state.detail = d;
  });
  function renderSchedule() {
    const { schedule } = state.detail;
    const tabs = TABS;
    const body = el("div", { class: "occ-tab-body" });
    fill(
      $id("occ-main"),
      el(
        "div",
        { class: "occ-toolbar" },
        button(t("occ_AllSchedules"), () => go("#/"), "basic", "left arrow"),
        el("h3", { class: "ui header occ-title", text: `${schedule.name} \xB7 ${schedule.number}` }),
        String(schedule.enabled) === "1" ? null : el("span", { class: "ui grey label", text: t("occ_Disabled") })
      ),
      el("div", { class: "ui top attached tabular menu" }, tabs.map((tab) => el("a", {
        class: `item ${state.tab === tab ? "active" : ""}`,
        href: `#/schedule/${schedule.id}/${tab}`,
        text: t(`occ_Tab_${tab}`)
      }))),
      el("div", { class: "ui bottom attached segment" }, body)
    );
    ({ calendar: renderCalendar, roster: renderRoster, rotation: renderRotation, settings: renderSettings, activity: renderActivity })[state.tab](body);
  }
  var refresh = () => loadDetail().then(renderSchedule);
  function renderCalendar(body) {
    const holder = el("div", { class: "occ-calendar" });
    fill(
      body,
      el("p", { class: "occ-hint", text: can("addOverride") ? t("occ_CalendarHint") : t("occ_ReadOnlyHint") }),
      el(
        "div",
        { class: "occ-legend" },
        el("span", { class: "occ-swatch occ-swatch-plan" }),
        t("occ_LegendPlan"),
        el("span", { class: "occ-swatch occ-swatch-override" }),
        t("occ_LegendOverride"),
        el("span", { class: "occ-swatch occ-swatch-gap" }),
        t("occ_LegendGap")
      ),
      holder
    );
    const { schedule } = state.detail;
    const calendar = new FullCalendar.Calendar(holder, {
      plugins: FullCalendar.plugins,
      locales: FullCalendar.locales,
      locale: LOCALE && LOCALE.startsWith("ru") ? "ru" : "en",
      timeZone: "UTC",
      now: () => wall(Math.floor(Date.now() / 1e3)),
      firstDay: 1,
      initialView: schedule.template === "oncall" ? "dayGridMonth" : "timeGridWeek",
      headerToolbar: { left: "prev,next today", center: "title", right: "dayGridMonth,timeGridWeek,listWeek" },
      contentHeight: 640,
      allDaySlot: false,
      nowIndicator: true,
      slotDuration: "00:30:00",
      scrollTime: "07:00:00",
      eventTimeFormat: { hour: "2-digit", minute: "2-digit", hourCycle: "h23" },
      slotLabelFormat: { hour: "2-digit", minute: "2-digit", hourCycle: "h23" },
      selectable: can("addOverride"),
      selectMirror: true,
      events: (info, success, failure) => {
        const from = info.startStr.slice(0, 10);
        const to = info.endStr.slice(0, 10);
        get("calendar", { id: schedule.id, from, to }).then((data) => success(calendarEvents(data, schedule, state.detail.members)), failure);
      },
      select: (info) => {
        calendar.unselect();
        overrideDialog({ start_at: fromCalendar(info.start), end_at: fromCalendar(info.end) });
      },
      eventClick: (info) => {
        const p = info.event.extendedProps;
        if (!can("addOverride") && !can("updateOverride")) return;
        if (p.override) overrideDialog(p.override);
        else if (p.segment) segmentDialog(p.segment);
      },
      eventDrop: (info) => moveOverride(info),
      eventResize: (info) => moveOverride(info)
    });
    state.calendar = calendar;
    calendar.render();
    const pending = state.pendingCover;
    state.pendingCover = null;
    if (pending && pending.scheduleId === schedule.id) {
      const now = Math.floor(Date.now() / 1e3) + state.clockOffset;
      coverDialog(now, pending.to > now ? pending.to : now + 3600);
    }
  }
  function calendarEvents(data, schedule, members) {
    const events = [];
    data.segments.forEach((s, i) => {
      if (s.start === "out") return;
      const range = { start: wall(s.from), end: wall(s.to) };
      if (s.decided_by === "override") {
        if (s.start === "2") events.push({ ...range, id: `ogap-${i}`, display: "background", classNames: ["occ-ev-gap"] });
        return;
      }
      if (s.start === "2") {
        events.push({ ...range, id: `gap-${i}`, display: "background", classNames: ["occ-ev-gap"] });
        events.push({
          ...range,
          id: `gapl-${i}`,
          title: decisionText(s, schedule, members),
          classNames: ["occ-ev-gap-label"],
          editable: false,
          backgroundColor: "transparent",
          borderColor: "transparent",
          textColor: "#9f3a38",
          extendedProps: { segment: s }
        });
        return;
      }
      const colour = memberColour(s.member_ids[0], members);
      events.push({
        ...range,
        id: `seg-${i}`,
        title: decisionText(s, schedule, members),
        editable: false,
        backgroundColor: `${colour}33`,
        borderColor: colour,
        textColor: "#1b1c1d",
        classNames: ["occ-ev-plan"],
        extendedProps: { segment: s }
      });
    });
    data.overrides.forEach((o) => {
      const colour = o.kind === "elsewhere" ? "#767676" : memberColour(o.members[0], members);
      const who = o.kind === "elsewhere" ? sub(t("occ_SentTo"), { target: destinationName(o.destination) }) : memberNames(o.members, members).join(", ");
      events.push({
        id: `ovr-${o.id}`,
        start: toInput(o.start_at),
        end: toInput(o.end_at),
        title: `${t(`occ_Kind_${o.kind}`)}: ${who}${o.note ? ` \xB7 ${o.note}` : ""}`,
        backgroundColor: colour,
        borderColor: colour,
        textColor: "#fff",
        editable: can("updateOverride"),
        classNames: ["occ-ev-override"],
        extendedProps: { override: o }
      });
    });
    return events;
  }
  function moveOverride(info) {
    const o = info.event.extendedProps.override;
    post("updateOverride", {
      id: state.scheduleId,
      override_id: o.id,
      override: { start_at: fromCalendar(info.event.start), end_at: fromCalendar(info.event.end || info.event.start) }
    }).then(calendarChanged, () => info.revert());
  }
  function segmentDialog(s) {
    const range = { start_at: fromCalendar(/* @__PURE__ */ new Date(`${wall(s.from)}Z`)), end_at: fromCalendar(/* @__PURE__ */ new Date(`${wall(s.to)}Z`)) };
    const { members } = state.detail;
    const userIds = s.member_ids.map((m) => userOfMember(m, members)).filter(Boolean);
    const modal = dialog(
      `${when(s.from, true)} \u2013 ${when(s.to, true)}`,
      el(
        "div",
        {},
        el("p", { text: decisionText(s, state.detail.schedule, members) }),
        el(
          "div",
          { class: "ui vertical fluid buttons" },
          button(t("occ_CoverEllipsis"), () => {
            $(modal).modal("hide");
            overrideDialog({ ...range, kind: "cover" });
          }, "basic", "user"),
          userIds.length === 1 ? button(t("occ_SwapEllipsis"), () => {
            $(modal).modal("hide");
            swapDialog(range, userIds[0]);
          }, "basic", "exchange") : null,
          button(t("occ_ElsewhereEllipsis"), () => {
            $(modal).modal("hide");
            overrideDialog({ ...range, kind: "elsewhere" });
          }, "basic", "share")
        )
      ),
      null
    );
  }
  function coverDialog(fromEpoch, toEpoch) {
    const from = fromCalendar(/* @__PURE__ */ new Date(`${wall(fromEpoch)}Z`));
    const to = fromCalendar(/* @__PURE__ */ new Date(`${wall(toEpoch)}Z`));
    overrideDialog({ kind: "cover", start_at: from, end_at: to });
  }
  function overrideDialog(override) {
    const existing = Boolean(override.id);
    const { schedule, members } = state.detail;
    const form = {
      kind: override.kind || "cover",
      start: override.start_at,
      end: override.end_at,
      users: existing ? (override.members || []).map((m) => userOfMember(m, members)).filter(Boolean) : [],
      destination: override.destination || state.detail.elsewhere_targets[0] || "",
      note: override.note || ""
    };
    const whoBox = el("div");
    const renderWho = () => fill(whoBox, form.kind === "elsewhere" ? field(t("occ_SendTo"), select(
      state.detail.elsewhere_targets.map((n) => [n, destinationName(n)]),
      form.destination,
      { onchange: (e) => {
        form.destination = e.target.value;
      } }
    ), t("occ_SendToHint")) : field(t("occ_WhoAnswers"), peopleList(form.users, (list) => {
      form.users = list;
      renderWho();
    }), t("occ_RingTogether")));
    const kinds = existing ? [[form.kind, t(`occ_Kind_${form.kind}`)]] : [["cover", t("occ_Kind_cover")], ["elsewhere", t("occ_Kind_elsewhere")]];
    const body = el(
      "div",
      {},
      field(t("occ_OverrideKind"), select(kinds, form.kind, { disabled: existing, onchange: (e) => {
        form.kind = e.target.value;
        renderWho();
      } })),
      el(
        "div",
        { class: "two fields" },
        field(t("occ_From"), el("input", { type: "datetime-local", value: toInput(form.start), oninput: (e) => {
          form.start = fromInput(e.target.value);
        } })),
        field(t("occ_To"), el("input", { type: "datetime-local", value: toInput(form.end), oninput: (e) => {
          form.end = fromInput(e.target.value);
        } }))
      ),
      whoBox,
      field(t("occ_Note"), el("input", { type: "text", maxlength: 200, value: form.note, oninput: (e) => {
        form.note = e.target.value;
      } }))
    );
    renderWho();
    const payload = () => ({ kind: form.kind, start_at: form.start, end_at: form.end, user_ids: form.users, destination: form.destination, note: form.note });
    const done = calendarChanged;
    dialog(
      existing ? t(`occ_Kind_${form.kind}`) : t("occ_NewOverride"),
      body,
      () => (existing ? post("updateOverride", { id: schedule.id, override_id: override.id, override: payload() }) : post("addOverride", { id: schedule.id, override: payload() })).then(done),
      t("occ_Save"),
      existing ? [{ text: t("occ_Delete"), action: () => post("deleteOverride", { id: schedule.id, override_id: override.id }).then(done) }] : []
    );
  }
  function swapDialog(rangeA, userA) {
    const form = { userB: "", startB: "", endB: "", note: "" };
    const body = el(
      "div",
      {},
      el("p", { text: sub(t("occ_SwapIntro"), { who: employeeName(userA), from: rangeA.start_at, to: rangeA.end_at }) }),
      field(t("occ_SwapWith"), select([["", "\u2014"], ...employeeOptions().filter(([id]) => id !== userA)], "", { onchange: (e) => {
        form.userB = e.target.value;
      } })),
      el(
        "div",
        { class: "two fields" },
        field(t("occ_TheirShiftFrom"), el("input", { type: "datetime-local", oninput: (e) => {
          form.startB = fromInput(e.target.value);
        } })),
        field(t("occ_To"), el("input", { type: "datetime-local", oninput: (e) => {
          form.endB = fromInput(e.target.value);
        } }))
      ),
      el("div", { class: "occ-hint", text: t("occ_SwapHint") }),
      field(t("occ_Note"), el("input", { type: "text", maxlength: 200, oninput: (e) => {
        form.note = e.target.value;
      } }))
    );
    dialog(t("occ_Kind_swap"), body, () => post("addSwap", {
      id: state.scheduleId,
      swap: { user_a: userA, start_a: rangeA.start_at, end_a: rangeA.end_at, user_b: Number(form.userB), start_b: form.startB, end_b: form.endB, note: form.note }
    }).then(calendarChanged));
  }
  function calendarChanged() {
    flash(t("occ_Saved"));
    return loadDetail().then(() => {
      if (state.calendar) state.calendar.refetchEvents();
    });
  }
  function renderRoster(body) {
    const { schedule, rosters, shift_types: shifts, members } = state.detail;
    if (!rosters.find((r) => r.id === state.rosterId)) {
      state.rosterId = rosters.length ? rosters[0].id : 0;
      state.rosterWeek = 1;
    }
    const roster = rosters.find((r) => r.id === state.rosterId);
    const addRoster = () => rosterDialog({ valid_from: state.options.today, valid_until: "", cycle_weeks: 1 });
    if (!roster) {
      fill(body, el("p", { text: t("occ_NoRoster") }), can("saveRoster") ? button(t("occ_AddRoster"), addRoster, "primary", "plus") : null);
      return;
    }
    if (state.rosterWeek > roster.cycle_weeks) state.rosterWeek = 1;
    const week = state.rosterWeek;
    const usersIn = (shiftId, weekday) => roster.cells.filter((c) => c.shift_type_id === shiftId && c.week === week && c.weekday === weekday).map((c) => userOfMember(c.member_id, members)).filter(Boolean);
    const rows = shifts.map((shift, i) => el(
      "tr",
      {},
      el(
        "td",
        { class: "occ-shift-cell" },
        el("div", { class: "occ-shift-name", text: shift.name }),
        el("div", { class: "occ-hint", text: `${shift.start}\u2013${shift.end}` }),
        can("saveShiftType") ? el(
          "div",
          { class: "occ-row-actions" },
          i > 0 ? button("", () => orderShift(i, -1), "mini basic icon", "arrow up") : null,
          i < shifts.length - 1 ? button("", () => orderShift(i, 1), "mini basic icon", "arrow down") : null,
          button("", () => shiftDialog(shift), "mini basic icon", "pencil"),
          button("", () => confirmDialog(
            sub(t("occ_DeleteShiftConfirm"), { name: shift.name }),
            () => post("deleteShiftType", { id: schedule.id, shift_type_id: shift.id }).then(refresh)
          ), "mini basic icon", "trash")
        ) : null
      ),
      WEEKDAYS.map((d) => {
        if (!shift.weekdays.includes(d)) return el("td", { class: "occ-cell-off" });
        const users = usersIn(shift.id, d);
        const editable = can("setCell");
        return el(
          "td",
          {
            class: `occ-cell ${editable ? "occ-cell-editable" : ""} ${users.length ? "" : "occ-cell-empty"}`,
            title: editable ? t("occ_EditCell") : null,
            onclick: editable ? () => cellDialog(roster, shift, week, d, users) : null
          },
          users.length ? users.map((u) => el("div", { class: "ui mini label occ-chip", style: `border-left: 4px solid ${colourOfUser(u)}`, text: employeeName(u) })) : el("span", { class: "occ-hint", text: t("occ_EmptyCell") })
        );
      })
    ));
    fill(
      body,
      rosters.length > 1 ? el("div", { class: "ui secondary menu" }, rosters.map((r) => el("a", {
        class: `item ${r.id === roster.id ? "active" : ""}`,
        onclick: () => {
          state.rosterId = r.id;
          state.rosterWeek = 1;
          renderSchedule();
        },
        text: rosterLabel(r)
      }))) : null,
      el(
        "div",
        { class: "occ-toolbar" },
        el("span", { text: rosterLabel(roster) }),
        can("saveRoster") ? button(t("occ_EditRoster"), () => rosterDialog(roster), "mini basic", "pencil") : null,
        can("saveShiftType") ? button(t("occ_AddShiftType"), () => shiftDialog({ name: "", start: "08:00", end: "17:00", weekdays: [1, 2, 3, 4, 5] }), "mini basic", "plus") : null,
        can("saveRoster") ? button(t("occ_AddSeasonalRoster"), addRoster, "mini basic", "calendar plus") : null
      ),
      roster.cycle_weeks > 1 ? el("div", { class: "ui pointing secondary menu" }, Array.from({ length: roster.cycle_weeks }, (_, i) => el("a", {
        class: `item ${week === i + 1 ? "active" : ""}`,
        onclick: () => {
          state.rosterWeek = i + 1;
          renderSchedule();
        },
        text: sub(t("occ_WeekN"), { n: i + 1 })
      }))) : null,
      shifts.length ? el(
        "table",
        { class: "ui celled fixed compact table occ-roster" },
        el("thead", {}, el("tr", {}, el("th", { text: t("occ_ShiftType") }), WEEKDAYS.map((d) => el("th", { text: weekdayName(d) })))),
        el("tbody", {}, rows)
      ) : el("p", { class: "occ-hint", text: t("occ_NoShiftTypes") }),
      el("p", { class: "occ-hint", text: t("occ_RosterHint") })
    );
    function orderShift(i, d) {
      const ids = shifts.map((s) => s.id);
      [ids[i], ids[i + d]] = [ids[i + d], ids[i]];
      post("orderShiftTypes", { id: schedule.id, ids }).then(refresh);
    }
  }
  var colourOfUser = (userId) => (state.detail.members.find((m) => m.user_id === userId) || {}).colour || "#767676";
  function rosterLabel(r) {
    const until = r.valid_until ? sub(t("occ_RosterUntil"), { date: r.valid_until }) : t("occ_RosterNoEnd");
    const cycle = r.cycle_weeks > 1 ? ` \xB7 ${sub(t("occ_CycleWeeks"), { n: r.cycle_weeks })}` : "";
    return `${sub(t("occ_RosterFrom"), { date: r.valid_from })} ${until}${cycle}`;
  }
  function cellDialog(roster, shift, week, weekday, users) {
    let list = [...users];
    const box = el("div");
    const render = () => fill(box, peopleList(list, (l) => {
      list = l;
      render();
    }));
    render();
    dialog(
      `${shift.name} \xB7 ${weekdayName(weekday)}${roster.cycle_weeks > 1 ? ` \xB7 ${sub(t("occ_WeekN"), { n: week })}` : ""}`,
      el("div", {}, el("p", { class: "occ-hint", text: t("occ_RingTogether") }), box),
      () => post("setCell", { id: state.scheduleId, roster_id: roster.id, shift_type_id: shift.id, week, weekday, user_ids: list }).then(refresh)
    );
  }
  function shiftDialog(shift) {
    const form = { ...shift, weekdays: [...shift.weekdays] };
    const days = el("div", { class: "inline fields" }, WEEKDAYS.map((d) => el("div", { class: "field" }, el(
      "div",
      { class: "ui checkbox" },
      el("input", {
        type: "checkbox",
        id: `occ-wd-${d}`,
        checked: form.weekdays.includes(d),
        onchange: (e) => {
          form.weekdays = e.target.checked ? [...form.weekdays, d] : form.weekdays.filter((x) => x !== d);
        }
      }),
      el("label", { for: `occ-wd-${d}`, text: weekdayName(d) })
    ))));
    dialog(
      shift.id ? t("occ_EditShiftType") : t("occ_AddShiftType"),
      el(
        "div",
        {},
        field(t("occ_Name"), el("input", { type: "text", maxlength: 60, value: form.name, oninput: (e) => {
          form.name = e.target.value;
        } })),
        el(
          "div",
          { class: "two fields" },
          field(t("occ_Starts"), el("input", { type: "time", value: form.start, oninput: (e) => {
            form.start = e.target.value;
          } })),
          field(t("occ_Ends"), el("input", { type: "time", value: form.end === "24:00" ? "00:00" : form.end, oninput: (e) => {
            form.end = e.target.value;
          } }))
        ),
        el("div", { class: "occ-hint", text: t("occ_OvernightHint") }),
        el("label", { class: "occ-label", text: t("occ_Weekdays") }),
        days
      ),
      () => post("saveShiftType", { id: state.scheduleId, shift: form }).then(refresh)
    );
  }
  function rosterDialog(roster) {
    const form = { ...roster };
    dialog(
      roster.id ? t("occ_EditRoster") : t("occ_AddRoster"),
      el(
        "div",
        {},
        el(
          "div",
          { class: "two fields" },
          field(t("occ_ValidFrom"), el("input", { type: "date", value: form.valid_from, oninput: (e) => {
            form.valid_from = e.target.value;
          } })),
          field(t("occ_ValidUntil"), el("input", { type: "date", value: form.valid_until, oninput: (e) => {
            form.valid_until = e.target.value;
          } }), t("occ_ValidUntilHint"))
        ),
        field(t("occ_Cycle"), select(
          [1, 2, 3, 4].map((n) => [n, sub(t("occ_CycleWeeks"), { n })]),
          form.cycle_weeks,
          { onchange: (e) => {
            form.cycle_weeks = Number(e.target.value);
          } }
        ), t("occ_CycleHint"))
      ),
      () => post("saveRoster", { id: state.scheduleId, roster: form }).then((r) => {
        state.rosterId = r.id;
        return refresh();
      }),
      t("occ_Save"),
      roster.id ? [{ text: t("occ_Delete"), action: () => post("deleteRoster", { id: state.scheduleId, roster_id: roster.id }).then(refresh) }] : []
    );
  }
  function renderRotation(body) {
    const { rotations, members } = state.detail;
    const add = () => rotationForm(body, {
      user_ids: [],
      first_handover: nextFriday(),
      turn_days: 7,
      active_hours: state.options.oncall_hours,
      valid_until: ""
    });
    if (!rotations.length) {
      fill(body, el("p", { text: t("occ_NoRotation") }), can("saveRotation") ? button(t("occ_AddRotation"), add, "primary", "plus") : null);
      return;
    }
    fill(
      body,
      rotations.map((r) => el(
        "div",
        { class: "ui segment" },
        el(
          "div",
          { class: "occ-toolbar" },
          el("strong", { text: memberNames(r.members, members).join(" \u2192 ") }),
          can("saveRotation") ? button(t("occ_Edit"), () => rotationForm(body, { ...r, user_ids: r.members.map((m) => userOfMember(m, members)).filter(Boolean) }), "mini basic", "pencil") : null
        ),
        el("div", { class: "occ-hint", text: sub(t("occ_RotationSummary"), { days: r.turn_days, at: r.first_handover }) }),
        preview(r)
      )),
      can("saveRotation") ? button(t("occ_AddRotation"), add, "basic", "plus") : null
    );
  }
  function preview(r) {
    const { members } = state.detail;
    const names = memberNames(r.members, members);
    const first = /* @__PURE__ */ new Date(`${toInput(r.first_handover)}:00Z`);
    const now = /* @__PURE__ */ new Date(`${wall(Math.floor(Date.now() / 1e3))}Z`);
    const step = r.turn_days * 864e5;
    let k = Math.max(0, Math.floor((now - first) / step));
    const rows = [];
    const last = r.valid_until ? /* @__PURE__ */ new Date(`${r.valid_until}T23:59:00Z`) : null;
    for (let i = 0; i < 8; i++, k++) {
      const start = new Date(first.getTime() + k * step);
      if (last && start > last) break;
      rows.push(el(
        "tr",
        {},
        el("td", { text: start.toLocaleString(LOCALE, { timeZone: "UTC", weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }) }),
        el("td", { text: names[k % names.length] })
      ));
    }
    return el(
      "table",
      { class: "ui very basic compact table occ-preview" },
      el("thead", {}, el("tr", {}, el("th", { text: t("occ_Handover") }), el("th", { text: t("occ_OnDuty") }))),
      el("tbody", {}, rows)
    );
  }
  function rotationForm(body, rotation) {
    const form = { ...rotation, active_hours: (rotation.active_hours || []).map((w) => ({ ...w, weekdays: [...w.weekdays] })) };
    const peopleBox = el("div");
    const hoursBox = el("div");
    const renderPeople = () => fill(peopleBox, peopleList(form.user_ids, (l) => {
      form.user_ids = l;
      renderPeople();
    }));
    const renderHours = () => fill(
      hoursBox,
      form.active_hours.length ? form.active_hours.map((w, i) => el(
        "div",
        { class: "ui segment occ-window" },
        el("div", { class: "inline fields" }, WEEKDAYS.map((d) => el("div", { class: "field" }, el(
          "div",
          { class: "ui checkbox" },
          el("input", {
            type: "checkbox",
            id: `occ-ah-${i}-${d}`,
            checked: w.weekdays.includes(d),
            onchange: (e) => {
              w.weekdays = e.target.checked ? [...w.weekdays, d] : w.weekdays.filter((x) => x !== d);
            }
          }),
          el("label", { for: `occ-ah-${i}-${d}`, text: weekdayName(d) })
        )))),
        el(
          "div",
          { class: "inline fields" },
          field(t("occ_Starts"), el("input", { type: "time", value: w.start, oninput: (e) => {
            w.start = e.target.value;
          } })),
          field(t("occ_Ends"), el("input", { type: "time", value: w.end, oninput: (e) => {
            w.end = e.target.value;
          } })),
          button("", () => {
            form.active_hours.splice(i, 1);
            renderHours();
          }, "mini basic icon", "trash")
        )
      )) : el("p", { class: "occ-hint", text: t("occ_AlwaysActive") }),
      el(
        "div",
        {},
        button(t("occ_AddWindow"), () => {
          form.active_hours.push({ weekdays: [1, 2, 3, 4, 5], start: "18:00", end: "08:00" });
          renderHours();
        }, "mini basic", "plus"),
        button(t("occ_PresetAfterHours"), () => {
          form.active_hours = state.options.oncall_hours.map((w) => ({ ...w, weekdays: [...w.weekdays] }));
          renderHours();
        }, "mini basic"),
        button(t("occ_PresetAlways"), () => {
          form.active_hours = [];
          renderHours();
        }, "mini basic")
      )
    );
    fill(body, el(
      "div",
      { class: "ui form" },
      el("h4", { class: "ui header", text: t("occ_RotationPeople") }),
      peopleBox,
      el(
        "div",
        { class: "three fields" },
        field(t("occ_FirstHandover"), el("input", { type: "datetime-local", value: toInput(form.first_handover), oninput: (e) => {
          form.first_handover = fromInput(e.target.value);
        } }), t("occ_FirstHandoverHint")),
        field(t("occ_TurnDays"), el("input", { type: "number", min: 1, max: 60, value: form.turn_days, oninput: (e) => {
          form.turn_days = Number(e.target.value);
        } })),
        field(t("occ_ValidUntil"), el("input", { type: "date", value: form.valid_until, oninput: (e) => {
          form.valid_until = e.target.value;
        } }), t("occ_ValidUntilHint"))
      ),
      el("h4", { class: "ui header", text: t("occ_ActiveHours") }),
      el("p", { class: "occ-hint", text: t("occ_ActiveHoursHint") }),
      hoursBox,
      el(
        "div",
        { class: "occ-actions" },
        button(t("occ_Save"), () => post("saveRotation", { id: state.scheduleId, rotation: form }).then(() => {
          flash(t("occ_Saved"));
          refresh();
        }), "primary"),
        button(t("occ_Cancel"), () => renderSchedule(), "basic"),
        form.id ? button(t("occ_Delete"), () => confirmDialog(
          t("occ_DeleteRotationConfirm"),
          () => post("deleteRotation", { id: state.scheduleId, rotation_id: form.id }).then(refresh)
        ), "basic red") : null
      )
    ));
    renderPeople();
    renderHours();
  }
  function renderSettings(body) {
    const d = state.detail;
    const s = d.schedule;
    const o = state.options;
    const form = {
      name: s.name,
      enabled: String(s.enabled) === "1",
      ring_seconds: s.ring_seconds,
      backup_type: s.backup_type,
      backup_number: s.backup_number,
      backup_user_id: s.backup_user_id || "",
      backup_ring_seconds: s.backup_ring_seconds,
      final_number: s.final_number,
      outside_number: s.outside_number,
      greeting_sound_id: s.greeting_sound_id || "",
      callerid_prefix: s.callerid_prefix || ""
    };
    const backupBox = el("div", { class: "fields" });
    const renderBackup = () => fill(
      backupBox,
      el(
        "div",
        { class: "six wide field" },
        el("label", { text: t("occ_Backup") }),
        select(
          [["", t("occ_BackupNone")], ["member", t("occ_BackupPerson")], ["queue", t("occ_BackupQueue")]],
          form.backup_type,
          { onchange: (e) => {
            form.backup_type = e.target.value;
            renderBackup();
          } }
        )
      ),
      form.backup_type === "queue" ? el(
        "div",
        { class: "six wide field" },
        el("label", { text: "\xA0" }),
        select([["", "\u2014"], ...o.queues.map((q) => [q.number, `${q.name} (${q.number})`])], form.backup_number, { onchange: (e) => {
          form.backup_number = e.target.value;
        } }),
        el("div", { class: "occ-hint", text: t("occ_QueueBackupHint") })
      ) : null,
      form.backup_type === "member" ? el(
        "div",
        { class: "six wide field" },
        el("label", { text: "\xA0" }),
        select([["", "\u2014"], ...employeeOptions()], form.backup_user_id, { onchange: (e) => {
          form.backup_user_id = e.target.value;
        } })
      ) : null,
      form.backup_type === "member" ? el(
        "div",
        { class: "four wide field" },
        el("label", { text: t("occ_RingSeconds") }),
        el("input", { type: "number", min: 5, max: 300, value: form.backup_ring_seconds, oninput: (e) => {
          form.backup_ring_seconds = Number(e.target.value);
        } })
      ) : null
    );
    renderBackup();
    const testFrom = select([["", t("occ_TestCallFrom")], ...o.employees.map((e) => [e.extension, `${e.name} (${e.extension})`])], "", {});
    const linked = d.routes.filter((r) => r.linked_schedule_id === s.id);
    const free = d.routes.filter((r) => r.linked_schedule_id === null && r.extension !== s.number);
    const routeAdd = select([["", t("occ_LinkRoutePick")], ...free.map((r) => [r.id, routeLabel(r)])], "", {});
    const others = d.references.filter((r) => !(r.kind === "route" && linked.some((l) => l.id === r.id)));
    const shortTimeout = d.routes.filter((r) => r.extension === s.number && r.timeout > 0 && r.timeout < d.needed_timeout);
    const rules = [
      ...d.rosters.map((r) => ({ type: "roster", id: r.id, priority: r.priority, label: `${t("occ_Roster")}: ${rosterLabel(r)}` })),
      ...d.rotations.map((r) => ({ type: "rotation", id: r.id, priority: r.priority, label: `${t("occ_Rotation")}: ${memberNames(r.members, d.members).join(" \u2192 ")}` }))
    ].sort((a, b) => a.priority - b.priority);
    const moveRule = (i, dir) => {
      const list = [...rules];
      [list[i], list[i + dir]] = [list[i + dir], list[i]];
      post("orderRules", { id: s.id, rules: list.map((r) => ({ type: r.type, id: r.id })) }).then(refresh);
    };
    fill(
      body,
      el(
        "div",
        { class: "ui form" },
        el("h4", { class: "ui dividing header", text: t("occ_General") }),
        el(
          "div",
          { class: "fields" },
          el(
            "div",
            { class: "ten wide field" },
            el("label", { text: t("occ_Name") }),
            el("input", { type: "text", maxlength: 60, value: form.name, oninput: (e) => {
              form.name = e.target.value;
            } })
          ),
          el("div", { class: "six wide field" }, el("label", { text: t("occ_Number") }), el("input", { type: "text", value: s.number, disabled: true }))
        ),
        el("div", { class: "field" }, el(
          "div",
          { class: "ui toggle checkbox" },
          el("input", { type: "checkbox", id: "occ-enabled", checked: form.enabled, onchange: (e) => {
            form.enabled = e.target.checked;
          } }),
          el("label", { for: "occ-enabled", text: t("occ_EnabledLabel") })
        )),
        el("h4", { class: "ui dividing header", text: t("occ_WizardNoAnswer") }),
        el(
          "div",
          { class: "fields" },
          el(
            "div",
            { class: "four wide field" },
            el("label", { text: t("occ_RingOnDuty") }),
            el("input", { type: "number", min: 5, max: 300, value: form.ring_seconds, oninput: (e) => {
              form.ring_seconds = Number(e.target.value);
            } })
          )
        ),
        backupBox,
        el(
          "div",
          { class: "two fields" },
          field(t("occ_Final"), select(destinationOptions().filter(([v]) => v !== s.number), form.final_number, { onchange: (e) => {
            form.final_number = e.target.value;
          } }), t("occ_FinalHint")),
          field(t("occ_Outside"), select(
            [["", t("occ_SameAsFinal")], ...destinationOptions().filter(([v]) => v !== s.number)],
            form.outside_number,
            { onchange: (e) => {
              form.outside_number = e.target.value;
            } }
          ), t("occ_OutsideHint"))
        ),
        field(t("occ_Greeting"), select(
          [["", t("occ_NoGreeting")], ...o.sounds.map((x) => [x.id, x.name])],
          form.greeting_sound_id,
          { onchange: (e) => {
            form.greeting_sound_id = e.target.value;
          } }
        ), t("occ_GreetingHint")),
        field(t("occ_CallerIdPrefix"), el("input", {
          type: "text",
          maxlength: 20,
          value: form.callerid_prefix,
          oninput: (e) => {
            form.callerid_prefix = e.target.value;
          }
        }), t("occ_CallerIdPrefixHint")),
        can("updateSchedule") ? el(
          "div",
          { class: "occ-actions" },
          button(t("occ_Save"), () => post("updateSchedule", { id: s.id, ...form, backup_user_id: Number(form.backup_user_id) || 0 }).then(() => {
            flash(t("occ_Saved"));
            refreshOptions().then(refresh);
          }), "primary")
        ) : el("p", { class: "occ-hint", text: t("occ_ReadOnlyHint") })
      ),
      can("testCall") ? el(
        "div",
        {},
        el("h4", { class: "ui dividing header", text: t("occ_TestCall") }),
        el("p", { class: "occ-hint", text: t("occ_TestCallHint") }),
        el(
          "div",
          { class: "ui action input occ-link" },
          testFrom,
          button(t("occ_TestCallStart"), () => testFrom.value && post("testCall", { id: s.id, extension: testFrom.value }).then(() => flash(sub(t("occ_TestCallStarted"), { ext: testFrom.value }))), "basic", "phone")
        )
      ) : null,
      el("h4", { class: "ui dividing header", text: t("occ_LinkedRoutes") }),
      el("p", { class: "occ-hint", text: sub(t("occ_LinkedRoutesHint"), { n: d.needed_timeout }) }),
      linked.length ? el("table", { class: "ui compact table" }, el("tbody", {}, linked.map((r) => el(
        "tr",
        {},
        el("td", { text: routeLabel(r) }),
        el("td", {}, r.changed_outside ? el("span", { class: "ui orange label", text: t("occ_ChangedOutside") }) : null),
        el("td", { class: "right aligned" }, can("unlinkRoute") && button(t("occ_Unlink"), () => post("unlinkRoute", { id: s.id, route_id: r.id }).then((res) => {
          flash(res.restored ? t("occ_Restored") : t("occ_ForgotLink"));
          refreshOptions().then(refresh);
        }), "mini basic"))
      )))) : el("p", { text: t("occ_NoLinkedRoutes") }),
      can("linkRoute") && el(
        "div",
        { class: "ui action input occ-link" },
        routeAdd,
        button(t("occ_Link"), () => routeAdd.value && post("linkRoute", { id: s.id, route_id: Number(routeAdd.value) }).then(() => {
          flash(t("occ_Saved"));
          refreshOptions().then(refresh);
        }), "basic")
      ),
      shortTimeout.length ? el("div", { class: "ui warning message", text: sub(t("occ_ShortTimeout"), { n: d.needed_timeout, routes: shortTimeout.map(routeLabel).join("; ") }) }) : null,
      others.length ? el(
        "div",
        {},
        el("p", { class: "occ-hint", text: t("occ_OtherReferences") }),
        el("ul", {}, others.map((r) => el("li", { text: `${t(`occ_RefKind_${r.kind}`)} "${r.name}"` })))
      ) : null,
      rules.length > 1 ? el(
        "div",
        {},
        el("h4", { class: "ui dividing header", text: t("occ_RuleOrder") }),
        el("p", { class: "occ-hint", text: t("occ_RuleOrderHint") }),
        el("ol", { class: "occ-people-list" }, rules.map((r, i) => el(
          "li",
          {},
          el("span", { text: r.label }),
          can("orderRules") ? el(
            "span",
            { class: "occ-row-actions" },
            i > 0 ? button("", () => moveRule(i, -1), "mini basic icon", "arrow up") : null,
            i < rules.length - 1 ? button("", () => moveRule(i, 1), "mini basic icon", "arrow down") : null
          ) : null
        )))
      ) : null,
      can("deleteSchedule") && el("h4", { class: "ui dividing header", text: t("occ_DangerZone") }),
      can("deleteSchedule") && button(t("occ_DeleteSchedule"), () => confirmDialog(
        sub(t("occ_DeleteScheduleConfirm"), { name: s.name, number: s.number }),
        () => post("deleteSchedule", { id: s.id }).then(() => {
          flash(t("occ_Deleted"));
          refreshOptions().then(() => go("#/"));
        })
      ), "basic red", "trash")
    );
  }
  function renderActivity(body) {
    fill(body, el("div", { class: "ui active centered inline loader" }));
    get("audit", { id: state.scheduleId }).then((data) => {
      fill(body, data.entries.length ? el(
        "table",
        { class: "ui compact table occ-audit" },
        el("thead", {}, el("tr", {}, el("th", { text: t("occ_When") }), el("th", { text: t("occ_Who") }), el("th", { text: t("occ_Change") }))),
        el("tbody", {}, data.entries.map((e) => el(
          "tr",
          {},
          el("td", { text: when(e.at, true) }),
          el("td", { text: e.actor || "\u2014" }),
          el("td", {}, el("div", { text: t(`occ_Audit_${e.action.replace(".", "_")}`) }), auditDetail(e))
        )))
      ) : el("p", { text: t("occ_NoActivity") }));
    });
  }
  function auditDetail(e) {
    const before = e.before && typeof e.before === "object" && !Array.isArray(e.before) ? e.before : {};
    const after = e.after && typeof e.after === "object" && !Array.isArray(e.after) ? e.after : {};
    const keys = [.../* @__PURE__ */ new Set([...Object.keys(before), ...Object.keys(after)])].filter((k) => !["id", "schedule_id", "created_at", "created_by"].includes(k) && JSON.stringify(before[k]) !== JSON.stringify(after[k]));
    if (!keys.length) return null;
    const show = (v) => v === void 0 || v === null || v === "" ? "\u2014" : typeof v === "object" ? JSON.stringify(v) : String(v);
    return el("div", { class: "occ-hint" }, keys.slice(0, 6).map((k) => el("div", { text: `${k}: ${show(before[k])} \u2192 ${show(after[k])}` })));
  }
  var refreshOptions = () => get("options").then((o) => {
    state.options = o;
    state.clockOffset = o.now - Math.floor(Date.now() / 1e3);
  });
  $(() => {
    refreshOptions().then(() => {
      window.addEventListener("hashchange", route);
      route();
    });
  });
})();
