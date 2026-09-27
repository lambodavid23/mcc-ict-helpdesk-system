<?php
/**
 * AI Assistant endpoint (technician panel)
 *
 * Actions:
 *   status   -> capability report, used by the panel to show engine state
 *   similar  -> keyword search over past resolutions + knowledge base
 *   generate -> draft resolution for a ticket (or free-form problem)
 *
 * Requires an authenticated admin or technician session plus a valid CSRF
 * token. Responds with JSON only.
 */

require_once '../config/auth_helper.php';
require_once '../config/database.php';
require_once '../config/AIAssistantService.php';

requireLogin();

if (!in_array($_SESSION['user_role'] ?? '', ['technician', 'admin'], true)) {
    $_SESSION['error'] = 'Access denied. You do not have permission to access this page.';
    header('Location: ../index.php');
    exit();
}

header('Content-Type: application/json');

$action    = $_POST['action'] ?? '';
$csrf      = $_POST['csrf_token'] ?? '';
$ai        = new AIAssistantService();

if ($action === 'status') {
    echo json_encode(['success' => true, 'status' => $ai->getStatus()]);
    exit();
}

if (!validateCSRFToken($csrf)) {
    echo json_encode([
        'success' => false,
        'message' => 'Your session expired or the request could not be verified. Reload the page and try again.',
    ]);
    exit();
}

switch ($action) {
    case 'similar':
        $problem = trim($_POST['problem'] ?? '');
        if ($problem === '') {
            echo json_encode(['success' => false, 'message' => 'Problem description is required.']);
            break;
        }
        $category = trim($_POST['category'] ?? '');
        $limit    = max(1, min(10, (int)($_POST['limit'] ?? 5)));
        $exclude  = (int)($_POST['ticket_id'] ?? 0);

        $started  = microtime(true);
        $results  = $ai->findSimilarSolutions($problem, $limit, $category, $exclude);
        $elapsed  = (int)round((microtime(true) - $started) * 1000);

        logActivity('AI_ASSIST', 'Technician searched similar solutions');
        echo json_encode([
            'success'    => true,
            'results'    => $results,
            'elapsed_ms' => $elapsed,
        ]);
        break;

    case 'generate':
        $conn     = $ai->getConnection();
        $ticket   = null;
        $problem  = trim($_POST['problem'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $force    = !empty($_POST['force']);

        if ($ticketId > 0) {
            $ticket = $conn->query(
                'SELECT * FROM tickets WHERE id = ' . $ticketId
            )->fetch_assoc();

            if (!$ticket) {
                echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
                break;
            }

            // Any signed-in technician or admin may use the assistant on any
            // ticket they can open - the queue deliberately lists unassigned
            // work so it can be inspected before being claimed.
            if ($category === '') {
                $category = $ticket['category'];
            }
            if ($problem === '') {
                $problem = $ticket['title'] . ' - ' . $ticket['description'];
            }
        } elseif ($problem === '') {
            echo json_encode(['success' => false, 'message' => 'Problem description is required.']);
            break;
        }

        $result = $ai->generateResolution($problem, $category, $ticket, $force);

        if (!empty($result['success']) && empty($result['cached'])) {
            logActivity('AI_ASSIST', sprintf(
                'Draft resolution generated via %s in %dms',
                $result['source'] ?? 'local',
                (int)($result['elapsed_ms'] ?? 0)
            ));
        }

        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}
