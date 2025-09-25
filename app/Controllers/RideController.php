<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ElectronicDocumentModel;
use CodeIgniter\RESTful\ResourceController;
use Dompdf\Dompdf;

class RideController extends ResourceController
{
    public function consultaRideFactura()
    {
        $data = $this->request->getJSON(true);

        if (!$data) {
            return $this->failValidationErrors(['error' => 'JSON inválido']);
        }

        $ruc = $data['ruc'] ?? null;
        $estab = $data['estab'] ?? null;
        $ptoEmi = $data['pto_emi'] ?? null;
        $secuencial = $data['secuencial'] ?? null;
        $tipoDocumento = 'FAC';

        if (!$ruc || !$estab || !$ptoEmi || !$secuencial) {
            return $this->failValidationErrors(['error' => 'Faltan parámetros obligatorios']);
        }

        // 1️⃣ Buscar compañía
        $company = (new CompanyModel())->where('ruc', $ruc)->first();
        if (!$company) {
            return $this->failNotFound('No se encontró compañía con el RUC proporcionado.');
        }

        $facturaModel = new ElectronicDocumentModel();

        // 2️⃣ Buscar documento por company_id
        $documento = $facturaModel->where([
            'company_id' => $company['company_id'],
            'tipo_documento' => $tipoDocumento,
            'estab' => $estab,
            'pto_emi' => $ptoEmi,
            'secuencial' => $secuencial
        ])->first();

        if (!$documento) {
            return $this->failNotFound('No se encontró el documento.');
        }

        $jsonRespuesta = json_decode($documento['json_respuesta'], true);

        // Ajuste clave: el XML de autorización
        $xmlAutorizacion = html_entity_decode($jsonRespuesta['data']['autorized']);
        $xmlObj = simplexml_load_string($xmlAutorizacion);

        if (!$xmlObj || !isset($xmlObj->comprobante)) {
            return $this->fail('No se encontró el comprobante en el XML de autorización.');
        }

        // Extraemos el contenido real de la factura y eliminamos la declaración XML duplicada
        $xmlComprobante = html_entity_decode((string) $xmlObj->comprobante);
        $xmlComprobante = preg_replace('/<\?xml.*?\?>/', '', $xmlComprobante);
        $xmlComprobanteObj = simplexml_load_string($xmlComprobante);

        if (!$xmlComprobanteObj) {
            return $this->fail('Error al procesar el XML del comprobante.');
        }

        // Generar HTML del RIDE
        $html = $this->generarHtmlRideFactura($xmlComprobanteObj);


        return $this->respondCreated([
            'data' => $html
        ]);
        // Generar RIDE en PDF usando Dompdf
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();

        // Retornar PDF como descarga
        return $this->response->setContentType('application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="RIDE_' . $secuencial . '.pdf"')
            ->setBody($pdfOutput);
    }

    private function generarHtmlRideFactura(\SimpleXMLElement $xmlObj): string
    {
        $infoTributaria = $xmlObj->infoTributaria;
        $infoFactura = $xmlObj->infoFactura;
        $detalles = $xmlObj->detalles->detalle;
        $infoAdicional = $xmlObj->infoAdicional->campoAdicional ?? [];

        $html = '<h1>Factura Electrónica</h1>';
        $html .= '<h2>RUC: ' . $infoTributaria->ruc . '</h2>';
        $html .= '<h3>Razón Social: ' . $infoTributaria->razonSocial . '</h3>';
        $html .= '<h3>Fecha: ' . $infoFactura->fechaEmision . '</h3>';

        $html .= '<table border="1" width="100%" cellpadding="5" cellspacing="0">';
        $html .= '<tr><th>Producto</th><th>Cantidad</th><th>Precio Unitario</th><th>Total</th></tr>';

        foreach ($detalles as $d) {
            $html .= '<tr>';
            $html .= '<td>' . $d->descripcion . '</td>';
            $html .= '<td>' . $d->cantidad . '</td>';
            $html .= '<td>' . $d->precioUnitario . '</td>';
            $html .= '<td>' . $d->precioTotalSinImpuesto . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        $html .= '<h3>Total: ' . $infoFactura->importeTotal . ' ' . $infoFactura->moneda . '</h3>';

        if ($infoAdicional) {
            $html .= '<h4>Información Adicional:</h4><ul>';
            foreach ($infoAdicional as $campo) {
                $nombre = (string) $campo['nombre'];
                $valor = (string) $campo;
                $html .= "<li>$nombre: $valor</li>";
            }
            $html .= '</ul>';
        }

        return $html;
    }
}
