# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **Schedules with their own internal numbers.** Each schedule's number is registered in MikoPBX as a module extension and appears in the destination lists of incoming routes, off-work time rules, IVR menus and forwarding as "Module: <name> (<number>)".
- **The if-no-answer chain in the dialplan.** A schedule's number rings the people on duty through MikoPBX's normal extension dialplan (call history, recording, every registered device, busy handling) with their personal forwarding turned off for that call, then an optional backup person or queue, then a final destination (voicemail by default). Outside covered hours calls go to a separate destination. Phones can transfer callers to a schedule's number.
- **Who is on duty is read from Asterisk's database** when a call arrives, so plan changes never reload the dialplan.
- **Rosters, rotations and overrides.** A roster assigns people to named shift types on weekdays and repeats over 1–4 weeks; a rotation hands duty from one person to the next every few days, optionally only within daily active hours; an override (cover, swap, take over, send elsewhere) beats both for an exact time range. Where shift types overlap, the one lower in the list wins; where a roster and a rotation overlap, the rule with the lower priority number wins. Covered time with nobody to ring is a gap and goes to the backup. Shifts follow wall-clock time in the PBX's time zone, overnight shifts belong to the day they start, and daylight-saving changes make a shift an hour shorter or longer.
- **A worker keeps the answer published**: at start, every minute, right when an answer changes, and immediately after a change on the module page. Each answer expires after at most three minutes, so if the worker stops, calls ask a live lookup (an AGI script) instead; if that fails too, the call goes to the backup and the final destination.
- **A module page** listing the schedules, for administrators only.
