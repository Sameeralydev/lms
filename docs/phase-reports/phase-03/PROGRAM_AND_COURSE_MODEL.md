# Program and Course Model

## Academic structures

Academic levels and qualification types are institution-configurable. Academic units support optional parent-child structure and campus associations. Programs reference a level and may reference a qualification, unit, credit policy, duration, period model, delivery mode, effective dates, credit-bearing flag, and available campuses. Draft programs can be edited and explicitly activated; published program curriculum and prerequisite graphs are outside this phase.

`academic_classes` is a configurable structural group with a group type (`grade`, `class`, `section_group`, `cohort`, `batch`, `training_cycle`, or `custom`), optional academic year, campus, program, level, structural capacity, schedule label, and dates. Sections belong to a group and have scoped codes and structural capacity. Capacity is a policy/configuration value, not an enrollment count.

## Subject and course catalog

Subjects have institution-scoped codes, category, optional level/unit, description, and status. Courses have a stable identity and optional subject, unit, and level. A course version captures title, credit-bearing state, theory/laboratory/total credits, active credit-policy reference, contact hours, duration, delivery modes, and effective dates. A draft version can be published; publication makes it current and preserves prior versions. Active subjects/courses can be retired without deleting historical definitions. Retired courses cannot receive or publish new versions.

Learning outcomes are versioned institution references and can be associated with programs or published/current course versions. This is domain readiness, not curriculum sequencing or a CASE endpoint.

## Directory behavior

Program and structure lists are server-paginated and use eager-loaded relations for labels/counts. Courses and subjects are tenant-scoped. A detailed query-latency benchmark was not run; see [PERFORMANCE_REPORT.md](PERFORMANCE_REPORT.md).

## Deliberate boundaries

There are no students, active rosters, admission decisions, curriculum maps, prerequisite graphs, scheduled course offerings, instructor assignments, lessons, assessments, grading scales, or transcripts. Dedicated program cloning and section edit/deactivation flows are not part of this delivered slice and are tracked in the traceability report.
