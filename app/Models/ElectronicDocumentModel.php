<?php

namespace App\Models;

use CodeIgniter\Model;

class ElectronicDocumentModel extends Model
{
    protected $table = 'electronic_document';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'company_id',        
        'tipo_documento',
        'json_envio',        
        'json_respuesta',    
        'sync_api',
        'estado',
        'clave_acceso',
        'estab',
        'pto_emi',
        'secuencial',     
    ];
    protected $useTimestamps = true; // created_at, updated_at
}
