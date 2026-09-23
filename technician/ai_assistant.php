<?php
/**
 * AI Assistant endpoint (technician panel)
 * Actions:
 *   similar   -> keyword search over past resolutions + knowledge base
 *   generate  -> LLM draft resolution for a ticket (or free-form problem)
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

$action = $_POST['action'] ?? '';
$ai = new AIAssistantService();

switch ($action) {
    case 'similar':
        $problem = trim($_POST['problem'] ?? '');
        if ($problem === '') {
            echo json_encode(['success' => false, 'message' => 'Problem description is required.']);
        } else {
            $category = trim($_POST['category'] ?? '');
            $limit = max(1, min(10, (int)($_POST['limit'] ?? 5)));
            $exclude = (int)($_POST['ticket_id'] ?? 0);
            $results = $ai->findSimilarSolutions($problem, $limit, $category, $exclude);
            logActivity('AI_ASSIST', "Technician searched similar solutions");
            echo json_encode(['success' => true, 'results' => $results]);
        }
        break;

    case 'generate':
        $ticket = null;
        $problem = trim($_POST['problem'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);

        if ($ticket_id > 0) {
            $ticket = $ai->getConnection()->query("SELECT * FROM tickets WHERE id = $ticket_id")->fetch_assoc();
            if (!$ticket) {
                echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
                break;
            }
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

        $result = $ai->generateResolution($problem, $category, $ticket);
        if ($result['success']) {
            logActivity('AI_ASSIST', "Technician generated AI draft resolution");
        }
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}