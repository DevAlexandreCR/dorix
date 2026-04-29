<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class TenantScopedModel extends Model
{
    use HasFactory;
    use HasTenantScope;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
