<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api', function ($routes) {
    //company
    $routes->post('company', 'CompanyController::create');

    $routes->post('factura/envio', 'FacturaController::sendApi');

    $routes->post('factura/consulta-ride', 'RideController::consultaRideFactura');

    $routes->post('factura/notificacion-correo', 'CorreoController::envioFactura');

});