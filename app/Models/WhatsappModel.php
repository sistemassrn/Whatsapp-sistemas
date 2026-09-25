<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class WhatsappModel extends Model
{
    /**
     * SQL Server can misread separated date strings depending on language/dateformat settings.
     * The compact yyyymmdd format is unambiguous for SQL Server datetime/datetime2 columns.
     */
    protected $dateFormat = 'Ymd H:i:s.v';
}
