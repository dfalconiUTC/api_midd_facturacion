<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailModel extends Model
{
    protected $table = 'email';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'electronic_document_id',
        'correo',
        'clave_acceso',
        'settings',
        'estado',
    ];
    protected $useTimestamps = true; // created_at, updated_at
}
