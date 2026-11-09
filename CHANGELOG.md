# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **Schedules with their own internal numbers.** Each schedule's number is registered in MikoPBX as a module extension and appears in the destination lists of incoming routes, off-work time rules, IVR menus and forwarding as "Module: <name> (<number>)".
- **The if-no-answer chain in the dialplan.** A schedule's number rings the people on duty through MikoPBX's normal extension dialplan (call history, recording, every registered device, busy handling) with their personal forwarding turned off for that call, then an optional backup person or queue, then a final destination (voicemail by default). Outside covered hours calls go to a separate destination. Phones can transfer callers to a schedule's number.
- **Who is on duty is read from Asterisk's database** when a call arrives, so plan changes never reload the dialplan. A missing or expired answer sends the call to the backup and the final destination.
- **A module page** listing the schedules, for administrators only.
