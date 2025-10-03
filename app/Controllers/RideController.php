<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ElectronicDocumentModel;
use CodeIgniter\RESTful\ResourceController;
use Dompdf\Dompdf; // Importar Dompdf
use Dompdf\Options;
use Picqer\Barcode\BarcodeGeneratorPNG;

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

    /*private function generarHtmlRideFactura(\SimpleXMLElement $xmlObj): string
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
    }*/
    private function generarHtmlRideFactura(\SimpleXMLElement $xmlObj): string
    {
        $generator = new BarcodeGeneratorPNG();
        $infoTributaria = $xmlObj->infoTributaria;
        $infoFactura = $xmlObj->infoFactura;
        $detalles = $xmlObj->detalles->detalle;
        $infoAdicional = $xmlObj->infoAdicional->campoAdicional ?? [];
        $atributos = $xmlObj->attributes();
        $barcode = $generator->getBarcode($atributos->numeroAutorizacion, $generator::TYPE_CODE_128);
        $barcodeBase64 = base64_encode($barcode);
        $ruc = (string) $infoTributaria->ruc;

        // 🔹 Valor por defecto (si no encuentra en BD)
        $logoPath = base_url('public/logo/image.png');

        // 🔹 Consultar en la tabla company
        $model = new CompanyModel();
        $company = $model->where('ruc', $ruc)->first();

        if ($company && !empty($company['logo'])) {
            // Si existe ruta de logo en BD, usar esa
            $logoPath = base_url($company['logo']);
        }

        $html = '
        <!DOCTYPE html>
        <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Factura</title>
                <style>
                    body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 10px; }
                    .detalle-productos th, .detalle-productos td { border: 1px solid #000; padding: 3px 4px; text-align: center; }
                    .sin-borde-interno { border: 1px solid #000; border-collapse: separate; border-spacing: 0; }
                    .sin-borde-interno th, .sin-borde-interno td { border: none; padding: 3px 4px; text-align: left; }
                    .totales { width: 40%; border: 1px solid #000; padding: 10px; font-size: 10px; float: right; }
                    .totales p { display: flex; justify-content: space-between; margin: 2px 0; }
                    .clearfix::after { content: ""; display: table; clear: both; }
                </style>
            </head>
            <body>
                <div class="factura">
                    <!-- ENCABEZADO -->
                    <table class="sin-borde-interno" width="100%" style="margin-bottom: 5px; border: 0px solid #000;">
                        <tr>
                            <td width="55%" style="vertical-align: top; padding: 3px;">
                                <div style="text-align: center; margin-bottom: 5px;">
                                    <img src="' . $logoPath . '" alt="logo" style="max-height:100px; max-width:300px;">
                                </div>
                                <br>
                                <div style="border: 1px solid #000; padding: 5px;">
                                    <br>
                                    <h2 style="margin:0; font-size: 14px;">' . $infoTributaria->razonSocial . '</h2>
                                    <p><b>Dirección Matriz:</b> ' . $infoTributaria->dirMatriz . '<p>
                                    <p><b>Dirección Sucursal:</b> ' . $infoFactura->dirEstablecimiento . '<p>
                                    <p><b>Teléfono:</b> (no especificado)<p>
                                    <p><b>RIMPE:</b> ' . $infoTributaria->contribuyenteRimpe . '<p>
                                    <p><b>OBLIGADO A LLEVAR CONTABILIDAD:</b> ' . $infoFactura->obligadoContabilidad . '<p>
                                </div>
                            </td>
                            <td width="40%" style="vertical-align: top; border: 1px solid #000; padding: 5px;">
                                <p><strong>RUC ' . $infoTributaria->ruc . '</strong></p>
                                <h2 style="margin:0; font-size: 14px;"><strong>FACTURA</strong></h2>
                                <p>Nº ' . $infoTributaria->estab . '-' . $infoTributaria->ptoEmi . '-' . $infoTributaria->secuencial . '</p>
                                <p><strong>NÚMERO DE AUTORIZACIÓN</strong></p>
                                <p style="word-break:break-word;">' . $atributos->numeroAutorizacion . '</p>
                                <p><strong>FECHA DE AUTORIZACIÓN:</strong> ' . $atributos->fechaAutorizacion . '</p>
                                <p><strong>AMBIENTE:</strong> PRODUCCIÓN</p>
                                <p><strong>EMISIÓN:</strong> Normal</p>
                                <h2 style="margin:0; font-size: 14px;">CLAVE DE ACCESO</h2>
                                <div style="text-align:center; margin-top:5px;">
                                    <img src="data:image/png;base64,' . $barcodeBase64 . '" alt="Código de barras" style="max-width:100%; height:25px;">
                                </div>
                                <p style="font-size:10px;word-break:break-word;">' . $infoTributaria->claveAcceso . '</p>
                            </td>
                        </tr>
                    </table>

                    <!-- DATOS DEL COMPRADOR -->
                    <div class="bloque-fin">
                        <table class="sin-borde-interno">
                            <tbody>
                                <tr>
                                    <td><strong>RAZON SOCIAL/NOMBRE O APELLIDO:</strong></td>
                                    <td>' . $infoFactura->razonSocialComprador . '</td>
                                    <td><strong>IDENTIFICACIÓN:</strong></td>
                                    <td>' . $infoFactura->identificacionComprador . '</td>
                                </tr>
                                <tr>
                                    <td><strong>FECHA DE EMISIÓN:</strong></td>
                                    <td>' . $infoFactura->fechaEmision . '</td>
                                    <td><strong>GUÍA DE REMISIÓN:</strong></td>
                                    <td>(no especificada)</td>
                                </tr>
                                <tr>
                                    <td><strong>DIRECCIÓN:</strong></td>
                                    <td>' . (isset($infoAdicional[1]) ? $infoAdicional[1] : '(no especificado)') . '</td>
                                    <td><strong>E-MAIL:</strong></td>
                                    <td>' . (isset($infoAdicional[0]) ? $infoAdicional[0] : '(no especificado)') . '</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- DETALLE DE PRODUCTOS -->
                    <table class="detalle-productos">
                        <thead>
                            <tr>
                                <th>Cod Principal</th>
                                <th>Cant</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Precio Unitario</th>
                                <th>Descuento</th>
                                <th>Precio Total</th>
                            </tr>
                        </thead>
                        <tbody>';
                        foreach ($detalles as $d) {
                            $html .= '
                                <tr>
                                    <td>' . $d->codigoPrincipal . '</td>
                                    <td>' . $d->cantidad . '</td>
                                    <td>' . $d->descripcion . '</td>
                                    <td>' . $d->descripcion . '</td>
                                    <td>' . $this->formatNumber($d->precioUnitario) . '</td>
                                    <td>' . $this->formatNumber($d->descuento) . '</td>
                                    <td>' . $this->formatNumber($d->precioTotalSinImpuesto) . '</td>
                                </tr>';
                        }
                        $html .= '
                        </tbody>
                    </table>

                    <!-- INFORMACIÓN ADICIONAL Y FORMA DE PAGO -->
                    <div class="bloque-info-fin">
                        <div style="width:55%; float:left;">
                            <table class="sin-borde-interno">
                                <thead>
                                    <tr><th colspan="2" style="text-align:center;">INFORMACIÓN ADICIONAL</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><b>APLICACIÓN:</b></td>
                                        <td>DAMISOFT</td>
                                    </tr>
                                    <tr>
                                        <td><b>EMAIL:</b></td>
                                        <td>' . (isset($infoAdicional[0]) ? $infoAdicional[0] : '(no especificado)') . '</td>
                                    </tr>
                                    <tr>
                                        <td><b>DIRECCIÓN:</b></td>
                                        <td>' . (isset($infoAdicional[1]) ? $infoAdicional[1] : '(no especificado)') . '</td>
                                    </tr>
                                </tbody>
                            </table>
                            <table class="sin-borde-interno">
                                <thead>
                                    <tr><th>FORMA DE PAGO</th><th>VALOR</th></tr>
                                </thead>
                                <tbody>';
                                if (isset($infoFactura->pagos->pago)) {
                                    // Si hay varios pagos, recorremos todos
                                    foreach ($infoFactura->pagos->pago as $pago) {
                                        $html .= '
                                        <tr>
                                            <td>' . (string)$pago->formaPago . '</td>
                                            <td>' . $this->formatNumber($pago->total) . '</td>
                                        </tr>';
                                    }
                                } else {
                                    $html .= '
                                    <tr>
                                        <td colspan="2">(No especificado)</td>
                                    </tr>';
                                }

                                $html .= '
                                </tbody>
                            </table>
                        </div>
                        <div style="width: 40%; float: right; border: 1px solid #000; padding: 10px; font-size: 12px;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tbody>
                                    <tr>
                                        <td><strong>Subtotal IVA 0%</strong></td>
                                        <td style="text-align: right;">' . $this->formatNumber($infoFactura->totalSinImpuestos) . '</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Descuento</strong></td>
                                        <td style="text-align: right;">' . $this->formatNumber($infoFactura->totalDescuento) . '</td>
                                    </tr>
                                    <tr>
                                        <td><strong>IVA</strong></td>
                                        <td style="text-align: right;">' . $this->formatNumber($infoFactura->totalConImpuestos->totalImpuesto->valor) . '</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Valor Total</strong></td>
                                        <td style="text-align: right;"><strong>' . $this->formatNumber($infoFactura->importeTotal) . '</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="clearfix"></div>
                <div style="text-align: center; font-size: 12px; margin-top: 50px;">
                    <strong>Factura emitida por sistema de DamiSoft</strong>
                </div>
            </body>
        </html>';

        return $html;
    }

    private function formatNumber($num): string
    {
        return number_format((float) $num, 2, '.', '');
    }


}