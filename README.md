# On Call Calendar for MikoPBX

**Module ID:** `ModuleOnCallCalendar`

A MikoPBX extension module that decides, from a calendar, who answers each phone line and when, and what happens if that person doesn't pick up. It covers two everyday needs with one design:

- **Shift duty:** "The Service line rings Anna in the morning and Ben in the afternoon. If they don't answer, it goes to the Service queue."
- **On-call:** "After hours, the emergency number rings whichever technician is on call this week. If they don't answer, it rings the backup, then voicemail."

> **Status: in development.** The module is not ready for use yet. What changed is in [CHANGELOG.md](CHANGELOG.md).

## How it works

Each **schedule** gets its own internal number. Incoming routes, off-work time rules, IVR menu options and colleagues send calls to that number like to any other destination; it appears in MikoPBX's destination lists as "Module: <schedule name> (<number>)".

When a call arrives, the module rings whoever is on duty at that moment. If nobody answers, the call follows the schedule's if-no-answer chain:

1. **The person or people on duty**, ringing together for the schedule's ring time. Their own call forwarding and voicemail are skipped for this call, so the backup always gets its turn. Busy or offline phones are skipped at once.
2. **A backup** (optional): another person, rung the same way, or a queue. A queue takes over the call from there, and its own timeout and if-empty settings decide what follows.
3. **A final destination:** MikoPBX's voicemail, an IVR menu, a queue or any other number.

Calls rung by the module are ordinary MikoPBX calls: each leg appears in call history and is recorded under the PBX's recording settings.

Who is on duty is kept in Asterisk's database and read when a call arrives, so changing the plan never reloads the dialplan. If that information is out of date, the call goes to the backup and then the final destination rather than to someone who may no longer be on duty.

## Linking an incoming route

When an incoming route sends calls to a schedule's number, set the route's ring timeout longer than the whole chain (step 1 plus a person backup's ring time). MikoPBX stops the route's call at that timeout and moves on to its next rule.

## Requirements

- MikoPBX 2026.6.34 or later.

## Development

```bash
composer install
composer test        # unit tests
composer lint        # PHP syntax check
```

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
