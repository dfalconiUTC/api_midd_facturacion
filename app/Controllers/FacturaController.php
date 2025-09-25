<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ElectronicDocumentModel;
use CodeIgniter\RESTful\ResourceController;

class FacturaController extends ResourceController
{
    public function sendApi()
    {
        $data = $this->request->getJSON(true);

        if (!$data) {
            return $this->failValidationErrors(['error' => 'JSON inválido']);
        }

        // 1️⃣ Buscar la compañía
        $company = (new CompanyModel())->where('ruc', $data['infoTributaria']['ruc'] ?? '')->first();
        if (!$company) {
            return $this->failNotFound('No se encontró compañía con el RUC proporcionado.');
        }

        $infoTributaria = $data['infoTributaria'] ?? [];
        $estab = $infoTributaria['estab'] ?? null;
        $ptoEmi = $infoTributaria['ptoEmi'] ?? null;
        $secuencial = $infoTributaria['secuencial'] ?? null;
        $claveAcceso = "";
        $fechaEmision = $data['infoFactura']['fechaEmision'] ?? date('d/m/Y');
        

        $facturaModel = new ElectronicDocumentModel();

        // 2️⃣ Verificar si ya existe en DB
        $existente = $facturaModel->where([
            'company_id' => $company['company_id'],
            'tipo_documento' => 'FAC',
            'estab' => $estab,
            'pto_emi' => $ptoEmi,
            'secuencial' => $secuencial
        ])->first();

        if ($existente) {
            if ($existente['estado'] === 'AUTORIZADO') {

                return $this->respond([
                    'data' => $existente,
                    'message' => 'Documento ya AUTORIZADO'
                ]);
            }

            // 3️⃣ Si existe pero no autorizado → verificar estado en el SRI
            try {
                $client = \Config\Services::curlrequest([
                    'baseURI' => 'http://134.122.122.81',
                    'timeout' => 30,
                ]);

                $response = $client->post('/api/verififacion-documento', [
                    'json' => [
                        "claveAcceso" => $existente['clave_acceso'] ?? '',
                        "ruc" => $data['infoTributaria']['ruc'] ?? '',
                        "documento" => "{$estab}-{$ptoEmi}-{$secuencial}",
                        "ambiente" => $infoTributaria['ambiente'] ?? 1,
                        "fecha" => $fechaEmision
                    ]
                ]);


                $verif = json_decode($response->getBody(), true);

                if (isset($verif['data']['estado']) && $verif['data']['estado'] === 'AUTORIZADO') {
                    // ✅ Actualizar registro a AUTORIZADO
                    $facturaModel->update($existente['id'], [
                        'estado' => 'AUTORIZADO',
                        'json_respuesta' => json_encode($verif),
                    ]);

                    $existente = $facturaModel->find($existente['id']);
                    return $this->respond([
                        'data' => $existente,
                        'message' => 'Documento actualizado como AUTORIZADO'
                    ]);
                }
            } catch (\Exception $e) {
            }
        }

        // Generar claveAcceso
        $claveAcceso = $this->generarClaveAcceso(
            $fechaEmision,            // fecha emisión
            '01',                     // factura
            $company['ruc'],          // RUC de la compañía
            $infoTributaria['ambiente'] ?? 1,
            $estab,
            $ptoEmi,
            $secuencial,
            1                         // tipoEmision normal
        );

        // Inyectar en el JSON
        $data['infoTributaria']['claveAcceso'] = $claveAcceso;

        // 4️⃣ Si no existe o no autorizado, consumir el servicio de creación
        $apiResponse = null;
        $syncApi = 0;
        $estadoSri = 'ERROR';

        try {
            $client = \Config\Services::curlrequest([
                'baseURI' => 'http://134.122.122.81',
                'timeout' => 30,
            ]);

            $response = $client->post('/api/factura/create', [
                'json' => $data
            ]);

            $apiResponse = json_decode($response->getBody(), true);

            $estadoSri = !empty($apiResponse['data']['estado'])
                ? $apiResponse['data']['estado']
                : (!empty($apiResponse['data']['respuesta'])
                    ? $apiResponse['data']['respuesta']
                    : 'ERROR');
            if ($response->getStatusCode() === 200) {
                $syncApi = 1;
            }

        } catch (\Exception $e) {
            $apiResponse = ['error' => $e->getMessage()];
            $estadoSri = 'ERROR_CONEXION';
        }

        // 5️⃣ Guardar en DB (si ya existía lo actualiza, si no lo inserta)
        if ($existente) {
            $facturaModel->update($existente['id'], [
                'json_envio' => json_encode($data),
                'json_respuesta' => json_encode($apiResponse),
                'estado' => $estadoSri,
                'sync_api' => $syncApi,
            ]);
            $registro = $facturaModel->find($existente['id']);
        } else {
            $insertedId = $facturaModel->insert([
                'company_id' => $company['company_id'],
                'tipo_documento' => 'FAC',
                'json_envio' => json_encode($data),
                'json_respuesta' => json_encode($apiResponse),
                'estado' => $estadoSri,
                'sync_api' => $syncApi,
                'clave_acceso' => $claveAcceso,
                'estab' => $estab,
                'pto_emi' => $ptoEmi,
                'secuencial' => $secuencial
            ], true);
            $registro = $facturaModel->find($insertedId);
        }

        return $this->respondCreated([
            'data' => $registro
        ]);
    }

    /**
     * Genera la clave de acceso SRI
     */
    private function generarClaveAcceso(
        string $fechaEmision,
        string $codDoc,
        string $ruc,
        string $ambiente,
        string $estab,
        string $ptoEmi,
        string $secuencial,
        string $tipoEmision
    ): string {
        // Formatear fecha a ddMMyyyy
        $fecha = \DateTime::createFromFormat('d/m/Y', $fechaEmision);
        $fechaFormato = $fecha ? $fecha->format('dmY') : '00000000';

        // Serie = estab + ptoEmi
        $serie = str_pad($estab, 3, '0', STR_PAD_LEFT) . str_pad($ptoEmi, 3, '0', STR_PAD_LEFT);

        // Secuencial a 9 dígitos
        $secuencial = str_pad($secuencial, 9, '0', STR_PAD_LEFT);

        // Código numérico (puedes cambiar por algo más robusto)
        $codigoNumerico = str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);

        // Concatenar los 48 dígitos
        $clave = $fechaFormato
            . str_pad($codDoc, 2, '0', STR_PAD_LEFT)
            . str_pad($ruc, 13, '0', STR_PAD_LEFT)
            . $ambiente
            . $serie
            . $secuencial
            . $codigoNumerico
            . $tipoEmision;

        // Calcular dígito verificador
        $digito = $this->modulo11($clave);

        return $clave . $digito;
    }

    /**
     * Cálculo del dígito verificador módulo 11
     */
    private function modulo11(string $numero): int
    {
        $baseMultiplicador = 7;
        $multiplicador = 2;
        $suma = 0;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += intval($numero[$i]) * $multiplicador;
            $multiplicador = ($multiplicador < $baseMultiplicador) ? $multiplicador + 1 : 2;
        }

        $modulo = 11 - ($suma % 11);
        if ($modulo == 11) {
            return 0;
        }
        if ($modulo == 10) {
            return 1;
        }
        return $modulo;
    }

}
