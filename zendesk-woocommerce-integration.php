<?php
/*
Plugin Name: Zendesk WooCommerce Integracion con Suop
Description: Plugin para crear los usuarios de WordPress y WooCommerce en Zendesk
Version: 1.1
Author: <a href="https://www.suop.es/" target="_blank">SUOP</a> - Hugo Perez-Vigo
*/

// Evita el acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar Composer autoload y Dotenv
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar variables de entorno desde el archivo .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Acceder a las variables de entorno
$subdomain = $_ENV['ZENDESK_SUBDOMAIN'];
$apiUser = $_ENV['ZENDESK_API_USER'];
$apiToken = $_ENV['ZENDESK_API_TOKEN'];

// Definir constantes con las variables de entorno
define('ZENDESK_SUBDOMAIN', $subdomain);
define('ZENDESK_API_USER', $apiUser);
define('ZENDESK_API_TOKEN', $apiToken);

// Verifica si WooCommerce está activado
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'zendesk_woocommerce_missing_notice');
    return;
}

// Mensaje de advertencia si WooCommerce no está activado
function zendesk_woocommerce_missing_notice() {
    echo '<div class="error"><p>El plugin <strong>Zendesk WooCommerce Integration</strong> requiere que WooCommerce esté instalado y activado.</p></div>';
}

// Función para hacer solicitudes a la API de Zendesk
function zendesk_api_request($endpoint, $method = 'GET', $data = []) {
    $url = "https://" . ZENDESK_SUBDOMAIN . ".zendesk.com/api/v2/$endpoint";
    $args = [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(ZENDESK_API_USER . ':' . ZENDESK_API_TOKEN),
            'Content-Type' => 'application/json'
        ],
        'method' => $method,
        'body' => $method !== 'GET' ? json_encode($data) : null
    ];

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        error_log('Error en la solicitud a Zendesk: ' . $response->get_error_message());
        return false;
    }

    // Log de la respuesta de la API
    error_log('Respuesta de Zendesk: ' . print_r($response, true));

    return json_decode(wp_remote_retrieve_body($response), true);
}

// Obtener el ID de un usuario en Zendesk por su correo electrónico
function get_zendesk_user_id_by_email($email) {
    if (empty($email)) {
        error_log('Correo electrónico no proporcionado para buscar en Zendesk.');
        return false;
    }

    $response = zendesk_api_request("users/search.json?query=" . urlencode($email));

    if ($response && !empty($response['users'])) {
        return $response['users'][0]['id']; // Devuelve el ID del primer usuario encontrado
    }

    return false;
}

// Crear o actualizar usuario en Zendesk
function create_or_update_zendesk_user_on_registration($user_id) {
    error_log('Usuario registrado en WordPress: ' . $user_id); // Log temporal
    $user = get_userdata($user_id);

    // Verificar si el usuario existe
    if (!$user || !$user->user_email) {
        error_log('Usuario no encontrado o no tiene correo electrónico: ' . $user_id);
        return;
    }

    // Log de los datos del usuario
    error_log('Datos del usuario: ' . print_r($user, true));

    // Datos del usuario
    $user_data = [
        'user' => [
            'name' => ($user->first_name && $user->last_name) ? $user->first_name . ' ' . $user->last_name : $user->user_login,
            'email' => $user->user_email,
            'role' => 'end-user',
            'user_fields' => [
                'fecha_creacion' => date('Y-m-d H:i:s'), // Campo personalizado: fecha de creación
                'canal_de_venta' => 'Travel eSIM' // Campo personalizado: canal de venta
            ],
            'tags' => ['Travel eSIM'], // Etiquetas
            'notes' => 'Travel eSIM' // Nota
        ]
    ];

    // Verificar si el usuario ya existe en Zendesk
    $zendesk_user_id = get_zendesk_user_id_by_email($user->user_email);

    if ($zendesk_user_id) {
        // Actualizar usuario existente
        $result = zendesk_api_request("users/{$zendesk_user_id}.json", 'PUT', $user_data);
    } else {
        // Crear nuevo usuario
        $result = zendesk_api_request('users.json', 'POST', $user_data);
    }

    if (!$result) {
        error_log('Error al crear/actualizar el usuario en Zendesk para el usuario de WordPress ID: ' . $user_id);
    }
}
add_action('user_register', 'create_or_update_zendesk_user_on_registration');
