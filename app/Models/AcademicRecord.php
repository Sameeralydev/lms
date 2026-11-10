<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

abstract class AcademicRecord extends Model
{
    use ScopedToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
