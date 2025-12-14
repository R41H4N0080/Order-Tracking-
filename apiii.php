<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$ordersFile = 'orders.json';
$adminFile = 'admin.json';

if (!file_exists($ordersFile)) {
    file_put_contents($ordersFile, json_encode([]));
}
if (!file_exists($adminFile)) {
    $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
    file_put_contents($adminFile, json_encode(['password' => $defaultPassword]));
}

function readJson($file) {
    return json_decode(file_get_contents($file), true);
}

function writeJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function sendResponse($success, $message, $data = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

function generateOrderId() {
    return 'ORD' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . time() % 10000;
}

function verifyAuth() {
    global $adminFile;
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';
    
    if (empty($token)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
    }
    
    if (strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    
    if (empty($token)) {
        sendResponse(false, 'Authentication required', null);
    }
    
    $admin = readJson($adminFile);
    if (!isset($admin['session_token']) || $admin['session_token'] !== $token) {
        sendResponse(false, 'Invalid session', null);
    }
    
    if (!isset($admin['session_expires']) || strtotime($admin['session_expires']) <= time()) {
        sendResponse(false, 'Session expired', null);
    }
    
    return true;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$publicActions = ['get_order', 'login'];

$protectedActions = ['get_stats', 'get_orders', 'create_order', 'update_order', 'delete_order', 'verify_session', 'change_password', 'logout'];

if (in_array($action, $protectedActions) && $action !== 'verify_session') {
    verifyAuth();
}

switch ($action) {
    case 'get_order':
        $id = $_GET['id'] ?? '';
        $orders = readJson($ordersFile);
        $order = array_filter($orders, fn($o) => $o['id'] === $id);
        if ($order) {
            sendResponse(true, 'Order found', array_values($order)[0]);
        } else {
            sendResponse(false, 'Order not found');
        }
        break;

    case 'get_stats':
        $orders = readJson($ordersFile);
        $total = count($orders);
        $pending = count(array_filter($orders, fn($o) => ($o['order_status'] ?? 'Pending') === 'Pending'));
        $processing = count(array_filter($orders, fn($o) => ($o['order_status'] ?? '') === 'Processing'));
        $completed = count(array_filter($orders, fn($o) => ($o['order_status'] ?? '') === 'Completed'));
        $cancelled = count(array_filter($orders, fn($o) => ($o['order_status'] ?? '') === 'Cancelled'));
        $totalRevenue = array_sum(array_column($orders, 'order_amount'));
        $paidAmount = array_sum(array_map(function($o) {
            return ($o['payment_status'] ?? '') === 'Paid' ? floatval($o['payment_amount'] ?? 0) : 0;
        }, $orders));
        $unpaidAmount = $totalRevenue - $paidAmount;
        sendResponse(true, 'Stats retrieved', [
            'total' => $total, 
            'pending' => $pending, 
            'processing' => $processing,
            'completed' => $completed, 
            'cancelled' => $cancelled,
            'total_revenue' => $totalRevenue,
            'paid_amount' => $paidAmount,
            'unpaid_amount' => $unpaidAmount
        ]);
        break;

    case 'get_orders':
        $orders = readJson($ordersFile);
        usort($orders, function($a, $b) {
            return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
        });
        sendResponse(true, 'Orders retrieved', $orders);
        break;

    case 'create_order':
        $input = json_decode(file_get_contents('php://input'), true);
        $orders = readJson($ordersFile);
        
        $newOrder = [
            'id' => $input['id'] ?? generateOrderId(),
            'customer_name' => $input['customer_name'] ?? '',
            'customer_email' => $input['customer_email'] ?? '',
            'customer_phone' => $input['customer_phone'] ?? '',
            'order_description' => $input['order_description'] ?? '',
            'order_amount' => floatval($input['order_amount'] ?? 0),
            'order_status' => $input['order_status'] ?? 'Pending',
            'order_link' => $input['order_link'] ?? '',
            'payment_method' => $input['payment_method'] ?? '',
            'payment_amount' => floatval($input['payment_amount'] ?? 0),
            'payment_status' => $input['payment_status'] ?? 'Unpaid',
            'payment_link' => $input['payment_link'] ?? '',
            'notes' => $input['notes'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'updates' => [['date' => date('Y-m-d H:i:s'), 'message' => 'Order created']]
        ];
        
        $orders[] = $newOrder;
        writeJson($ordersFile, $orders);
        sendResponse(true, 'Order created successfully', $newOrder);
        break;

    case 'update_order':
        $input = json_decode(file_get_contents('php://input'), true);
        $orders = readJson($ordersFile);
        $found = false;
        
        foreach ($orders as &$order) {
            if ($order['id'] === $input['id']) {
                $order['customer_name'] = $input['customer_name'] ?? $order['customer_name'] ?? '';
                $order['customer_email'] = $input['customer_email'] ?? $order['customer_email'] ?? '';
                $order['customer_phone'] = $input['customer_phone'] ?? $order['customer_phone'] ?? '';
                $order['order_description'] = $input['order_description'] ?? $order['order_description'] ?? '';
                $order['order_amount'] = floatval($input['order_amount'] ?? $order['order_amount'] ?? 0);
                $order['order_status'] = $input['order_status'] ?? $order['order_status'] ?? 'Pending';
                $order['order_link'] = $input['order_link'] ?? $order['order_link'] ?? '';
                $order['payment_method'] = $input['payment_method'] ?? $order['payment_method'] ?? '';
                $order['payment_amount'] = floatval($input['payment_amount'] ?? $order['payment_amount'] ?? 0);
                $order['payment_status'] = $input['payment_status'] ?? $order['payment_status'] ?? 'Unpaid';
                $order['payment_link'] = $input['payment_link'] ?? $order['payment_link'] ?? '';
                $order['notes'] = $input['notes'] ?? $order['notes'] ?? '';
                $order['updated_at'] = date('Y-m-d H:i:s');
                
                if (!empty($input['update_message'])) {
                    if (!isset($order['updates'])) {
                        $order['updates'] = [];
                    }
                    $order['updates'][] = ['date' => date('Y-m-d H:i:s'), 'message' => $input['update_message']];
                }
                $found = true;
                break;
            }
        }
        
        if ($found) {
            writeJson($ordersFile, $orders);
            sendResponse(true, 'Order updated successfully');
        } else {
            sendResponse(false, 'Order not found');
        }
        break;

    case 'delete_order':
        $input = json_decode(file_get_contents('php://input'), true);
        $orders = readJson($ordersFile);
        $orders = array_filter($orders, fn($o) => $o['id'] !== $input['id']);
        writeJson($ordersFile, array_values($orders));
        sendResponse(true, 'Order deleted successfully');
        break;

    case 'login':
        $input = json_decode(file_get_contents('php://input'), true);
        $admin = readJson($adminFile);
        if (password_verify($input['password'], $admin['password'])) {
            $token = bin2hex(random_bytes(32));
            $admin['session_token'] = $token;
            $admin['session_expires'] = date('Y-m-d H:i:s', strtotime('+24 hours'));
            writeJson($adminFile, $admin);
            sendResponse(true, 'Login successful', ['token' => $token]);
        } else {
            sendResponse(false, 'Invalid password');
        }
        break;

    case 'verify_session':
        $input = json_decode(file_get_contents('php://input'), true);
        $admin = readJson($adminFile);
        $token = $input['token'] ?? '';
        if (isset($admin['session_token']) && $admin['session_token'] === $token) {
            if (strtotime($admin['session_expires']) > time()) {
                sendResponse(true, 'Session valid');
            }
        }
        sendResponse(false, 'Session invalid');
        break;

    case 'change_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $admin = readJson($adminFile);
        
        if (!password_verify($input['current_password'], $admin['password'])) {
            sendResponse(false, 'Current password is incorrect');
        }
        
        if (strlen($input['new_password']) < 6) {
            sendResponse(false, 'New password must be at least 6 characters');
        }
        
        $admin['password'] = password_hash($input['new_password'], PASSWORD_DEFAULT);
        unset($admin['session_token']);
        unset($admin['session_expires']);
        writeJson($adminFile, $admin);
        sendResponse(true, 'Password changed successfully. Please login again.');
        break;

    case 'logout':
        $admin = readJson($adminFile);
        unset($admin['session_token']);
        unset($admin['session_expires']);
        writeJson($adminFile, $admin);
        sendResponse(true, 'Logged out successfully');
        break;

    default:
        sendResponse(false, 'Invalid action');
        break;
}
?>
