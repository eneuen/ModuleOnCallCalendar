# CallSim: real-call checks for On Call Calendar

CallSim places real SIP calls through a disposable MikoPBX test instance, so the module's routing is checked the way callers meet it: which phones ring, when the caller is answered, and what the PBX records. `probe.py` runs scenarios against a probe schedule it sets up through the module page's own actions, as an admin's browser does.

> Use a throwaway test PBX only. CallSim creates extensions 301-305, a queue, an IVR menu, a conference room, a SIP provider and routes, all named "CallSim ...", and the probe creates schedules 2290 and 2291 and the inbound DID 5551090. Each probe run replaces the probe schedule's plan.

## How it works

- **Phones:** a container on a private Docker network runs one [baresip](https://github.com/baresip/baresip) softphone per extension (301-305). The softphones register with the PBX and are driven over baresip's `ctrl_tcp` control port.
- **Carrier:** a second container, on its own IP address, plays the phone company. The PBX knows it as a SIP provider without registration, matched by that IP. It places inbound calls to any DID.
- **Driver:** `callsim.py` creates the PBX objects through the REST API and starts the containers. `probe.py` logs in as the admin, calls the module page's JSON actions with the admin's Bearer token, places the calls, and reads the call records and the module's AstDB values inside the PBX container.

Test layout on the PBX:

| Object | Number | Behaviour |
| --- | --- | --- |
| Extensions | 301-305 | Plain extensions |
| Queue | 3100 | Rings 302 and 303 together; the caller hears ringing |
| IVR menu | 3200 | 1 to the queue, 2 to 304, 3 to voicemail |
| DID 5551001 | from the carrier | Goes straight to 301 |
| DID 5551002 | from the carrier | Goes to the conference room 3300 |
| DID 5551090 | from the carrier | The probe's own route, linked to the probe schedule by scenarios that call in |
| Schedule 2290 | "CallSim probe" | The probe schedule; each scenario sets its plan and chain |

**Out-of-hours rules.** MikoPBX sends inbound calls to its out-of-hours handling outside working hours, for every inbound route unless a rule is restricted to some routes. So that `run` works at any time, the probe restricts each rule to the PBX's *other* inbound routes for the length of the run and puts the rules back afterwards. The originals are saved in the state file first, and `teardown` restores them if a run was interrupted.

## Requirements

- Docker, and a MikoPBX test container with host networking. CallSim creates a Docker network called `callsim`, lets Docker choose its addresses, and reaches the PBX at that network's gateway. If your PBX is reachable at another address from that network, set `CALLSIM_PBX_SIP_HOST` to it.
- The module installed and enabled on that PBX.
- Python 3.10 or later on the machine that runs the scripts (standard library only).

## Usage

```bash
export MIKOPBX_ADMIN_PASSWORD='<test PBX admin password>'
export MIKOPBX_URL=https://localhost        # default
export MIKOPBX_CONTAINER=mikopbx-dev       # default

python3 tools/callsim/callsim.py setup      # objects, network, phones; waits for registration
python3 tools/callsim/probe.py list         # scenarios
python3 tools/callsim/probe.py run          # all scenarios, or name some
python3 tools/callsim/probe.py cleanup      # delete the probe schedules
python3 tools/callsim/callsim.py teardown   # remove everything CallSim created
```

`setup` generates new SIP passwords each time and keeps them in `.callsim-state.json` next to the script. That file is local and never committed.

A run prints PASS or FAIL for every check and ends with a summary; it exits with status 1 if any scenario failed. Run all scenarios before a release.

## Scenarios

| Scenario | Checks |
| --- | --- |
| `roster_rings_together` | Two people in one roster cell ring together; each leg has its own call record |
| `cover_beats_roster` | A cover override rings the cover, not the person on the roster |
| `busy_phone_skipped` | A phone already on a call is skipped at once |
| `own_forwarding_skipped` | The person on duty's own forwarding is ignored; the backup rings instead |
| `no_answer_backup_then_voicemail` | Inbound: on duty, then the backup, then voicemail; the caller isn't answered before |
| `gap_goes_to_backup` | An empty roster cell sends the call to the backup |
| `outside_hours_destination` | Time no rule covers goes to the outside-hours destination |
| `send_elsewhere` | A "send elsewhere" override sends calls to its destination |
| `queue_backup` | A queue backup gets the call after the on-duty ring time |
| `answered_call_outlives_route_timeout` | An answered inbound call isn't cut off at the route's timeout |
| `transfer_to_schedule` | A blind transfer to the schedule's number rings the person on duty |
| `expired_answer_live_lookup` | With the published answer expired, the live lookup decides from the plan |
| `greeting_keeps_call_unanswered` | The greeting plays without answering the caller |
| `rule_backup_replaces_schedule_backup` | A roster's own backup (a person, or none) replaces the schedule's queue backup |
| `activity_report` | Calls answered on duty, sent to voicemail and given up are logged with who answered and how fast |
| `takeover_by_phone` | A member's \*22 puts them on duty and they get the next call; a non-member's changes nothing |
| `caller_name_prefix` | The phone on duty shows the schedule's prefix before the caller's name |
| `test_call` | The page's test call rings the chosen phone first, then the schedule |
| `link_unlink_restores_route` | Linking points a route at the schedule and raises its timeout; unlinking restores both |
| `delete_refused_while_routed` | A schedule can't be deleted while a route set by hand points at it |

## Files

| File | Purpose |
| --- | --- |
| `callsim.py` | Driver: setup and teardown of the phones, carrier and PBX objects; call and call-record helpers |
| `probe.py` | The scenarios, run against the module through its page's actions |
| `ctl.py` | Small client for baresip's netstring/JSON control protocol |
| `phone.py` | Container entry point: starts one baresip per account |
| `Dockerfile` | Debian image with baresip and Python |

## Notes

- baresip's built-in tone generator only produces 48 kHz stereo, so the audio settings in `phone.py` use that and baresip resamples to the 8 kHz call codec.
- Each baresip binds TLS on its SIP port + 1, so the phones' SIP ports are 10 apart.
- The checks look at signalling (who rang, who answered, call records), not audio.
