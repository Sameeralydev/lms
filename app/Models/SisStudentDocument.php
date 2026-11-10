<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SisStudentDocument extends AcademicRecord
{
    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class, 'student_id');
    }

    /** @return BelongsTo<AdmissionDocument, $this> */
    public function admissionDocument(): BelongsTo
    {
        return $this->belongsTo(AdmissionDocument::class, 'admission_document_id');
    }
}
