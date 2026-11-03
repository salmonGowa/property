<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gap-free counter behind human-readable numbers such as INV-000123 and RCT-000045.
 */
#[Fillable(['name', 'last_value'])]
class DocumentSequence extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';
}
