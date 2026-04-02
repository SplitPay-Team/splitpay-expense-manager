<?php
// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if config exists, if not redirect to install
if (!file_exists('config.php')) {
    header('Location: install.php');
    exit;
}

// Load required files with error handling
$required_files = [
    'includes/Database.php',
    'includes/Template.php',
    'includes/Auth.php',
    'includes/User.php',
    'includes/Payment.php',
    'includes/Settlement.php',
    'includes/functions.php'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("Critical Error: Required file '$file' not found. Please ensure all files are uploaded correctly.");
    }
    require_once $file;
}

// Initialize core classes
try {
    $template = new Template();
    $auth = new Auth();
    $user = new User();
    $payment = new Payment();
    $settlement = new Settlement();
} catch (Exception $e) {
    die("Error initializing application: " . $e->getMessage());
}

// Route handling
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Remove base path if application is in subfolder
// Uncomment and modify if your app is in a subfolder
// $base_path = '/your-subfolder';
// if (strpos($path, $base_path) === 0) {
//     $path = substr($path, strlen($base_path));
// }

// API Routes
if (strpos($path, '/api/') === 0) {
    handleApiRequest($path, $method, $auth, $user, $payment, $settlement);
    exit;
}

// Public routes
if ($path === '/' || $path === '/index.php') {
    if ($auth->isLoggedIn()) {
        header('Location: /dashboard');
    } else {
        header('Location: /login');
    }
    exit;
}

// Authentication routes
if ($path === '/login') {
    if ($method === 'POST') {
        $result = $auth->login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['success']) {
            header('Location: /dashboard');
            exit;
        } else {
            $template->assign('error', $result['message']);
        }
    }
    
    $content = $template->render('login.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

if ($path === '/register') {
    if ($method === 'POST') {
        if (($_POST['password'] ?? '') !== ($_POST['confirm_password'] ?? '')) {
            $template->assign('error', 'Passwords do not match');
        } else {
            $result = $auth->register($_POST['username'] ?? '', $_POST['password'] ?? '');
            if ($result['success']) {
                $template->assign('success', $result['message']);
            } else {
                $template->assign('error', $result['message']);
            }
        }
    }
    
    $content = $template->render('register.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

if ($path === '/logout') {
    $auth->logout();
    header('Location: /login');
    exit;
}

// Protected routes
if (!$auth->isLoggedIn()) {
    header('Location: /login');
    exit;
}

$currentUser = $auth->getCurrentUser();
$template->assign('loggedIn', true);
$template->assign('username', $currentUser['username']);

// Dashboard
if ($path === '/dashboard') {
    $balance = $payment->getUserBalance($currentUser['id']);
    $payments = $payment->getUserPayments($currentUser['id']);
    
    $template->assign('netBalance', number_format($balance['net_balance'], 2));
    $template->assign('toReceive', number_format($balance['to_receive'], 2));
    $template->assign('toPay', number_format($balance['to_pay'], 2));
    $template->assign('balanceClass', $balance['net_balance'] >= 0 ? 'positive' : 'negative');
    
    // Prepare payments for template
    $paymentsData = [];
    foreach (array_slice($payments, 0, 5) as $p) { // Show only 5 recent payments
        $paymentsData[] = [
            'id' => $p['id'],
            'payment_date' => date('M d, Y', strtotime($p['payment_date'])),
            'amount' => number_format($p['amount'], 2),
            'description' => htmlspecialchars($p['description']),
            'payer_name' => htmlspecialchars($p['payer_name'])
        ];
    }
    $template->assign('payments', $paymentsData);
    
    $content = $template->render('dashboard.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

// Payments list
// Payments list
if ($path === '/payments') {
    $payments = $payment->getUserPayments($currentUser['id']);
    
    $paymentsData = [];
    foreach ($payments as $p) {
        // Check if user is actually a participant in this payment
        $isParticipant = false;
        $paymentDetails = $payment->getPaymentDetails($p['id']);
        
        foreach ($paymentDetails['participants'] as $participant) {
            if ($participant['user_id'] == $currentUser['id']) {
                $isParticipant = true;
                break;
            }
        }
        
        $paymentsData[] = [
            'id' => $p['id'],
            'payment_date' => date('M d, Y', strtotime($p['payment_date'])),
            'amount' => number_format($p['amount'], 2),
            'description' => htmlspecialchars($p['description']),
            'payer_name' => htmlspecialchars($p['payer_name']),
            'isPayer' => ($p['payer_id'] == $currentUser['id']),
            'isParticipant' => $isParticipant
        ];
    }
    
    $template->assign('payments', $paymentsData);
    $template->assign('payments_count', count($paymentsData)); // Add this for debugging
    
    $content = $template->render('payments.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

// Add payment
if ($path === '/add-payment') {
    if ($method === 'POST') {
        $includeMyself = isset($_POST['include_myself']);
        $participants = !empty($_POST['participants']) ? explode(',', $_POST['participants']) : [];
        $participants = array_filter($participants); // Remove empty values
        
        $result = $payment->create(
            $currentUser['id'],
            $_POST['amount'] ?? 0,
            $_POST['description'] ?? '',
            $_POST['payment_date'] ?? date('Y-m-d'),
            $participants,
            $includeMyself
        );
        
        if ($result['success']) {
            header('Location: /payments');
            exit;
        } else {
            $template->assign('error', $result['message']);
        }
    }
    
    $template->assign('today', date('Y-m-d'));
    $content = $template->render('add-payment.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

// View single payment
if (preg_match('/^\/payment\/(\d+)$/', $path, $matches)) {
    $paymentId = $matches[1];
    $paymentDetails = $payment->getPaymentDetails($paymentId);
    
    if ($paymentDetails) {
        // You can create a payment-detail.html template
        header('Location: /payments');
        exit;
    }
    
    header('Location: /payments');
    exit;
}

// Settlements
if ($path === '/settlements' || $path === '/settlements.php') {
    $outstanding = $settlement->getOutstandingSettlements($currentUser['id']);
    
    // Debug: Log the data to see what's coming from the database
    error_log('Raw outstanding settlements: ' . print_r($outstanding, true));
    
    // Make sure each settlement has all required fields
    $processedSettlements = [];
    foreach ($outstanding as $item) {
        // Ensure payment_id is explicitly set
        $processedSettlements[] = [
            'participant_id' => $item['participant_id'],
            'debtor_name' => $item['debtor_name'],
            'description' => $item['description'],
            'outstanding' => $item['outstanding'],
            'payment_id' => $item['payment_id'] // Make sure this is included
        ];
    }
    
    $template->assign('settlements', $processedSettlements);
    
    $content = $template->render('settlements.html');
    echo $template->renderWithHeaderFooter($content);
    exit;
}

// 404
header("HTTP/1.0 404 Not Found");
echo "<h1>404 - Page Not Found</h1>";
echo "<p>The page you are looking for does not exist.</p>";
echo "<p><a href='/dashboard'>Return to Dashboard</a></p>";

// API Handler
function handleApiRequest($path, $method, $auth, $user, $payment, $settlement) {
    header('Content-Type: application/json');
    
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $currentUser = $auth->getCurrentUser();
    
    // Search users API
    if ($path === '/api/search-users' && $method === 'GET') {
        $query = $_GET['q'] ?? '';
        $users = $user->searchUsers($query, $currentUser['id']);
        echo json_encode($users);
        exit;
    }
    
    // Settle payment API
    if ($path === '/api/settle' && $method === 'POST') {
        // Get and validate parameters
        $participantId = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
        $paymentId = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
        $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? floatval($_POST['amount']) : null;
        
        error_log("Settlement request - Participant: $participantId, Payment: $paymentId, Amount: " . ($amount ?? 'full'));
        
        if (!$participantId || !$paymentId) {
            echo json_encode([
                'success' => false, 
                'message' => 'Missing required parameters: participant_id and payment_id are required'
            ]);
            exit;
        }
        
        $result = $settlement->settleParticipant($paymentId, $participantId, $amount);
        echo json_encode($result);
        exit;
    }
    
    // Delete payment API
    if (preg_match('/^\/api\/delete-payment\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
        $paymentId = $matches[1];
        $result = $payment->deletePayment($paymentId, $currentUser['id']);
        echo json_encode($result);
        exit;
    }
    
    // If no API route matched
    http_response_code(404);
    echo json_encode(['error' => 'Invalid API endpoint']);
    exit;
}
?>
