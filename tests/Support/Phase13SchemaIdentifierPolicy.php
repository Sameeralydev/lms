<?php

namespace Tests\Support;

final class Phase13SchemaIdentifierPolicy
{
    /** @var list<string> */
    public const IDENTIFIERS = [
        'exam_cycles', 'exam_sessions', 'exam_candidates', 'exam_seat_allocations',
        'exam_cycle_tenant_id_uq', 'exam_cycle_code_uq', 'exam_cycle_status_idx', 'exam_cycle_inst_fk', 'exam_cycle_campus_fk', 'exam_cycle_period_fk',
        'exam_session_tenant_id_uq', 'exam_session_code_uq', 'exam_session_schedule_idx', 'exam_session_cycle_fk', 'exam_session_campus_fk', 'exam_session_course_fk', 'exam_session_room_fk',
        'exam_candidate_tenant_id_uq', 'exam_candidate_student_uq', 'exam_candidate_number_uq', 'exam_candidate_status_idx', 'exam_candidate_cycle_fk', 'exam_candidate_student_fk',
        'exam_seat_candidate_uq', 'exam_seat_number_uq', 'exam_seat_session_fk', 'exam_seat_candidate_fk', 'exam_seat_room_fk',
    ];
}
