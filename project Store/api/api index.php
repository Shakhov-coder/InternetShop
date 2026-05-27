<?php
// api/index.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db.php';
$db = new Database();
$pdo = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$resource = $path[0] ?? null;
$id = $path[1] ?? null;

// ========== РАБОТА С ТОВАРАМИ ==========
if ($resource === 'products') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } else {
            $stmt = $pdo->query("SELECT * FROM products");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO products (name, price, stock) VALUES (?, ?, ?)");
        $stmt->execute([$data['name'], $data['price'], $data['stock']]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'status' => 'created']);
    }
    elseif ($method === 'DELETE') {
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'deleted']);
        }
    }
}

// ========== РАБОТА С ЗАКАЗАМИ ==========
elseif ($resource === 'orders') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("
                SELECT o.*, 
                       json_group_array(
                           json_object('product_id', oi.product_id, 'quantity', oi.quantity)
                       ) as items
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.id = ?
                GROUP BY o.id
            ");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } else {
            $stmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $pdo->beginTransaction();
        try {
            // Вычисляем общую сумму заказа
            $total = 0;
            foreach ($data['items'] as $item) {
                $prod = $pdo->prepare("SELECT price FROM products WHERE id = ?");
                $prod->execute([$item['product_id']]);
                $price = $prod->fetchColumn();
                $total += $price * $item['quantity'];
            }

            // Создаём заказ
            $stmt = $pdo->prepare("INSERT INTO orders (customer_name, total) VALUES (?, ?)");
            $stmt->execute([$data['customer_name'], $total]);
            $orderId = $pdo->lastInsertId();

            // Добавляем позиции заказа
            foreach ($data['items'] as $item) {
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$orderId, $item['product_id'], $item['quantity']]);
            }

            $pdo->commit();
            echo json_encode(['order_id' => $orderId, 'total' => $total, 'status' => 'created']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    elseif ($method === 'DELETE') {
        if ($id) {
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'order deleted']);
        }
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
?>