<?php
namespace App\Validations;
class CompanyValidator
{
    public static function reglas(): array
    {
        return [
            'ruc' =>
                'required|exact_length[13]',
            'company_id' => 'required|is_natural_no_zero',
            'certificado_nombre' => 'required|string',
            'certificado_password' => 'required|string',
            'certificado_base64' => 'required|string',
            'razon_social' => 'permit_empty|string',
            'logo' => 'required|string',
        ];
    }

    public static function mensajes(): array
    {
        return [
            'ruc' => [
                'required' => 'El RUC es obligatorio',
                'exact_length' => 'El RUC debe tener 13 dígitos',
            ],
            'company_id' => [
                'required' => 'La compañia es obligatoria',
            ],
            'certificado_nombre' => [
                'required' => 'El nombre del certificado es obligatorio',
            ],
            'certificado_password' => [
                'required' => 'La clave del certificado es obligatoria',
            ],
            'certificado_base64' => [
                'required' => 'El contenido del certificado es obligatorio',
            ],
            'logo' => [
                'required' => 'El logo es obligatorio',
            ]
        ];
    }
}