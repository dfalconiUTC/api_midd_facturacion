<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ElectronicDocumentModel;
use CodeIgniter\RESTful\ResourceController;
use Dompdf\Dompdf; // Importar Dompdf
use Dompdf\Options;

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

        // Buscar compañía
        $company = (new CompanyModel())->where('ruc', $ruc)->first();

        if (!$company) {
            return $this->failNotFound('No se encontró compañía con el RUC proporcionado.');
        }

        $facturaModel = new ElectronicDocumentModel();

        // Buscar documento
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

        // Procesar XML de autorización
        libxml_use_internal_errors(true);
        // Paso 1: decodificar el XML
        $xmlAutorizacion = html_entity_decode($jsonRespuesta['data']['autorized']);
        // Limpiar espacios y BOM
        $xmlAutorizacion = trim($jsonRespuesta['data']['autorized']);
        $xmlAutorizacion = preg_replace('/^\xEF\xBB\xBF/', '', $xmlAutorizacion);

        // Paso 2: cargar el XML externo
        $xmlObj = simplexml_load_string($xmlAutorizacion);
        if (!$xmlObj) {
            /*$errors = libxml_get_errors();
            foreach ($errors as $error) {
                echo "Error XML: " . $error->message . "\n";
            }
            libxml_clear_errors();*/
            return $this->fail('Error al cargar el XML de autorización.');
        }

        if (!$xmlObj || !isset($xmlObj->comprobante)) {
            return $this->fail('No se encontró el comprobante en el XML de autorización.');
        }

        // Procesar XML del comprobante
        $xmlComprobanteRaw = (string) $xmlObj->comprobante;
        $xmlComprobanteDecoded = html_entity_decode($xmlComprobanteRaw);
        $xmlComprobanteClean = preg_replace('/<\?xml.*?\?>/', '', $xmlComprobanteDecoded);
        $xmlComprobanteObj = simplexml_load_string($xmlComprobanteClean);

        //var_dump($xmlObj);
        if (!$xmlComprobanteObj) {
            return $this->fail('Error al procesar el XML del comprobante.');
        }

        libxml_clear_errors();
        // $jsonRespuesta['data']['autorized'] ya contiene tu XML procesado
// $xmlComprobanteObj contiene el comprobante en SimpleXMLElement
// $xmlObj contiene la info de autorización

        // Convertimos el XML del comprobante a array si no lo está
        $xmlComprobanteArray = $xmlComprobanteObj;

        // Agregar los campos de autorización desde $xmlObj
        $xmlComprobanteArray['estado'] = $xmlObj->estado ?? null;
        $xmlComprobanteArray['numeroAutorizacion'] = $xmlObj->numeroAutorizacion ?? null;
        $xmlComprobanteArray['fechaAutorizacion'] = $xmlObj->fechaAutorizacion ?? null;
        $xmlComprobanteArray['ambiente'] = $xmlObj->ambiente ?? null;

        // Ahora $xmlComprobanteArray o $xmlComprobanteFinal ya tiene toda la información

        /*return $this->respondCreated([
            'data' => $xmlComprobanteArray
        ]);*/
        // Generar HTML del RIDE
        $html = $this->generarHtmlRideFactura($xmlComprobanteArray);

        // ---------------- PDF ----------------
        $options = new Options();
        $options->set('isRemoteEnabled', true); // si hay imágenes externas
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Preparar ruta dentro de PUBLIC/ride/{RUC}/
        $basePath = FCPATH . 'public/ride/' . $ruc . '/'; // FCPATH apunta a public/
        if (!is_dir($basePath)) {
            mkdir($basePath, 0777, true); // crear carpetas recursivamente
        }

        $nombrePdf = $xmlComprobanteArray->infoTributaria->claveAcceso . '.pdf';
        $rutaPdf = $basePath . $nombrePdf;

        // Guardar PDF
        file_put_contents($rutaPdf, $dompdf->output());

        // Retornar HTML y URL del PDF
        return $this->respondCreated([
            'pdf' => base_url('public/ride/' . $ruc . '/' . $nombrePdf)
        ]);
    }

    private function generarHtmlRideFactura(\SimpleXMLElement $xmlObj): string
    {
        $infoTributaria = $xmlObj->infoTributaria;
        $infoFactura = $xmlObj->infoFactura;
        $detalles = $xmlObj->detalles->detalle;
        $infoAdicional = $xmlObj->infoAdicional->campoAdicional ?? [];
        $atributos = $xmlObj->attributes();

        $html = '<div style="font-family: Arial, sans-serif; font-size: 14px;">';

        // Encabezado
        $html .= '<h2 style="text-align:center;">FACTURA ELECTRÓNICA</h2>';
        $html .= '<p><strong>RUC:</strong> ' . $infoTributaria->ruc . '</p>';
        $html .= '<p><strong>Razón Social:</strong> ' . $infoTributaria->razonSocial . '</p>';
        $html .= '<p><strong>Nombre Comercial:</strong> ' . $infoTributaria->nombreComercial . '</p>';
        $html .= '<p><strong>Dirección Matriz:</strong> ' . $infoTributaria->dirMatriz . '</p>';
        $html .= '<p><strong>Dirección Establecimiento:</strong> ' . $infoFactura->dirEstablecimiento . '</p>';
        $html .= '<p><strong>Teléfono:</strong> (no especificado)</p>';
        $html .= '<p><strong>Obligado a llevar contabilidad:</strong> ' . $infoFactura->obligadoContabilidad . '</p>';
        $html .= '<p><strong>Régimen:</strong> ' . $infoTributaria->contribuyenteRimpe . '</p>';

        // Datos de autorización
        $html .= '<hr>';
        $html .= '<p><strong>FACTURA Nº:</strong> ' . $infoTributaria->estab . '-' . $infoTributaria->ptoEmi . '-' . $infoTributaria->secuencial . '</p>';
        $html .= '<p><strong>Número de Autorización:</strong> ' . $atributos->numeroAutorizacion . '</p>';
        $html .= '<p><strong>Fecha de Autorización:</strong> ' . $atributos->fechaAutorizacion . '</p>';
        $html .= '<p><strong>Ambiente:</strong> ' . ($atributos->ambiente === 'PRUEBAS' ? 'PRUEBAS' : 'PRODUCCIÓN') . '</p>';
        $html .= '<p><strong>Emisión:</strong> Normal</p>';
        $html .= '<p><strong>Clave de Acceso:</strong><br><span style="font-family:monospace;">' . $infoTributaria->claveAcceso . '</span></p>';

        // Datos del comprador
        $html .= '<hr>';
        $html .= '<p><strong>Razón Social Comprador:</strong> ' . $infoFactura->razonSocialComprador . '</p>';
        $html .= '<p><strong>Identificación:</strong> ' . $infoFactura->identificacionComprador . '</p>';
        $html .= '<p><strong>Fecha de Emisión:</strong> ' . $infoFactura->fechaEmision . '</p>';
        $html .= '<p><strong>Dirección:</strong> (no especificada)</p>';
        $html .= '<p><strong>Email:</strong> ';
        $html .= isset($infoAdicional[0]) ? $infoAdicional[0] : '(no especificado)';
        $html .= '</p>';

        // Detalle de productos
        $html .= '<hr>';
        $html .= '<table border="1" width="100%" cellpadding="5" cellspacing="0">';
        $html .= '<tr><th>Código</th><th>Cantidad</th><th>Producto</th><th>Descripción</th><th>Precio Unitario</th><th>Descuento</th><th>Total</th></tr>';
        foreach ($detalles as $d) {
            $html .= '<tr>';
            $html .= '<td>' . $d->codigoPrincipal . '</td>';
            $html .= '<td>' . $d->cantidad . '</td>';
            $html .= '<td>' . $d->descripcion . '</td>';
            $html .= '<td>' . $d->descripcion . '</td>';
            $html .= '<td>' . $d->precioUnitario . '</td>';
            $html .= '<td>' . $d->descuento . '</td>';
            $html .= '<td>' . $d->precioTotalSinImpuesto . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        // Totales
        $html .= '<hr>';
        $html .= '<table border="0" width="50%" cellpadding="5" cellspacing="0">';
        $html .= '<tr><td>Subtotal IVA 0%</td><td>' . $infoFactura->totalSinImpuestos . '</td></tr>';
        $html .= '<tr><td>Descuento</td><td>' . $infoFactura->totalDescuento . '</td></tr>';
        $html .= '<tr><td>IVA</td><td>' . $infoFactura->totalConImpuestos->totalImpuesto->valor . '</td></tr>';
        $html .= '<tr><td><strong>Valor Total</strong></td><td><strong>' . $infoFactura->importeTotal . ' ' . $infoFactura->moneda . '</strong></td></tr>';
        $html .= '</table>';

        // Forma de pago
        $html .= '<hr>';
        $html .= '<p><strong>Forma de Pago:</strong> ' . $infoFactura->pagos->pago->formaPago . '</p>';
        $html .= '<p><strong>Valor:</strong> ' . $infoFactura->pagos->pago->total . '</p>';

        // Pie de página
        $html .= '<hr>';
        $html .= '<p>Estimad@, su comprobante electrónico ha sido emitido exitosamente.</p>';
        $html .= '<p>Documento generado por sistema institucional.</p>';

        $html .= '</div>';

        return $html;
    }
}