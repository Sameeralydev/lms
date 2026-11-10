<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
abstract class AdmissionsRecord extends Model
{
    use ScopedToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
