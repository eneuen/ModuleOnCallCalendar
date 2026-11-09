# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **Schedules with their own internal numbers.** Each schedule's number is registered in MikoPBX as a module extension and appears in the destination lists of incoming routes, off-work time rules, IVR menus and forwarding as "Module: <name> (<number>)".
- **The if-no-answer chain in the dialplan.** A schedule's number rings the people on duty through MikoPBX's normal extension dialplan (call history, recording, every registered device, busy handling) with their personal forwarding turned off for that call, then an optional backup person or queue, then a final destination (voicemail by default). Outside covered hours calls go to a separate destination. Phones can transfer callers to a schedule's number.
- **Who is on duty is read from Asterisk's database** when a call arrives, so plan changes never reload the dialplan.
- **Rosters, rotations and overrides.** A roster assigns people to named shift types on weekdays and repeats over 1–4 weeks; a rotation hands duty from one person to the next every few days, optionally only within daily active hours; an override (cover, swap, take over, send elsewhere) beats both for an exact time range. Where shift types overlap, the one lower in the list wins; where a roster and a rotation overlap, the rule with the lower priority number wins. Covered time with nobody to ring is a gap and goes to the backup. Shifts follow wall-clock time in the PBX's time zone, overnight shifts belong to the day they start, and daylight-saving changes make a shift an hour shorter or longer.
- **A worker keeps the answer published**: at start, every minute, right when an answer changes, and immediately after a change on the module page. Each answer expires after at most three minutes, so if the worker stops, calls ask a live lookup (an AGI script) instead; if that fails too, the call goes to the backup and the final destination.
- **The module page** (Modules → On Call Calendar):
  - **Home:** one card per schedule with who is on duty now and until when, what comes next, uncovered hours in the next 14 days, how many places route to it, and "Cover now".
  - **New schedule wizard:** pick a template (Shift duty, On-call or Blank), a name and a free number, tick the incoming routes it should answer, the on-call rotation if any, and the if-no-answer chain.
  - **Calendar** (FullCalendar Standard, month, week and list views) in the PBX's own time zone: planned shifts in each person's colour, overrides solid, gaps striped. Click a shift to cover, swap or redirect it; drag across empty time to add an override; drag or resize overrides to move them.
  - **Roster grid:** shift types as rows, weekdays as columns, one grid per week of the cycle; click a cell to choose who works it. Shift types can be added, edited, deleted and reordered.
  - **Rotation:** people in turn order, first handover, turn length, active-hours windows, an end date, and the next eight handovers.
  - **Settings:** name, on/off, ring times, backup, final and outside-hours destinations, greeting, a test call, which rule wins, linked incoming routes (link, unlink, a warning for routes whose timeout is shorter than the chain), everything else that routes to the number, and deleting the schedule.
  - **Activity:** the audit log, with the changed values.
- **Caller name prefix:** an optional prefix (for example SERVICE) put before the caller's name on the phones a schedule rings, as MikoPBX queues do, so whoever answers knows which line it is.
- **Greeting:** an optional sound file played to the caller before anyone is rung, as early media, so the call is not answered (or charged) until a person picks up; ringing is then played to the caller.
- **Test call** under Settings: rings a chosen phone first and, once answered, calls the schedule through its real chain.
- **Rights for access groups:** View, Edit duty and Edit settings, granted through MikoPBX's access control (MIKO's Access control management module) as the page's actions; the README lists which to tick. Every request is checked against the role's rights, change requests need a token that carries them, and the page shows only the controls a person may use.
- **Linking incoming routes** from the wizard or the settings: the route's destination becomes the schedule's number and its timeout is raised to fit the chain; unlinking puts both back, unless the route was changed by hand since. Deleting a schedule unlinks its routes first and is refused while anything else still routes to its number.
- **Every change is audited** with who made it, and validated on the server whatever the page sends.
- **English and Russian.**
- **For contributors:** a real-call probe (`tools/callsim`) with 16 scenarios run through a test PBX with softphones and a fake carrier; CI on every push (unit tests, lint, a banned-code check, PHPStan against the MikoPBX core, npm audit, and a check that the committed bundles match their sources), a tag-triggered Release workflow that builds the install zip into a draft release, CONTRIBUTING.md and SECURITY.md.
