<?php

namespace App\Models;

use CodeIgniter\Model;

class CompanyModel extends Model
{
    protected $table = 'company';
    protected $primaryKey = 'id';
    protected $allowedFields = [
        'ruc',
        'razon_social',
        'certificado_nombre',
        'certificado_path',
        'certificado_password',
        'company_id',
        'sync_api',
        'response_api',
        'logo',
    ];
    protected $useTimestamps = true;
}