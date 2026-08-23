<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pivot restricting which contacts a user may see.
 */
class UserContactAccess extends Model
{
    protected $table = 'user_contact_access';

    public $timestamps = false;

    protected $guarded = ['id'];
}
