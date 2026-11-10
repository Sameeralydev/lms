# Academic Calendar Specification

## Data and behavior

Academic years are institution-owned and may optionally reference a campus. They store a name, scoped code, description, academic model, date range, locale/timezone, lifecycle status, current flag, publication state, and actor references. Periods belong to one academic year and store configurable type, sequence, calendar dates, optional teaching/assessment dates, lifecycle, current flag, and publication state.

Supported period types in the interface are semester, term, trimester, quarter, module, annual, and custom. Dates are SQL `DATE` values interpreted using the institution or academic-year timezone; the system does not assume a January–December calendar.

The calendar controller supports create/edit, list filtering by text/status, activate/complete/archive transitions, selecting the current year/period, and copying a year with periods shifted by the new start-date offset. Transactions and row locks protect lifecycle updates and current-record changes. Date validation requires periods to fit inside their parent year and prevents overlapping periods; active years are checked for overlapping ranges at institution/campus scope.

## Authorization

All routes require a verified active tenant membership plus `academic.view`; writes require the specific calendar/period permission. Campus-scoped memberships may use calendar operations only for their assigned campus. Institution-wide years are not exposed to a campus-scoped account. The route middleware is an allowlist; new academic routes default to denied for campus scope.

## Audit/history

Creation, edits, lifecycle transitions, current designation, and copy operations record an `academic.*` audit event. Edit events keep safe before/after fields. Archived years are immutable through the update endpoint. Published historical period/year records are not deleted by the UI.

## Known limitations

There is no printable/exported calendar preview, registration calendar, or separate assessment calendar. Those contracts remain for later academic operations. Current browser evidence exercises create/edit/activate/current designation for one year and period, including mobile overflow and axe checks; broader lifecycle combinations are covered by Laravel feature tests.
