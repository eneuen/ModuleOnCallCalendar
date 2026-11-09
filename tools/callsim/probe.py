#!/usr/bin/env python3
"""On Call Calendar probe: real calls through the test PBX, checked end to end.

Each scenario sets up a probe schedule through the module page's own
actions (as an admin's browser does), places real SIP calls with CallSim's
phones and carrier, and checks which phones rang, whether the caller was
answered, the call records the PBX wrote, and what the module published in
AstDB or changed in the PBX's routing.

  probe.py list            list the scenarios
  probe.py run [names...]  run all scenarios (or the named ones)
  probe.py cleanup         delete the probe schedules and route

Needs `callsim.py setup` first, the module installed and enabled, and the
same environment variables as callsim.py. The probe schedules use numbers
2290 and 2291 and the DID 5551090; scenarios replace the probe schedule's
plan each time, so run it on a test PBX only.
"""
import json
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

import callsim
from callsim import (DID_CONF, DID_DIRECT, NAME, PBX_CONTAINER, PBX_URL, QUEUE, Line, Pbx, callsim_routes_in_hours,
                     cdr_max_id, docker, forwarding, load_state, pause, wait_for_legs)

MODULE = "module-on-call-calendar"
PROBE_NUMBER = "2290"
SPARE_NUMBER = "2291"
PROBE_NAME = f"{NAME} probe"
PROBE_DID = "5551090"
PROBE_ROUTE = f"{NAME} OCC probe {PROBE_DID}"
FAMILY = "OnCallCalendar"


# --------------------------------------------------------------------------- the module page

class Page:
    """The module page's JSON actions, called as the page calls them: AJAX, with the admin's Bearer token."""

    def __init__(self):
        self.pbx = Pbx()

    def _send(self, req):
        try:
            with urllib.request.urlopen(req, context=self.pbx.tls, timeout=60) as r:
                return json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            return json.loads(e.read() or b"{}")

    def _headers(self):
        return {"Authorization": f"Bearer {self.pbx.token}", "X-Requested-With": "XMLHttpRequest", "Accept": "application/json"}

    def get(self, action, **params):
        url = f"{PBX_URL}/admin-cabinet/{MODULE}/{action}?{urllib.parse.urlencode(params)}"
        body = self._send(urllib.request.Request(url, headers=self._headers()))
        if not body.get("success"):
            raise RuntimeError(f"{action}: {body.get('message')}")
        return body["data"]

    def post(self, action, data, expect_refusal=False):
        req = urllib.request.Request(f"{PBX_URL}/admin-cabinet/{MODULE}/{action}", data=json.dumps(data).encode(), method="POST",
                                     headers={**self._headers(), "Content-Type": "application/json"})
        body = self._send(req)
        if expect_refusal:
            if body.get("success"):
                raise RuntimeError(f"{action} was accepted but should have been refused")
            return body.get("message", "")
        if not body.get("success"):
            raise RuntimeError(f"{action}: {body.get('message')}")
        return body.get("data")


# --------------------------------------------------------------------------- the probe schedule

class Probe:
    def __init__(self):
        self.page = Page()
        self.options = self.page.get("options")
        self.tz = ZoneInfo(self.options["timezone"])
        self.users = {e["extension"]: e["id"] for e in self.options["employees"]}
        self.id = self.ensure_schedule()

    def ensure_schedule(self):
        for item in self.page.get("overview")["schedules"]:
            if item["schedule"]["number"] == PROBE_NUMBER:
                return item["schedule"]["id"]
        schedule_id = self.page.post("createSchedule", {"name": PROBE_NAME, "number": PROBE_NUMBER, "template": "blank"})["id"]
        wait_dialplan(f"{PROBE_NUMBER}@internal", "On-call schedule")
        return schedule_id

    def detail(self):
        return self.page.get("schedule", id=self.id)

    def local(self, minutes=0):
        """PBX wall-clock time, now plus `minutes`, as "Y-m-d H:i"."""
        return (datetime.now(self.tz) + timedelta(minutes=minutes)).strftime("%Y-%m-%d %H:%M")

    def ids(self, *extensions):
        return [self.users[e] for e in extensions]

    def reset(self, **chain):
        """An empty plan and a short chain: ring 8 s, no backup, voicemail; then the given changes."""
        d = self.detail()
        today = datetime.now(self.tz).date()
        cal = self.page.get("calendar", id=self.id, **{"from": str(today - timedelta(days=2)), "to": str(today + timedelta(days=3))})
        for o in cal["overrides"]:
            try:
                self.page.post("deleteOverride", {"id": self.id, "override_id": o["id"]})
            except RuntimeError:
                pass  # the other half of a swap, already gone
        for r in d["rosters"]:
            self.page.post("deleteRoster", {"id": self.id, "roster_id": r["id"]})
        for r in d["rotations"]:
            self.page.post("deleteRotation", {"id": self.id, "rotation_id": r["id"]})
        for s in d["shift_types"]:
            self.page.post("deleteShiftType", {"id": self.id, "shift_type_id": s["id"]})
        settings = {"id": self.id, "enabled": True, "ring_seconds": 8, "backup_type": "", "backup_number": "", "backup_user_id": 0,
                    "backup_ring_seconds": 8, "final_number": "voicemail", "outside_number": "", "greeting_sound_id": ""}
        settings.update(chain)
        self.page.post("updateSchedule", settings)

    def roster_today(self, *extensions):
        """A 24-hour shift every day, with these people in today's cell (none: an empty cell, a gap)."""
        shift = self.page.post("saveShiftType", {"id": self.id, "shift": {"name": "All day", "start": "00:00", "end": "00:00",
                                                                         "weekdays": [1, 2, 3, 4, 5, 6, 7]}})["id"]
        today = datetime.now(self.tz).date()
        monday = today - timedelta(days=today.weekday())
        roster = self.page.post("saveRoster", {"id": self.id, "roster": {"valid_from": str(monday), "valid_until": "", "cycle_weeks": 1}})["id"]
        self.page.post("setCell", {"id": self.id, "roster_id": roster, "shift_type_id": shift, "week": 1,
                                   "weekday": today.isoweekday(), "user_ids": self.ids(*extensions)})

    def override(self, kind, *extensions, destination=""):
        """An override from 30 minutes ago to an hour from now."""
        self.page.post("addOverride", {"id": self.id, "override": {"kind": kind, "start_at": self.local(-30), "end_at": self.local(60),
                                                                   "user_ids": self.ids(*extensions), "destination": destination}})

    def published(self):
        out = docker("exec", PBX_CONTAINER, "asterisk", "-rx", f"database show {FAMILY}/{self.id}", capture=True)
        values = {}
        for line in out.splitlines():
            if line.startswith(f"/{FAMILY}/{self.id}/"):
                key, _, value = line[len(f"/{FAMILY}/{self.id}/"):].partition(":")
                values[key.strip()] = value.strip()
        return values

    def wait_published(self, start, dial=None, timeout=20):
        """Waits until the worker has published the expected answer for the probe schedule."""
        deadline = time.time() + timeout
        values = {}
        while time.time() < deadline:
            values = self.published()
            if values.get("start") == start and (dial is None or values.get("dial") == dial):
                return values
            time.sleep(0.5)
        raise RuntimeError(f"not published as expected (start={start}, dial={dial}): {values}")

    def ensure_route(self):
        """The probe's own inbound route, created through the PBX's REST API (named so CallSim's teardown removes it)."""
        pbx = self.page.pbx
        for r in pbx.items("incoming-routes"):
            if r.get("rulename") == PROBE_ROUTE:
                return int(r["id"])
        route = pbx.default("incoming-routes")
        route.update({"rulename": PROBE_ROUTE, "number": PROBE_DID, "providerid": load_state()["provider_id"],
                      "extension": "voicemail", "timeout": 20})
        return int(pbx.post("/incoming-routes", route)["data"]["id"])

    def route(self, route_id):
        data = self.page.pbx.get(f"/incoming-routes/{route_id}")["data"]
        return {"extension": str(data.get("extension")), "timeout": int(data.get("timeout") or 0)}

    def link_route(self):
        route_id = self.ensure_route()
        if not any(r["id"] == route_id and r["linked_schedule_id"] == self.id for r in self.detail()["routes"]):
            self.page.post("linkRoute", {"id": self.id, "route_id": route_id})
        # Linking reloads the dialplan; a call placed before the reload finishes would follow the old route.
        wait_dialplan("", f"Local/{PROBE_NUMBER}@internal-incoming")
        return route_id


def wait_dialplan(target, text, timeout=30):
    """Waits until `dialplan show <target>` contains `text`: the PBX reloads its dialplan in the background."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        if text in docker("exec", PBX_CONTAINER, "asterisk", "-rx", f"dialplan show {target}".strip(), capture=True):
            return
        time.sleep(1)
    raise RuntimeError(f"the dialplan has no {text!r} after {timeout} s")


def dial_of(*extensions):
    return "&".join(f"Local/{e}@internal-users/n" for e in extensions)


# --------------------------------------------------------------------------- checks

class Checks:
    def __init__(self):
        self.items = []

    def that(self, ok, what):
        self.items.append((bool(ok), what))

    @property
    def ok(self):
        return all(ok for ok, _ in self.items)


def rang(line, timeout):
    try:
        line.ringing(timeout)
        return True
    except TimeoutError:
        return False


def answered(line, timeout):
    try:
        line.established(timeout)
        return True
    except TimeoutError:
        return False


def legs(since):
    return [{k: r.get(k) for k in ("src_num", "dst_num", "disposition", "billsec")} for r in wait_for_legs(since, quiet_seconds=5)]


SCENARIOS = []


def scenario(description):
    def wrap(fn):
        SCENARIOS.append({"name": fn.__name__, "description": description, "run": fn})
        return fn
    return wrap


# --------------------------------------------------------------------------- scenarios

@scenario("Roster cell with 301 and 302: an internal call rings both; 302 answers; each leg has its own call record.")
def roster_rings_together(p, c):
    p.reset()
    p.roster_today("301", "302")
    p.wait_published("1", dial_of("301", "302"))
    since = cdr_max_id()
    a, b1, b2 = Line("305"), Line("301"), Line("302")
    a.dial(PROBE_NUMBER)
    c.that(rang(b1, 8), "301 rings")
    c.that(rang(b2, 3), "302 rings")
    pause(1); b2.answer(); c.that(answered(a, 5), "the caller is connected"); pause(3); a.hangup(); b2.closed()
    rows = legs(since)
    c.that(any(r["dst_num"] == "302" and r["disposition"] == "ANSWERED" for r in rows), "call record: 302 answered")
    c.that(any(r["dst_num"] == "301" and r["disposition"] != "ANSWERED" for r in rows), "call record: 301 not answered")


@scenario("Cover override: 303 covers 301's roster shift; only 303 rings.")
def cover_beats_roster(p, c):
    p.reset()
    p.roster_today("301")
    p.override("cover", "303")
    p.wait_published("1", dial_of("303"))
    a, b1, b3 = Line("305"), Line("301"), Line("303")
    a.dial(PROBE_NUMBER)
    c.that(rang(b3, 8), "303 (cover) rings")
    c.that(not rang(b1, 2), "301 (roster) does not ring")
    a.hangup(); pause(2)


@scenario("301 is on another call: a call to the schedule rings 302 at once.")
def busy_phone_skipped(p, c):
    p.reset()
    p.roster_today("301", "302")
    p.wait_published("1", dial_of("301", "302"))
    x, y = Line("303"), Line("301")
    x.dial("301"); y.ringing(); y.answer(); x.established()
    a, b2 = Line("305"), Line("302")
    t0 = time.time(); a.dial(PROBE_NUMBER)
    c.that(rang(b2, 5), "302 rings")
    c.that(time.time() - t0 < 4, f"302 rang within 4 s ({time.time() - t0:.1f} s)")
    a.hangup(); x.hangup(); pause(2)


@scenario("301 forwards to 303 after 5 s, but on duty its forwarding is skipped: it rings the schedule's full 15 s, "
          "303 never rings, then the backup 304 does.")
def own_forwarding_skipped(p, c):
    p.reset(ring_seconds=15, backup_type="member", backup_user_id=p.users["304"])
    p.override("cover", "301")
    p.wait_published("1", dial_of("301"))
    with forwarding("301", "303", ringlength=5):
        a, b1, b3, b4 = Line("305"), Line("301"), Line("303"), Line("304")
        t0 = time.time(); a.dial(PROBE_NUMBER)
        c.that(rang(b1, 8), "301 rings")
        c.that(not rang(b3, 12), "303 (301's forwarding) does not ring")
        c.that(rang(b4, 10), "304 (backup) rings")
        waited = time.time() - t0
        c.that(waited >= 14, f"301 rang the schedule's 15 s, not its own 5 s (backup after {waited:.0f} s)")
        a.hangup(); pause(2)


@scenario("Inbound, nobody answers: 301 for 6 s, the backup 304 for 6 s, then voicemail answers the caller.")
def no_answer_backup_then_voicemail(p, c):
    p.reset(ring_seconds=6, backup_type="member", backup_user_id=p.users["304"], backup_ring_seconds=6)
    p.link_route()
    p.override("cover", "301")
    p.wait_published("1", dial_of("301"))
    since = cdr_max_id()
    caller, b1, b4 = Line("carrier"), Line("301"), Line("304")
    t0 = time.time(); caller.dial(PROBE_DID)
    c.that(rang(b1, 8), "301 rings")
    c.that(not answered(caller, 4), "the caller is not answered while 301 rings")
    c.that(rang(b4, 8), "304 (backup) rings")
    c.that(answered(caller, 15), f"voicemail answers, after {time.time() - t0:.0f} s")
    pause(3); caller.hangup(); pause(2)
    c.that(any(r["dst_num"] == "voicemail" and r["disposition"] == "ANSWERED" for r in legs(since)), "call record: voicemail answered")


@scenario("Covered time with nobody planned (an empty roster cell): the backup 304 rings first.")
def gap_goes_to_backup(p, c):
    p.reset(backup_type="member", backup_user_id=p.users["304"])
    p.roster_today()
    p.wait_published("2")
    a, b4 = Line("305"), Line("304")
    a.dial(PROBE_NUMBER)
    c.that(rang(b4, 5), "304 (backup) rings")
    a.hangup(); pause(2)


@scenario("Nothing planned for this time: calls go to the outside-hours destination, the CallSim queue.")
def outside_hours_destination(p, c):
    p.reset(outside_number=QUEUE)
    p.wait_published("out")
    a, q = Line("305"), Line("302")
    a.dial(PROBE_NUMBER)
    c.that(rang(q, 8), "queue member 302 rings")
    a.hangup(); pause(2)


@scenario("Send elsewhere: calls go to the queue instead of the person on the roster.")
def send_elsewhere(p, c):
    p.reset(outside_number=QUEUE)
    p.roster_today("301")
    p.override("elsewhere", destination=QUEUE)
    p.wait_published("go")
    a, b1, q = Line("305"), Line("301"), Line("302")
    a.dial(PROBE_NUMBER)
    c.that(rang(q, 8), "queue member 302 rings")
    c.that(not rang(b1, 1), "301 does not ring")
    a.hangup(); pause(2)


@scenario("Backup is a queue: after 301's 6 s the call is handed to the queue and its members ring.")
def queue_backup(p, c):
    p.reset(ring_seconds=6, backup_type="queue", backup_number=QUEUE)
    p.override("cover", "301")
    p.wait_published("1", dial_of("301"))
    a, b1, q = Line("305"), Line("301"), Line("303")
    t0 = time.time(); a.dial(PROBE_NUMBER)
    c.that(rang(b1, 8), "301 rings")
    c.that(rang(q, 12), f"queue member 303 rings, after {time.time() - t0:.0f} s")
    a.hangup(); pause(2)


@scenario("Inbound on a linked route: an answered call stays up past the route's timeout.")
def answered_call_outlives_route_timeout(p, c):
    p.reset(ring_seconds=5)
    route_id = p.link_route()
    timeout = p.route(route_id)["timeout"]
    p.override("cover", "302")
    p.wait_published("1", dial_of("302"))
    caller, b2 = Line("carrier"), Line("302")
    caller.dial(PROBE_DID)
    c.that(rang(b2, 8), "302 rings")
    b2.answer(); c.that(answered(caller, 5), "the caller is connected")
    try:
        caller.closed(timeout + 5)
        c.that(False, f"the call was cut off within the route's {timeout} s timeout")
    except TimeoutError:
        c.that(True, f"the call is still up after {timeout + 5} s (route timeout {timeout} s)")
    caller.hangup(); b2.closed(); pause(2)


@scenario("301 answers a direct inbound call and blind-transfers it to the schedule; 302, on duty, rings.")
def transfer_to_schedule(p, c):
    p.reset()
    p.override("cover", "302")
    p.wait_published("1", dial_of("302"))
    caller, b1, b2 = Line("carrier"), Line("301"), Line("302")
    caller.dial(DID_DIRECT); b1.ringing(); b1.answer(); caller.established(); pause(2)
    b1.transfer(PROBE_NUMBER)
    c.that(rang(b2, 10), "302 rings after the transfer")
    b2.answer(); pause(2); caller.hangup(); b2.closed(); pause(2)


@scenario("The published answer has expired and names the wrong person: the live lookup rings 302 from the plan.")
def expired_answer_live_lookup(p, c):
    p.reset()
    p.override("cover", "302")
    p.wait_published("1", dial_of("302"))
    docker("exec", PBX_CONTAINER, "asterisk", "-rx", f"database put {FAMILY} {p.id}/until 1", capture=True)
    docker("exec", PBX_CONTAINER, "asterisk", "-rx", f"database put {FAMILY} {p.id}/dial {dial_of('301')}", capture=True)
    a, b1, b2 = Line("305"), Line("301"), Line("302")
    a.dial(PROBE_NUMBER)
    c.that(rang(b2, 8), "302 (from the plan) rings")
    c.that(not rang(b1, 1), "301 (from the expired answer) does not ring")
    a.hangup(); pause(2)


@scenario("Greeting: the caller hears it as early media and is not answered until 301 picks up.")
def greeting_keeps_call_unanswered(p, c):
    sounds = p.options["sounds"]
    if not sounds:
        c.that(False, "no custom sound file on the PBX to use as a greeting")
        return
    p.reset(greeting_sound_id=sounds[0]["id"])
    p.link_route()
    p.override("cover", "301")
    p.wait_published("1", dial_of("301"))
    caller, b1 = Line("carrier"), Line("301")
    caller.dial(PROBE_DID)
    c.that(rang(b1, 15), "301 rings after the greeting")
    c.that(not answered(caller, 2), "the caller is not answered before 301 picks up")
    b1.answer(); c.that(answered(caller, 5), "the caller is connected"); pause(2); caller.hangup(); b1.closed(); pause(2)


@scenario("Test call from the page: 305 rings first; once answered, the schedule's chain rings 303.")
def test_call(p, c):
    p.reset()
    p.override("cover", "303")
    p.wait_published("1", dial_of("303"))
    a, b3 = Line("305"), Line("303")
    p.page.post("testCall", {"id": p.id, "extension": "305"})
    c.that(rang(a, 10), "305 rings first")
    a.answer()
    c.that(rang(b3, 10), "303 (on duty) rings")
    b3.answer(); pause(2); a.hangup(); b3.closed(); pause(2)


@scenario("Linking a route raises its timeout and points it at the schedule; unlinking restores both.")
def link_unlink_restores_route(p, c):
    p.reset(ring_seconds=10, backup_type="member", backup_user_id=p.users["304"], backup_ring_seconds=10)
    route_id = p.ensure_route()
    for r in p.detail()["routes"]:
        if r["id"] == route_id and r["linked_schedule_id"] == p.id:
            p.page.post("unlinkRoute", {"id": p.id, "route_id": route_id})
    before = p.route(route_id)
    p.page.post("linkRoute", {"id": p.id, "route_id": route_id})
    linked = p.route(route_id)
    c.that(linked["extension"] == PROBE_NUMBER, f"linked: points at {PROBE_NUMBER} ({linked['extension']})")
    c.that(linked["timeout"] >= 10 + 10 + 30, f"linked: timeout raised to fit the chain ({linked['timeout']} s)")
    restored = p.page.post("unlinkRoute", {"id": p.id, "route_id": route_id})["restored"]
    after = p.route(route_id)
    c.that(restored and after == before, f"unlinked: back to {before} ({after})")


@scenario("Deleting a schedule is refused while a route set by hand still points at it, and allowed once it doesn't.")
def delete_refused_while_routed(p, c):
    page, pbx = p.page, p.page.pbx
    spare = next((i["schedule"]["id"] for i in page.get("overview")["schedules"] if i["schedule"]["number"] == SPARE_NUMBER), None)
    if spare is None:
        spare = page.post("createSchedule", {"name": f"{NAME} spare", "number": SPARE_NUMBER, "template": "blank"})["id"]
    route = next(r for r in pbx.items("incoming-routes") if r.get("number") == DID_CONF)
    original = str(route["extension"])
    pbx.patch(f"/incoming-routes/{route['id']}", {"extension": SPARE_NUMBER})
    try:
        message = page.post("deleteSchedule", {"id": spare}, expect_refusal=True)
        c.that(route.get("rulename", "") in message, f"refused, naming the route: {message}")
    finally:
        pbx.patch(f"/incoming-routes/{route['id']}", {"extension": original})
    page.post("deleteSchedule", {"id": spare})
    c.that(all(i["schedule"]["number"] != SPARE_NUMBER for i in page.get("overview")["schedules"]), "deleted once nothing routes to it")


# --------------------------------------------------------------------------- running

def hang_up_all():
    for name in [*callsim.PHONES, "carrier"]:
        try:
            Line(name).hangup()
        except Exception:  # noqa: BLE001 - best effort between scenarios
            pass


def run(names):
    if not callsim.STATE_FILE.exists():
        sys.exit("Run `callsim.py setup` first.")
    unknown = set(names) - {s["name"] for s in SCENARIOS}
    if unknown:
        sys.exit(f"Unknown scenario(s): {', '.join(sorted(unknown))}")
    chosen = [s for s in SCENARIOS if not names or s["name"] in names]
    failed = []
    with callsim_routes_in_hours():
        probe = Probe()
        for s in chosen:
            print(f"{s['name']}: {s['description']}")
            checks = Checks()
            try:
                s["run"](probe, checks)
            except Exception as e:  # noqa: BLE001 - report and carry on with the next scenario
                checks.that(False, f"error: {e}")
                hang_up_all()
                pause(3)
            for ok, what in checks.items:
                print(f"  {'PASS' if ok else 'FAIL'}  {what}")
            if not checks.ok:
                failed.append(s["name"])
        probe.reset()
    print(f"\n{len(chosen) - len(failed)} of {len(chosen)} scenarios passed.")
    if failed:
        print("Failed: " + ", ".join(failed))
        sys.exit(1)


def cleanup():
    """Unlinks and deletes the probe schedules; the probe route is removed by `callsim.py teardown`."""
    page = Page()
    for item in page.get("overview")["schedules"]:
        if item["schedule"]["number"] in (PROBE_NUMBER, SPARE_NUMBER):
            page.post("deleteSchedule", {"id": item["schedule"]["id"]})
            print(f"Deleted schedule {item['schedule']['number']}.")


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "run":
        run(sys.argv[2:])
    elif cmd == "list":
        for s in SCENARIOS:
            print(f"{s['name']:40} {s['description']}")
    elif cmd == "cleanup":
        cleanup()
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
