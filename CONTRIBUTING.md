# Contributing to On Call Calendar

Thanks for your interest. This guide covers how to report problems, how to set up the code, the checks a change must pass, how the module works, where things live, and how a release is made.

## Reporting problems and suggesting changes

- **Bugs and ideas:** open an issue. For a bug, say which MikoPBX version you run, what you did, what you expected and what happened.
- **Security problems:** please don't open a public issue; follow [SECURITY.md](SECURITY.md).
- **Pull requests:** welcome. Keep each one to a single change, add or update tests where the code allows it, and update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]`. CI must pass.

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
composer check-banned
```

`check-banned` fails if shipped code uses shell or code execution or `unserialize`, or if the browser script or views use `eval`, HTML injection, inline scripts or inline event handlers. Schedule, employee and route names come from admins, so the page only ever inserts them as text.

Static analysis runs PHPStan against the MikoPBX core release the module targets. The core and its dependencies are large (about 240 MB), so they live in their own folder:

```bash
composer install -d tools/phpstan --ignore-platform-req='ext-*'
composer analyse
```

The browser assets are built from `public/assets/js/src/` and `build/fullcalendar-entry.js`, and the built files are committed:

```bash
npm ci
npm run build
```

CI (`.github/workflows/ci.yml`) runs all of the above on every push, runs `npm audit`, and checks that the committed bundles match a fresh build.

Keep code that needs no core classes (the resolver, the publisher's values, the dialplan builder, the editor, the route linker) free of core and Phalcon dependencies, so it stays unit-testable here. Code that needs the MikoPBX core (the hooks, the worker, the AGI script, the controller) is tested on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`. [tools/callsim](tools/callsim/README.md) places real SIP calls through such a test PBX with softphones and a fake carrier, and its `probe.py` checks the module end to end: who rings, when the caller is answered, the call records, AstDB and route linking. Run all its scenarios before a release.

## How a call is routed

Each schedule owns an internal number, registered in the core's extensions as a `MODULES` extension. The core lists such a number in its destination pickers only when an enabled module's model is related to it, which `Models/Schedule::getDynamicRelations()` declares. The module never hooks incoming routes: a route, an off-work time rule, an IVR option or a colleague sends calls to the number like to any other destination.

Who is on duty is not in the dialplan:

1. **Deciding.** `Lib/DutyResolver` decides who is on duty at any moment from the schedule's plan: overrides first (the newest wins), then the first roster or rotation that covers the moment (lowest priority number; inside a roster the shift type lower in the list wins), else outside covered hours. A covered moment with nobody to ring is a gap. Times are wall-clock times in the PBX's time zone.
2. **Publishing.** The worker (`Lib/WorkerOnCallCalendar`) writes each enabled schedule's current answer into AstDB (family `OnCallCalendar`): `start` (1, 2, out or go), `dial`, `goto` and `until`. It publishes at start, every minute, just after an answer stops being valid, and when the page asks after a change. `until` is never more than three minutes ahead.
3. **Ringing.** The number's dialplan (`Lib/DialplanBuilder`) reads those values. A missing or expired answer is asked from `agi-bin/on_call_calendar_lookup.php`, which resolves from the database itself; if that fails too, the call is a gap. The people on duty are rung together through the core's `[internal-users]` context, then the backup person (the same way) or the backup queue (handed over with `Goto`, because a queue that plays music answers at once), then the final destination. Each person's own forwarding is turned off for the call: `FW_DISABLE`/`FW_DISABLE_INTERNAL` stop the core forwarding after its Dial, and a channel variable `FW_TIME_<ext>` shadows their own "forward after N seconds", which the core would otherwise use as the phone's ring time.

Other details the dialplan takes care of:

- It clears `TIMEOUT(absolute)`, which `[internal-incoming]` sets to the route's timeout, so an answered call isn't cut off.
- It clears `pt1c_UNIQUEID`, so every leg gets its own call record.
- It adds the numbers to `[internal-transfer]`, so phones can transfer to them.
- It counts how many schedules a call has passed through (`OCC_HOPS`); after five it goes to voicemail. The editor already refuses destinations that lead back to the same schedule, so this is only a backstop.

A route's own dial timeout still limits the unanswered chain, which is why linking a route raises its timeout.

Roster and override edits only change AstDB values, so they never reload Asterisk. A schedule's number or chain changes reload the dialplan.

The worker writes all of a schedule's keys every minute and checks each write. When an answer changes, it sets `until` to 0 first and writes it last, so a call arriving mid-write asks the live lookup instead of mixing the old and new answer.

## Changing the database

The core creates the module's tables from the model annotations in `Models/` on install, and adds new columns on update. A change it can't make by adding a column (renaming, moving data, new indexes on an existing table) needs a numbered migration step in the installer.

## Layout

| Path | What it holds |
| --- | --- |
| `module.json` | Module manifest: ID, version, minimum MikoPBX version |
| `Lib/OnCallCalendarConf.php` | Hooks the core calls: dialplan, the worker, enable and disable |
| `Lib/DialplanBuilder.php` | Every line of dialplan the module adds; only validated values reach it |
| `Lib/DutyResolver.php`, `Lib/DutySegment.php` | Who is on duty at a moment or over a range |
| `Lib/DutyPublisher.php`, `Lib/WorkerOnCallCalendar.php` | The AstDB values, and the worker that keeps them current and places test calls |
| `agi-bin/on_call_calendar_lookup.php` | Live lookup when the published answer is missing or expired |
| `Lib/ScheduleRepository.php`, `Lib/Employees.php`, `Lib/Databases.php`, `Lib/ModuleContext.php` | Reading schedules, employees and settings |
| `Lib/ScheduleEditor.php`, `Lib/EditorException.php`, `Lib/Templates.php` | Every change, validated and audited; the templates new schedules start from |
| `Lib/RouteLinker.php` | Linking and unlinking incoming routes, and what else routes to a number |
| `Lib/ScheduleNumbers.php` | The schedules' numbers in the core's extensions table |
| `Lib/ScheduleStatus.php` | The home page's now, next and coverage |
| `Lib/Rights.php` | The page's actions grouped into View, Edit duty and Edit settings |
| `Models/` | Tables in the module's database |
| `Setup/PbxExtensionSetup.php` | Installer: sidebar entry, and on uninstall the AGI link and AstDB keys the core leaves behind |
| `App/Controllers/`, `App/Views/` | The module page and its JSON actions, each checked against the role's rights. Reads are AJAX GETs; writes must be AJAX POSTs with a Bearer token that grants the action |
| `public/assets/` | Page script and styles; `js/vendor/` holds the FullCalendar bundle and its licence |
| `Messages/` | Interface text (English and Russian) |
| `build/`, `package.json` | Builds the browser assets with esbuild, and the release zip (`build/package.sh`) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tools/callsim/` | Real-call test harness and the module's probe scenarios |
| `tools/check-banned.php` | Fails the build if shipped code uses a banned function |
| `tools/phpstan/` | PHPStan setup against the MikoPBX core |
| `.gitattributes` | Lists the development files that are left out of every release |

## Packaging a release

This section is for maintainers.

What ships is everything except the development files listed as `export-ignore` in `.gitattributes`: tests, tools, build scripts, script sources, dependency manifests and CI files. That one list applies to every archive git makes, including the release zip and GitHub's "Source code" downloads. Those downloads are for developers only, because `module.json` keeps its `%ModuleVersion%` placeholder until a release is packaged.

To release a version:

1. In `CHANGELOG.md`, move the entries under `## [Unreleased]` into a new section for the version and date, for example `## [1.0.0] - 2026-12-01`, leave `## [Unreleased]` empty above it, and commit.
2. Tag that commit with the version (`1.0.0` or `v1.0.0`) and push the tag.
3. The Release workflow runs every CI check on the tagged commit, builds `ModuleOnCallCalendar-<version>.zip` and its SHA-256 checksum with `build/package.sh`, and attaches them to a **draft** GitHub release, with that version's CHANGELOG section as the notes.
4. Review the draft and publish it.

To build a package locally, for testing:

```bash
build/package.sh 1.0.0-rc.1
```

This writes `dist/ModuleOnCallCalendar-1.0.0-rc.1.zip` and its checksum, built from the committed files of `HEAD` (or a commit given as the second argument), with the version filled into `module.json`.

## License

By contributing, you agree that your contributions are licensed under the project's license, GPL-3.0-or-later.
