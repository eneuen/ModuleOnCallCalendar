#!/usr/bin/env python3
"""Real SIP phones and a fake carrier around a disposable MikoPBX test instance.

Sets up softphones for extensions 301-305 and a carrier on their own Docker
network, plus the PBX objects they need, so probe.py can place real calls
through the module's schedules.

  callsim.py setup      create the test network, phones, carrier and PBX objects
  callsim.py teardown   remove the containers and the PBX objects it created

Only use it against a throwaway test PBX: it creates extensions, a queue, an
IVR menu, a conference room, a provider and routes, all named "CallSim ...".

Environment:
  MIKOPBX_URL             web interface (default https://localhost)
  MIKOPBX_ADMIN_PASSWORD  admin password (required)
  MIKOPBX_CONTAINER       PBX container name (default mikopbx-dev)
"""
import json
import os
import re
import secrets
import ssl
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

from ctl import Phone

HERE = Path(__file__).resolve().parent
STATE_FILE = HERE / ".callsim-state.json"           # local only, never committed

PBX_URL = os.environ.get("MIKOPBX_URL", "https://localhost").rstrip("/")
PBX_CONTAINER = os.environ.get("MIKOPBX_CONTAINER", "mikopbx-dev")
CDR_DB = "/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db"

# The test network's addresses are chosen by Docker, not fixed here, so CallSim
# fits any machine. The PBX (host networking) is reached at the network's
# gateway unless CALLSIM_PBX_SIP_HOST says otherwise.
NETWORK = "callsim"
PBX_SIP_HOST = os.environ.get("CALLSIM_PBX_SIP_HOST", "")
IMAGE = "callsim"

PHONES = ["301", "302", "303", "304", "305"]
CTRL_PORT = {"301": 4461, "302": 4462, "303": 4463, "304": 4464, "305": 4465, "carrier": 4470}
QUEUE = "3100"                  # members 302 and 303, ring all
IVR = "3200"                    # 1 -> queue, 2 -> 304, 3 -> voicemail; timeout -> queue
DID_MAIN = "5551000"            # carrier -> IVR
DID_DIRECT = "5551001"          # carrier -> 301
DID_CONF = "5551002"            # carrier -> conference room
CONF_ROOM = "3300"              # conference room, no PIN
PARK_FEATURE = "*2"             # MikoPBX default: park the other party during a call
OUTBOUND_PREFIX = "555"         # 555 + 7 digits -> carrier
CALLER = "5550000100"           # caller ID the carrier presents; dialable back via the outbound route
OUTBOUND_NUMBER = OUTBOUND_PREFIX + "1234567"   # the number outbound scenarios dial
NAME = "CallSim"


# --------------------------------------------------------------------------- REST

class Pbx:
    def __init__(self):
        password = os.environ.get("MIKOPBX_ADMIN_PASSWORD")
        if not password:
            sys.exit("Set MIKOPBX_ADMIN_PASSWORD to the test PBX's admin password.")
        # The test PBX uses a self-signed certificate.
        self.tls = ssl.create_default_context()
        self.tls.check_hostname = False
        self.tls.verify_mode = ssl.CERT_NONE
        res = self._request("POST", "/auth:login", {"login": "admin", "password": password}, auth=False)
        self.token = res["data"]["accessToken"]

    def _request(self, method, path, data=None, auth=True):
        url = f"{PBX_URL}/pbxcore/api/v3{path}"
        body = None
        headers = {"Accept": "application/json"}
        if data is not None:
            body = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
        if auth:
            headers["Authorization"] = f"Bearer {self.token}"
        req = urllib.request.Request(url, data=body, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, context=self.tls, timeout=60) as r:
                return json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            detail = e.read().decode(errors="replace")[:500]
            raise RuntimeError(f"{method} {path}: HTTP {e.code}: {detail}") from None

    def get(self, path):
        return self._request("GET", path)

    def post(self, path, data):
        return self._request("POST", path, data)

    def patch(self, path, data):
        return self._request("PATCH", path, data)

    def delete(self, path):
        return self._request("DELETE", path)

    def items(self, resource):
        data = self.get(f"/{resource}?limit=1000")["data"]
        if isinstance(data, dict):
            data = data.get("data") or data.get("items") or []
        return data

    def default(self, resource):
        return self.get(f"/{resource}:getDefault")["data"]


def docker(*args, check=True, capture=False):
    return subprocess.run(["docker", *args], check=check, text=True,
                          capture_output=capture).stdout


# --------------------------------------------------------------------------- setup

def load_state():
    return json.loads(STATE_FILE.read_text()) if STATE_FILE.exists() else {}


def network_gateway():
    out = docker("network", "inspect", NETWORK, "--format", "{{range .IPAM.Config}}{{.Gateway}} {{end}}", capture=True)
    gateways = out.split()
    if not gateways:
        sys.exit(f"Docker network {NETWORK} has no gateway; set CALLSIM_PBX_SIP_HOST to the PBX address.")
    return gateways[0]


def container_ip(name):
    out = docker("inspect", "--format", f'{{{{(index .NetworkSettings.Networks "{NETWORK}").IPAddress}}}}', name, capture=True)
    return out.strip()


def setup():
    pbx = Pbx()
    previous = load_state()
    state = {"secrets": {n: secrets.token_urlsafe(12) for n in PHONES}}
    # Stop old phones first, while their passwords still work, so they unregister.
    stop_containers()
    remove_pbx_objects(pbx, previous)

    # Test network and carrier container first: Docker picks free addresses,
    # and the carrier's provider below is created for the carrier's address.
    # The carrier never registers, so starting it early can't trip fail2ban.
    docker("build", "-q", "-t", IMAGE, str(HERE), capture=True)
    if NETWORK not in docker("network", "ls", "--format", "{{.Name}}", capture=True).split():
        docker("network", "create", NETWORK, capture=True)
    state["pbx_ip"] = PBX_SIP_HOST or network_gateway()
    docker("run", "-d", "--name", "callsim-carrier", "--network", NETWORK,
           "-p", f"127.0.0.1:{CTRL_PORT['carrier']}:{CTRL_PORT['carrier']}",
           IMAGE, f"carrier={CALLER},{OUTBOUND_NUMBER}:none@:{CTRL_PORT['carrier']}", capture=True)
    state["carrier_ip"] = container_ip("callsim-carrier")
    # Saved now, so teardown can find everything even if a step below fails.
    STATE_FILE.write_text(json.dumps(state, indent=2))
    os.chmod(STATE_FILE, 0o600)

    # Extensions 301-305, named "CallSim 301" etc.
    taken = {e["number"] for e in pbx.items("employees")} & set(PHONES)
    if taken:
        sys.exit(f"Extension(s) {', '.join(sorted(taken))} already exist and are not CallSim's.")
    for number in PHONES:
        emp = pbx.default("employees")
        emp.update({"number": number, "user_username": f"{NAME} {number}",
                    "sip_secret": state["secrets"][number], "sip_enableRecording": False})
        pbx.post("/employees", {k: v for k, v in emp.items() if not k.endswith("_represent")})

    # Carrier: a SIP provider without registration, matched by its IP.
    prov = pbx.default("sip-providers")
    prov.update({"description": f"{NAME} carrier", "registration_type": "none",
                 "host": state["carrier_ip"], "port": 5060, "qualify": False,
                 # getDefault returns "UDP", but the validator accepts only lowercase.
                 "transport": "udp"})
    prov = pbx.post("/sip-providers", prov)["data"]
    state["provider_id"] = prov["id"]

    queue = pbx.default("call-queues")
    queue.update({"name": f"{NAME} queue", "extension": QUEUE, "strategy": "ringall",
                  "seconds_to_ring_each_member": 15,
                  "members": [{"extension": "302", "priority": 1}, {"extension": "303", "priority": 2}]})
    pbx.post("/call-queues", queue)

    ivr = pbx.default("ivr-menu")
    ivr.update({"name": f"{NAME} IVR", "extension": IVR, "timeout": "5", "number_of_repeat": "1",
                "timeout_extension": QUEUE,
                "actions": [{"digits": "1", "extension": QUEUE}, {"digits": "2", "extension": "304"},
                            {"digits": "3", "extension": "voicemail"}]})
    ivr.pop("represent", None)
    pbx.post("/ivr-menu", ivr)

    conf = pbx.default("conference-rooms")
    conf.update({"name": f"{NAME} conference", "extension": CONF_ROOM, "pinCode": ""})
    pbx.post("/conference-rooms", {k: v for k, v in conf.items() if not k.endswith("represent")})

    for did, target in ((DID_MAIN, IVR), (DID_DIRECT, "301"), (DID_CONF, CONF_ROOM)):
        route = pbx.default("incoming-routes")
        route.update({"rulename": f"{NAME} {did}", "number": did, "providerid": state["provider_id"],
                      "extension": target, "timeout": 60})
        pbx.post("/incoming-routes", route)

    out = pbx.default("outbound-routes")
    out.update({"rulename": f"{NAME} outbound", "providerid": state["provider_id"],
                "numberbeginswith": OUTBOUND_PREFIX, "restnumbers": 7, "priority": 1})
    out.pop("represent", None)
    pbx.post("/outbound-routes", out)

    STATE_FILE.write_text(json.dumps(state, indent=2))
    os.chmod(STATE_FILE, 0o600)

    # Phones last, once their extensions exist: registering to a missing
    # extension fails, and repeated failures get the address banned by the
    # PBX's fail2ban.
    phone_args, ports = [], []
    for n in PHONES:
        phone_args.append(f"{n}={n}:{state['secrets'][n]}@{state['pbx_ip']}:{CTRL_PORT[n]}")
        ports += ["-p", f"127.0.0.1:{CTRL_PORT[n]}:{CTRL_PORT[n]}"]
    docker("run", "-d", "--name", "callsim-phones", "--network", NETWORK,
           *ports, IMAGE, *phone_args, capture=True)

    print("Waiting for the phones to register...")
    deadline = time.time() + 60
    while time.time() < deadline:
        out = docker("exec", PBX_CONTAINER, "asterisk", "-rx", "pjsip show contacts", capture=True)
        if all(f"{n}/" in out for n in PHONES):
            extra = [n for n in PHONES if out.count(f"Contact:  {n}/") > 1]
            if extra:
                sys.exit(f"Extension(s) {', '.join(extra)} have stale registrations; "
                         "wait for them to expire (about 2 minutes) and run setup again.")
            print("All phones registered.")
            return
        time.sleep(2)
    sys.exit("Phones did not register within 60 s; check `docker logs callsim-phones`.")


def _delete_named(pbx, resource, field):
    for item in pbx.items(resource):
        if str(item.get(field, "")).startswith(NAME):
            pbx.delete(f"/{resource}/{item['id']}")


def remove_pbx_objects(pbx, state):
    """Deletes everything CallSim created, users of an object before the object."""
    for resource, field in (("incoming-routes", "rulename"), ("outbound-routes", "rulename"),
                            ("ivr-menu", "name"), ("call-queues", "name"), ("conference-rooms", "name")):
        _delete_named(pbx, resource, field)
    # The provider list doesn't return the description, so match the provider
    # CallSim recorded, by id or by the carrier address it was created for.
    for p in pbx.items("sip-providers"):
        if (state.get("provider_id") and p.get("id") == state["provider_id"]) \
                or (state.get("carrier_ip") and p.get("host") == state["carrier_ip"]):
            pbx.delete(f"/sip-providers/{p['id']}")
    for emp in pbx.items("employees"):
        if emp["number"] in PHONES and str(emp.get("user_username", "")).startswith(NAME):
            pbx.delete(f"/employees/{emp['id']}")


def stop_containers():
    """Stops the phones gracefully so they unregister, then removes them.

    A phone killed without unregistering leaves its contact on the PBX until
    it expires, and the next call to that extension rings it as a second
    device, adding a stray leg to the fixture.
    """
    for name in ("callsim-phones", "callsim-carrier"):
        docker("stop", "-t", "15", name, check=False, capture=True)
        docker("rm", "-f", name, check=False, capture=True)


def teardown():
    stop_containers()
    docker("network", "rm", NETWORK, check=False, capture=True)
    pbx = Pbx()
    restore_offwork_rules(pbx)
    remove_pbx_objects(pbx, load_state())
    STATE_FILE.unlink(missing_ok=True)
    print("Removed the CallSim containers, network and PBX objects.")


# --------------------------------------------------------------------------- calls

class Line:
    """One phone (or the carrier) under test, driven over baresip's ctrl_tcp."""

    def __init__(self, name):
        self.name = name
        self.ctl = Phone("127.0.0.1", CTRL_PORT[name])

    def dial(self, number):
        if self.name == "carrier":
            # Place the call from the caller-ID account, straight to the PBX.
            self.ctl.command("uafind", f"sip:{CALLER}@127.0.0.1:5060")
            self.ctl.command("dial", f"sip:{number}@{load_state()['pbx_ip']}:5060")
        else:
            self.ctl.command("dial", number)

    def ringing(self, timeout=20):
        return self.ctl.wait_event("CALL_INCOMING", timeout)

    def answer(self):
        self.ctl.command("accept")

    def established(self, timeout=20):
        return self.ctl.wait_event("CALL_ESTABLISHED", timeout)

    def hangup(self):
        self.ctl.command("hangup")

    def closed(self, timeout=60):
        return self.ctl.wait_event("CALL_CLOSED", timeout)

    def command(self, cmd, params=""):
        return self.ctl.command(cmd, params)

    def keys(self, digits, gap=0.4):
        """Sends DTMF digits one by one (RFC 2833), as a phone keypad would."""
        for d in digits:
            self.ctl.command("sndcode", d)
            time.sleep(gap)

    def transfer(self, number):
        """Blind transfer with SIP REFER, as a phone's transfer button does."""
        self.ctl.command("transfer", number)


def pause(seconds):
    time.sleep(seconds)


# --------------------------------------------------------------------------- call records

def cdr_rows(since_id):
    sql = f"SELECT * FROM cdr_general WHERE id > {int(since_id)} ORDER BY id"
    out = docker("exec", PBX_CONTAINER, "sqlite3", "-json", CDR_DB, sql, capture=True)
    return json.loads(out) if out.strip() else []


def cdr_max_id():
    out = docker("exec", PBX_CONTAINER, "sqlite3", CDR_DB,
                 "SELECT COALESCE(MAX(id), 0) FROM cdr_general", capture=True)
    return int(out.strip() or 0)


def wait_for_legs(since_id, quiet_seconds=8, timeout=120):
    """Waits until new legs have arrived and no more have come for a while."""
    deadline = time.time() + timeout
    last_count, last_change = -1, time.time()
    while time.time() < deadline:
        rows = cdr_rows(since_id)
        if len(rows) != last_count:
            last_count, last_change = len(rows), time.time()
        elif rows and time.time() - last_change >= quiet_seconds:
            return rows
        time.sleep(2)
    return cdr_rows(since_id)


def pbx_version():
    out = docker("exec", PBX_CONTAINER, "cat", "/etc/version", check=False, capture=True)
    return out.strip() or "unknown"


# --------------------------------------------------------------------------- scenario helpers

class forwarding:
    """Turns on call forwarding for one extension for the length of a scenario.

    MikoPBX forwards an extension when it rings unanswered for `ringlength`
    seconds (fwd_forwarding), when it is busy, or when it is unavailable. An
    outside number can only be a forwarding target when it is an employee's
    mobile number, so `mobile` sets the extension's own mobile first (the
    usual "forward to my mobile"). The PBX reloads its dialplan after a
    change, so wait before calling.
    """

    def __init__(self, extension, target, ringlength=8, mobile=None):
        self.extension, self.target, self.ringlength, self.mobile = extension, target, ringlength, mobile

    def _employee(self, pbx):
        for emp in pbx.items("employees"):
            if emp["number"] == self.extension:
                return emp
        raise RuntimeError(f"No employee {self.extension}")

    def __enter__(self):
        self.pbx = Pbx()
        emp = self._employee(self.pbx)
        change = {"fwd_forwarding": self.target, "fwd_ringlength": self.ringlength}
        if self.mobile:
            change.update({"mobile_number": self.mobile, "mobile_dialstring": self.mobile})
        self.pbx.patch(f"/employees/{emp['id']}", change)
        pause(8)
        return self

    def __exit__(self, *exc):
        emp = self._employee(self.pbx)
        change = {"fwd_forwarding": "", "fwd_ringlength": 0}
        if self.mobile:
            change.update({"mobile_number": "", "mobile_dialstring": ""})
        self.pbx.patch(f"/employees/{emp['id']}", change)
        pause(8)
        return False


class callsim_routes_in_hours:
    """Keeps the PBX's out-of-hours rules off CallSim's own inbound routes during a run.

    MikoPBX applies an out-of-hours rule to every inbound route unless the rule
    is restricted (allowRestriction) to a list of routes. For the run, each rule
    is restricted to the PBX's other routes, so CallSim's DIDs always reach
    their targets while every other route behaves as before. The original
    rules are saved in the state file first and put back afterwards, or by
    teardown if a run is interrupted.
    """

    def __enter__(self):
        self.pbx = Pbx()
        routes = self.pbx.items("incoming-routes")
        ours = {str(r["id"]) for r in routes if str(r.get("rulename", "")).startswith(NAME)}
        others = [str(r["id"]) for r in routes if str(r["id"]) not in ours]
        self.saved = []
        for rule in self.pbx.items("off-work-times"):
            full = self.pbx.get(f"/off-work-times/{rule['id']}")["data"]
            original = {"allowRestriction": bool(full.get("allowRestriction")),
                        "incomingRouteIds": [str(i) for i in full.get("incomingRouteIds") or []]}
            if original["allowRestriction"]:
                keep = [i for i in original["incomingRouteIds"] if i not in ours]
                if keep == original["incomingRouteIds"]:
                    continue
                change = {"allowRestriction": True, "incomingRouteIds": keep}
            else:
                change = {"allowRestriction": True, "incomingRouteIds": others}
            self.saved.append({"id": str(rule["id"]), **original})
            self.pbx.patch(f"/off-work-times/{rule['id']}", change)
        if self.saved:
            state = load_state()
            state["offwork_restore"] = self.saved
            STATE_FILE.write_text(json.dumps(state, indent=2))
            print(f"Out-of-hours rules kept off CallSim's routes for this run ({len(self.saved)} rule(s)).")
            pause(8)
        return self

    def __exit__(self, *exc):
        restore_offwork_rules(self.pbx)
        return False


def restore_offwork_rules(pbx):
    """Puts back the out-of-hours rules saved by callsim_routes_in_hours."""
    state = load_state()
    saved = state.pop("offwork_restore", [])
    for rule in saved:
        pbx.patch(f"/off-work-times/{rule['id']}",
                  {"allowRestriction": rule["allowRestriction"], "incomingRouteIds": rule["incomingRouteIds"]})
    if saved:
        if STATE_FILE.exists():
            STATE_FILE.write_text(json.dumps(state, indent=2))
        print(f"Out-of-hours rules restored ({len(saved)} rule(s)).")


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "setup":
        setup()
    elif cmd == "teardown":
        teardown()
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
