<?php

namespace App\Controllers;
use App\Models\CompanyModel;
use CodeIgniter\RESTful\ResourceController;
use App\Validations\CompanyValidator;

class CompanyController extends ResourceController
{
    public function create()
    {
        $data = $this->request->getJSON(true);

        $validation = \Config\Services::validation();
        $validation->setRules(CompanyValidator::reglas(), CompanyValidator::mensajes());

        if (!$validation->run($data)) {
            return $this->failValidationErrors($validation->getErrors());
        }

        $apiResponse = null;
        $syncApi = 0;

        // 🔹 Consumir API externa
        try {
            $client = \Config\Services::curlrequest([
                'baseURI' => 'http://134.122.122.81',
                'timeout' => 30,
            ]);

            $response = $client->post('/api/subir-certificado', [
                'json' => [
                    'ruc' => $data['ruc'],
                    'certificado_password' => $data['certificado_password'],
                    'certificado_base64' => $data['certificado_base64'],
                ]
            ]);

            $apiResponse = json_decode($response->getBody(), true);
            if ($response->getStatusCode() === 200) {
                $syncApi = 1;
            }

        } catch (\Exception $e) {
            $apiResponse = ['error' => $e->getMessage()];
        }

        // Guardar archivo localmente
        $extension = pathinfo($data['certificado_nombre'], PATHINFO_EXTENSION);
        $nombreArchivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '', $data['ruc'] . '.' . $extension);
        $rutaRelativa = 'public/uploads/' . $nombreArchivo;
        $rutaCompleta = FCPATH . $rutaRelativa;

        $contenido = base64_decode($data['certificado_base64']);
        if ($contenido === false) {
            return $this->fail('El archivo no se pudo decodificar correctamente.');
        }

        file_put_contents($rutaCompleta, $contenido);

        // 🔹 Guardar en DB (insert/update)
        $model = new CompanyModel();
        $existing = $model->where('ruc', $data['ruc'])->first();

        $saveData = [
            'ruc' => $data['ruc'],
            'company_id' => $data['company_id'] ?? null,
            'razon_social' => $data['razon_social'] ?? '',
            'certificado_nombre' => $nombreArchivo,
            'certificado_path' => $rutaRelativa,
            'certificado_password' => $data['certificado_password'],
            'sync_api' => $syncApi,
            'response_api' => json_encode($apiResponse)
        ];

        if ($existing) {
            // 🔹 Update
            $model->update($existing['id'], $saveData);
            $registro = $model->find($existing['id']);
        } else {
            // 🔹 Insert
            $insertedId = $model->insert($saveData, true);
            $registro = $model->find($insertedId);
        }

        return $this->respondCreated([
            'data' => $registro
        ]);
    }
}
