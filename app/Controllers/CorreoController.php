<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ElectronicDocumentModel;
use App\Models\EmailModel;
use CodeIgniter\RESTful\ResourceController;

class CorreoController extends ResourceController
{
    public function envioFactura()
    {
        $data = $this->request->getJSON(true);

        if (!$data) {
            return $this->failValidationErrors(['error' => 'JSON inválido']);
        }

        $claveAcceso = $data['clave_acceso'] ?? null;
        $correo = $data['correo'] ?? null;

        if (!$claveAcceso || !$correo) {
            return $this->failValidationErrors(['error' => 'Faltan parámetros obligatorios']);
        }

        // Buscar documento
        $documento = (new ElectronicDocumentModel())
            ->where('clave_acceso', $claveAcceso)
            ->first();

        if (!$documento) {
            return $this->failNotFound('No se encontró el documento.');
        }

        // Buscar compañía
        $company = (new CompanyModel())
            ->where('company_id', $documento["company_id"])
            ->first();

        if (!$company) {
            return $this->failNotFound('No se encontró compañía para este documento.');
        }

        // Validar settings y credenciales
        $settings = !empty($company['settings']) ? json_decode($company['settings'], true) : null;
        if (!$settings || empty($settings['email_credenciales'])) {
            return $this->failValidationErrors([
                'error' => 'La compañía no tiene credenciales de correo configuradas'
            ]);
        }

        // Procesar json_respuesta
        $jsonRespuesta = json_decode($documento['json_respuesta'], true);
        $dataRespuesta = $jsonRespuesta["data"] ?? null;

        if (!$dataRespuesta || ($dataRespuesta['estado'] ?? '') !== 'AUTORIZADO') {
            return $this->failValidationErrors([
                'error' => 'El documento no está autorizado o no tiene estado válido'
            ]);
        }

        // Extraer datos
        $ruc = $company['ruc'];
        $xmlAutorizado = $dataRespuesta['autorized'];

        // Guardar XML en ruta public/xml/$ruc/$claveAcceso.xml
        $carpetaXml = FCPATH . "public/xml/$ruc/";
        if (!is_dir($carpetaXml)) {
            mkdir($carpetaXml, 0777, true);
        }

        $rutaXml = $carpetaXml . $claveAcceso . ".xml";
        file_put_contents($rutaXml, $xmlAutorizado);


        // Asumimos que el PDF ya existe en esta ruta:
        $rutaPdf = FCPATH . "public/ride/$ruc/$claveAcceso.pdf";
        if (!file_exists($rutaPdf)) {
            try {
                $client = \Config\Services::curlrequest();

                $response = $client->post(base_url('api/factura/consulta-ride'), [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => [
                        'ruc' => $ruc,
                        'estab' => $documento['estab'],
                        'pto_emi' => $documento['pto_emi'],
                        'secuencial' => $documento['secuencial']
                    ],
                    'timeout' => 60,
                ]);

                if ($response->getStatusCode() === 201) {
                    $pdfResponse = json_decode($response->getBody(), true);
                    log_message('info', 'PDF generado: ' . json_encode($pdfResponse));
                } else {
                    log_message('error', 'Error al generar el PDF: ' . $response->getBody());
                }
            } catch (\Exception $e) {
                log_message('error', 'Error al consumir API de RIDE: ' . $e->getMessage());
            }
        }

        // Guardar o actualizar en tabla email
        $emailModel = new EmailModel();

        $dataEmail = [
            'electronic_document_id' => $documento["id"],
            'clave_acceso' => $claveAcceso,
            'correo' => $correo,
            'settings' => json_encode([
                'rutaXml' => "public/xml/$ruc/$claveAcceso.xml",
                'rutaPdf' => "public/pdf/$ruc/$claveAcceso.pdf"
            ])
        ];

        // Buscar si ya existe registro con esa clave de acceso
        $existing = $emailModel->where('clave_acceso', $claveAcceso)->first();

        if ($existing) {
            // Actualizar
            $emailModel->update($existing['id'], $dataEmail);
        } else {
            // Insertar
            $emailModel->insert($dataEmail);
        }


        $emailConfig = [
            'protocol' => 'smtp',
            'SMTPHost' => $settings['email_credenciales']['smtp_host'],
            'SMTPUser' => $settings['email_credenciales']['smtp_user'],
            'SMTPPass' => $settings['email_credenciales']['smtp_pass'],
            'SMTPPort' => $settings['email_credenciales']['smtp_port'],
            'SMTPCrypto' => $settings['email_credenciales']['smtp_secure'],
            'mailType' => 'html',
            'charset' => 'utf-8',
            'newline' => "\r\n",
            'wordWrap' => true,
        ];


        $email = \Config\Services::email($emailConfig);

        $email->setFrom($settings['email_credenciales']['from_email'], $settings['email_credenciales']['from_name']);
        $email->setTo($correo);
        $email->setSubject('Nuevo Documento Electrónico Recibido');

        // Decodificar json_envio
        $jsonEnvio = json_decode($documento['json_envio'], true);
        $logoPath = FCPATH . $company['logo']; // Ejemplo: FCPATH . 'public/logo/0550080774001/logo.png'
        $logoUrl = base_url($company['logo']); // URL pública para el HTML

        $logoHtml = '';
        if (!empty($company['logo']) && file_exists($logoPath)) {
            $logoHtml = '<img src="' . $logoUrl . '" class="logo" alt="Logo">';
        } else {
            $logoHtml = '<h2>' . $company['razon_social'] . '</h2>';
        }
        // Construir HTML del mensaje
        $html = '
        <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .header { background-color: #f5f5f5; padding: 10px; text-align: center; }
                    .logo { max-height: 80px; }
                    .content { padding: 20px; }
                    .footer { font-size: 12px; color: #777; text-align: center; margin-top: 30px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                    th { background-color: #eee; }
                </style>
            </head>
            <body>
                <div class="header">
                    ' . $logoHtml . '
                    <p>Factura Electrónica Autorizada</p>
                </div>
                <div class="content">
                    <p>Estimado/a <strong>' . $jsonEnvio['infoFactura']['razonSocialComprador'] . '</strong>,</p>
                    <p>Adjunto encontrará su factura electrónica correspondiente a la compra realizada el <strong>' . $jsonEnvio['infoFactura']['fechaEmision'] . '</strong>.</p>

                    <h4>Resumen de la factura</h4>
                    <ul>
                        <li><strong>RUC:</strong> ' . $jsonEnvio['infoTributaria']['ruc'] . '</li>
                        <li><strong>Razón Social:</strong> ' . $jsonEnvio['infoTributaria']['razonSocial'] . '</li>
                        <li><strong>Dirección:</strong> ' . $jsonEnvio['infoFactura']['dirEstablecimiento'] . '</li>
                        <li><strong>Total:</strong> $' . number_format($jsonEnvio['infoFactura']['importeTotal'], 2) . '</li>
                    </ul>

                    <h4>Detalles de productos</h4>
                    <table>
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Cantidad</th>
                        <th>Precio Unitario</th>
                        <th>Total</th>
                    </tr>';

        foreach ($jsonEnvio['detalles'] as $item) {
            $html .= '
                    <tr>
                        <td>' . $item['codigoPrincipal'] . '</td>
                        <td>' . $item['descripcion'] . '</td>
                        <td>' . $item['cantidad'] . '</td>
                        <td>$' . number_format($item['precioUnitario'], 2) . '</td>
                        <td>$' . number_format($item['precioTotalSinImpuesto'], 2) . '</td>
                    </tr>';
        }

        $html .= '
                    </table>

                    <p>Se adjuntan los archivos XML y PDF correspondientes a esta factura.</p>
                    <p>Gracias por su preferencia.</p>
                </div>
                <div class="footer">
                    Este correo fue generado automáticamente por el sistema de facturación electrónica de <strong>' . $jsonEnvio['infoTributaria']['nombreComercial'] . '</strong>.
                </div>
            </body>
        </html>';

        // Asignar mensaje HTML
        $email->setMessage($html);

        // Adjuntar archivos
        $email->attach($rutaPdf);
        $email->attach($rutaXml);

        // Enviar correo
        if (!$email->send()) {
            $debug = $email->printDebugger();
            $detalle = is_array($debug) ? json_encode($debug) : (string) $debug;
            return $this->failServerError('No se pudo enviar el correo: ' . $detalle);
        }

        return $this->respondCreated([
            'message' => 'Correo enviado',
        ]);
    }
}