<?php

namespace Tests\Support;

use RuntimeException;

final class Phase12SchemaIdentifierPolicy
{
    /** @var list<string> */
    public const IDENTIFIERS = [
        'qqb_tenant_id_uq', 'qqb_tenant_code_uq', 'qqb_scope_status_idx', 'qqb_inst_fk', 'qqb_campus_fk', 'qqb_course_fk',
        'qq_tenant_id_uq', 'qq_bank_status_idx', 'qq_bank_fk',
        'qqv_question_version_uq', 'qqv_tenant_id_uq', 'qqv_search_idx', 'qqv_question_fk',
        'qqt_tenant_id_uq', 'qqt_tenant_name_uq', 'qqtl_pk', 'qqtl_question_fk', 'qqtl_tag_fk',
        'qqo_pk', 'qqo_version_fk', 'qqo_outcome_fk', 'quiz_tenant_id_uq', 'quiz_offering_status_idx', 'quiz_inst_fk', 'quiz_offering_fk',
        'qsec_pk', 'qsec_quiz_fk', 'qsec_section_fk', 'qv_quiz_version_uq', 'qv_tenant_id_uq', 'qv_status_pub_idx', 'qv_quiz_fk',
        'qvq_pk', 'qvq_position_uq', 'qvq_version_fk', 'qvq_question_version_fk',
        'qqp_tenant_id_uq', 'qqp_version_key_uq', 'qqp_version_fk', 'qpqv_pk', 'qpqv_pool_fk', 'qpqv_question_fk',
        'qat_tenant_id_uq', 'qat_attempt_num_uq', 'qat_member_idem_uq', 'qat_receipt_uq', 'qat_quiz_status_idx', 'qat_student_started_idx', 'qat_quiz_fk', 'qat_version_fk', 'qat_student_fk', 'qat_member_fk',
        'qar_tenant_id_uq', 'qar_attempt_question_uq', 'qar_grading_queue_idx', 'qar_attempt_fk', 'qar_question_fk',
        'qrr_response_rev_uq', 'qrr_response_fk', 'qgr_response_rev_uq', 'qgr_response_fk', 'qrrq_status_created_idx', 'qrrq_attempt_fk',
        'qap_scope_uq', 'qap_inst_fk', 'qap_campus_fk', 'qob_event_key_uq', 'qob_dispatch_idx', 'qn_user_event_uq', 'qn_inbox_idx',
    ];

    public static function assertMySqlIdentifierLengths(): void
    {
        foreach (self::IDENTIFIERS as $identifier) {
            self::assertIdentifierLength($identifier);
        }
    }

    private static function assertIdentifierLength(string $identifier): void
    {
        if (strlen($identifier) > 64) {
            throw new RuntimeException('Phase 12 schema identifier exceeds the MySQL 64-character limit.');
        }
    }
}
