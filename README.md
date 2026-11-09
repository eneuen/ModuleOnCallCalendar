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

## Using it

Open **Modules → On Call Calendar** (administrators only).

1. **New schedule:** choose a template, a name and a number, and tick the incoming routes this schedule should answer. Shift duty starts with Morning and Afternoon shifts on weekdays; On-call starts with a rotation in the evenings, at night and at weekends.
2. **Plan who is on duty:** fill the **Roster** grid (who works which shift on which weekday; several people in one cell ring together), or set up the **Rotation** (people take turns every few days). A roster can repeat over up to four weeks for alternating patterns.
3. **Handle exceptions on the Calendar:** click a shift to have someone cover it, swap it with someone else's shift, or send its calls elsewhere; drag across empty time to add one. Changes take effect within seconds, without reloading anything.

The home page shows who is on duty on every line right now, who is next, and where nobody is planned.

## Incoming routes

Linking a route from the module (in the wizard or under **Settings**) points it at the schedule's number and remembers what it pointed at before; **Unlink** puts it back. The module also raises the route's timeout so the whole chain fits: MikoPBX stops a route's call at that timeout and moves on to the route's next rule.

Off-work time rules, IVR menu options and employees' forwarding can send calls to a schedule's number too; set those on their own pages. A typical on-call setup keeps the daytime route as it is and points an off-work time rule at the on-call schedule.

Before disabling or deleting the module, unlink its routes and change anything else that points at a schedule's number: while the module is disabled, those calls fail.

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
