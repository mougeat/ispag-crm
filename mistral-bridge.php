<?php
// === Gestion des requêtes de validation de Mistral AI ===
// Mistral AI envoie une requête GET ou OPTIONS pour valider le connecteur
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Content-Type: application/json');
    header('HTTP/1.1 200 OK');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    echo json_encode([
        "status" => "ok",
        "message" => "ISPAG Sales Planning MCP connector is operational",
        "version" => "1.0.0"
    ]);
    exit;
}

// Activer l'affichage des erreurs et des logs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Chemin du fichier de log
$log_file = dirname(__FILE__) . '/mcp_debug.log';

// Fonction pour écrire dans le log
function log_message($message) {
    global $log_file;
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND | LOCK_EX);
}

// Début des logs
log_message("=== NOUVELLE REQUÊTE MCP ===");
log_message("Méthode HTTP: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// --- NOUVELLE SECTION : Gestion avancée des headers pour OVH ---
// Récupération des headers (compatible OVH et redirections)
$headers = [];
if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
} else {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $header_name = str_replace('HTTP_', '', $key);
            $headers[$header_name] = $value;
        }
    }
}

// === CORRECTION CRITIQUE : Gestion des headers redirigés par OVH ===
// OVH place souvent l'Authorization dans REDIRECT_HTTP_AUTHORIZATION
if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}

// Debug: Log des headers reçus (pour vérifier)
log_message("Headers complets: " . print_r($headers, true));

// Charger WordPress de manière sécurisée
$wp_load_path = false;
$current_dir = dirname(__FILE__);

// Essayer plusieurs chemins possibles pour wp-load.php
for ($i = 1; $i <= 5; $i++) {
    $test_path = dirname($current_dir, $i) . '/wp-load.php';
    if (file_exists($test_path)) {
        $wp_load_path = $test_path;
        break;
    }
}

// Si toujours pas trouvé, essayer avec ABSPATH
if (!$wp_load_path && defined('ABSPATH')) {
    $wp_load_path = ABSPATH . 'wp-load.php';
}

if (!$wp_load_path || !file_exists($wp_load_path)) {
    log_message("Error: Impossible de trouver wp-load.php");
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode([
        "jsonrpc" => "2.0",
        "error" => [
            "code" => -32001,
            "message" => "Impossible de charger WordPress (wp-load.php introuvable)"
        ]
    ]));
}

// Charger WordPress
require_once($wp_load_path);

// Vérifier que la constante CRM_MCP_API_KEY est définie
if (!defined('CRM_MCP_API_KEY')) {
    log_message("Error: CRM_MCP_API_KEY is not defined in wp-config.php");
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode([
        "jsonrpc" => "2.0",
        "error" => [
            "code" => -32002,
            "message" => "La clé CRM_MCP_API_KEY is not defined in wp-config.php"
        ]
    ]));
}

// --- CORRECTION : Utilisation des headers avec priorité ---
// On vérifie d'abord REDIRECT_HTTP_AUTHORIZATION (OVH), puis HTTP_AUTHORIZATION, puis les headers normaux
$auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';
$received_key = str_replace('Bearer ', '', $auth_header);

if (empty($received_key)) {
    $received_key = $_GET['key'] ?? '';
    log_message("Clé reçue via GET: " . $received_key);
} else {
    log_message("Clé reçue via Header: " . $received_key);
}

log_message("Clé attendue (CRM_MCP_API_KEY): " . CRM_MCP_API_KEY);

if ($received_key !== CRM_MCP_API_KEY) {
    log_message("Error: Clé invalide. Reçue: '$received_key', Attendue: '" . CRM_MCP_API_KEY . "'");
    header('HTTP/1.0 403 Forbidden');
    die(json_encode([
        "jsonrpc" => "2.0",
        "error" => [
            "code" => -32600,
            "message" => "Access denied - Invalid key"
        ]
    ]));
}

// Lire la requête MCP
$json_input = file_get_contents('php://input');
log_message("Requête MCP reçue: " . $json_input);

$request = json_decode($json_input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    log_message("Error JSON: " . json_last_error_msg());
    header('HTTP/1.0 400 Bad Request');
    die(json_encode([
        "jsonrpc" => "2.0",
        "error" => [
            "code" => -32700,
            "message" => "Invalid JSON request"
        ]
    ]));
}

$method = $request['method'] ?? '';
$id = $request['id'] ?? null;

// Charger la classe API
$class_file = plugin_dir_path(__FILE__) . 'classes/class-ispag-agent-commercial-api.php';
if (!file_exists($class_file)) {
    log_message("Error: Fichier de classe introuvable: " . $class_file);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        "jsonrpc" => "2.0",
        "id" => $id,
        "error" => [
            "code" => -32002,
            "message" => "Fichier de classe introuvable"
        ]
    ]);
    exit;
}
require_once $class_file;

// 5. Gérer les méthodes MCP
switch ($method) {
    case 'initialize':
        // Réponse complète pour Mistral AI
        log_message("Réponse à initialize");
        header('Content-Type: application/json');
        echo json_encode([
            "jsonrpc" => "2.0",
            "id" => $id,
            "result" => [
                "protocolVersion" => "2025-11-25",
                "capabilities" => [
                    "tools" => []
                ],
                "serverInfo" => [
                    "name" => "ISPAG Planning Commercial Connector",
                    "version" => "1.0.0"
                ]
            ]
        ]);
        break;

    case 'list_tools':
        // Liste des outils disponibles
        log_message("Réponse à list_tools");
        header('Content-Type: application/json');
        echo json_encode([
            "jsonrpc" => "2.0",
            "id" => $id,
            "result" => [
                "tools" => [
                    [
                        "name" => "get_commercial_planning",
                        "description" => "Récupère les deals et tâches de Cyril chez ISPAG",
                        "inputSchema" => [
                            "type" => "object",
                            "properties" => [
                                "view_user" => [
                                    "type" => "integer",
                                    "description" => "L'ID de l'utilisateur (ex: 6048)",
                                    "default" => 6048
                                ]
                            ],
                            "required" => ["view_user"]
                        ]
                    ]
                ]
            ]
        ]);
        break;

    case 'tools/call':
        // Appel d'outil
        log_message("Appel de tools/call");
        $target_id = $request['params']['arguments']['view_user'] ?? 6048;
        log_message("view_user utilisé: " . $target_id);

        if (class_exists('Ispag_Agent_Commercial_API')) {
            try {
                $api = new Ispag_Agent_Commercial_API();
                $wp_request = new WP_REST_Request('GET', '/ispag/v1/planning');
                $wp_request->set_param('view_user', $target_id);
                $data = $api->get_planning_data($wp_request);

                header('Content-Type: application/json');
                echo json_encode([
                    "jsonrpc" => "2.0",
                    "id" => $id,
                    "result" => [
                        "content" => [
                            [
                                "type" => "text",
                                "text" => $data->get_data()
                            ]
                        ],
                        "isError" => false
                    ]
                ]);
            } catch (Exception $e) {
                log_message("Error dans tools/call: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode([
                    "jsonrpc" => "2.0",
                    "id" => $id,
                    "error" => [
                        "code" => -32000,
                        "message" => "Error interne: " . $e->getMessage()
                    ]
                ]);
            }
        } else {
            log_message("Error: Classe Ispag_Agent_Commercial_API introuvable");
            header('Content-Type: application/json');
            echo json_encode([
                "jsonrpc" => "2.0",
                "id" => $id,
                "error" => [
                    "code" => -32000,
                    "message" => "Classe API introuvable"
                ]
            ]);
        }
        break;

    case 'notifications/initialized':
        // Notification de démarrage (optionnel)
        log_message("Réponse à notifications/initialized");
        header('Content-Type: application/json');
        echo json_encode([
            "jsonrpc" => "2.0",
            "id" => $id,
            "result" => []
        ]);
        break;

    default:
        // Méthode non supportée
        log_message("Error: Unsupported method: " . $method);
        header('Content-Type: application/json');
        echo json_encode([
            "jsonrpc" => "2.0",
            "id" => $id,
            "error" => [
                "code" => -32601,
                "message" => "Unsupported method: " . $method
            ]
        ]);
        break;
}