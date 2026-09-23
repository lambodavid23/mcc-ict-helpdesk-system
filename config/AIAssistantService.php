<?php
/**
 * AI Assistant Service
 * Smart ICT Helpdesk System - Mutare City Council
 *
 * Hybrid technician assistant built on two engines:
 *
 *  1. Local retrieval (always works, no API key):
 *     Similar past resolutions (fault_history) and Knowledge Base articles,
 *     scored by keyword relevance against the current problem.
 *
 *  2. LLM draft generation (optional):
 *     Sends the ticket context plus the best similar cases to an
 *     OpenAI-compatible chat-completions endpoint and returns a draft
 *     resolution the technician can review and edit.
 *
 * To enable LLM generation, either set the AI_* environment variables
 * (AI_ENABLED, AI_ENDPOINT, AI_API_KEY, AI_MODEL) or change the $llm
 * defaults in the constructor below. Local suggestions need nothing.
 */

require_once 'database.php';

class AIAssistantService {
    private $conn;

    // LLM configuration. findSimilarSolutions() never needs this; only
    // generateResolution() does.
    //
    // Defaults target a LOCAL Ollama installation (Installed for this project):
    //   AI_ENDPOINT = http://localhost:11434/v1/chat/completions
    //   AI_MODEL    = llama3.2:3b   (imported in the Ollama setup)
    //   AI_API_KEY  = ''            (no key needed for local models)
    //
    // To switch to a hosted provider (e.g. OpenAI), either set the AI_* env
    // variables or change these defaults.
    private $llm = [
        'enabled'  => true,
        'endpoint' => 'http://localhost:11434/v1/chat/completions',
        'api_key'  => '',
        'model'    => 'llama3.2:3b',
        'timeout'  => 90,
    ];

    public function __construct() {
        $this->conn = (new Database())->getConnection();
        $this->applyEnvConfig();
    }

    private function applyEnvConfig() {
        $env = getenv('AI_ENABLED');
        if ($env !== false) {
            $this->llm['enabled'] = filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        $endpoint = getenv('AI_ENDPOINT');
        if ($endpoint !== false) {
            $this->llm['endpoint'] = trim($endpoint);
        }
        $key = getenv('AI_API_KEY');
        if ($key !== false) {
            $this->llm['api_key'] = trim($key);
        }
        $model = getenv('AI_MODEL');
        if ($model !== false) {
            $this->llm['model'] = trim($model);
        }
        // Auto-enable when a key is configured and no explicit toggle was set.
        if ($env === false && $this->llm['api_key'] !== '') {
            $this->llm['enabled'] = true;
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function isLlmEnabled() {
        return $this->llm['enabled'];
    }

    public function getLlmConfig() {
        return $this->llm;
    }

    /**
     * Find the most similar past resolutions and knowledge base articles for
     * a given problem description. Pure keyword scoring - no external API.
     *
     * @param string $problem        The problem text to match against.
     * @param int    $limit          Max results to return.
     * @param string|null $category  Optional category; boosting exact matches.
     * @param int    $excludeTicketId Optional ticket to exclude (the current one).
     * @return array
     */
    public function findSimilarSolutions($problem, $limit = 5, $category = null, $excludeTicketId = 0) {
        $tokens = $this->tokenize($problem);
        if (!$tokens) {
            return [];
        }

        $rows = [];

        $history = $this->conn->query(
            "SELECT fh.id AS ref_id, fh.ticket_id, fh.problem AS strong, fh.solution AS weak,
                    t.title, t.category, tech.name AS tech, fh.resolved_at
             FROM fault_history fh
             JOIN tickets t ON fh.ticket_id = t.id
             LEFT JOIN technicians tech ON fh.resolved_by = tech.id
             WHERE fh.deleted_at IS NULL
               AND fh.solution IS NOT NULL AND fh.solution <> ''
               AND fh.ticket_id <> " . (int)$excludeTicketId . "
             ORDER BY fh.resolved_at DESC
             LIMIT 400"
        );
        if ($history) {
            while ($h = $history->fetch_assoc()) {
                $rows[] = [
                    'source'      => 'past',
                    'ticket_id'   => (int)$h['ticket_id'],
                    'category'    => $h['category'],
                    'title'       => $h['title'],
                    'strong'      => $h['strong'],
                    'weak'        => $h['weak'],
                    'tech'        => $h['tech'],
                    'resolved_at' => $h['resolved_at'],
                ];
            }
        }

        $kb = $this->conn->query(
            "SELECT id AS kb_id, issue_keyword AS strong, recommended_solution AS weak, category
             FROM knowledge_base"
        );
        if ($kb) {
            while ($k = $kb->fetch_assoc()) {
                $rows[] = [
                    'source'      => 'kb',
                    'ticket_id'   => 0,
                    'category'    => $k['category'],
                    'title'       => $k['strong'],
                    'strong'      => $k['strong'],
                    'weak'        => $k['weak'],
                    'tech'        => null,
                    'resolved_at' => null,
                ];
            }
        }

        $scored = [];
        foreach ($rows as $row) {
            $score = 0;

            if ($category !== null && $category !== '' && strcasecmp((string)$row['category'], (string)$category) === 0) {
                $score += 3;
            }

            foreach ($tokens as $tok) {
                if ($this->matches($tok, $row['strong'])) {
                    $score += 2;
                }
                if ($this->matches($tok, $row['weak'])) {
                    $score += 1;
                }
            }

            if ($score < 1) {
                continue;
            }

            $row['score'] = $score;
            $scored[] = $row;
        }

        usort($scored, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] - $a['score'];
            }
            $at = $a['resolved_at'] ?? '';
            $bt = $b['resolved_at'] ?? '';
            return strcmp($bt, $at);
        });

        $scored = array_slice($scored, 0, $limit);

        $clean = [];
        foreach ($scored as $r) {
            $clean[] = [
                'source'      => $r['source'],
                'ticket_id'   => $r['ticket_id'],
                'title'       => $r['title'],
                'category'    => $r['category'],
                'snippet'     => $r['weak'],
                'tech'        => $r['tech'],
                'resolved_at' => $r['resolved_at'],
                'score'       => $r['score'],
            ];
        }

        return $clean;
    }

    /**
     * Ask the configured LLM to write a draft resolution for a problem/ticket.
     *
     * @param string      $problem  Problem text (title + description).
     * @param string      $category Ticket category, for context.
     * @param array|null  $ticket   Optional tickets row (gives ticket id/priority).
     * @return array ['success' => bool, 'draft'|'message' => string]
     */
    public function generateResolution($problem, $category = '', $ticket = null) {
        $similar = $this->findSimilarSolutions($problem, 5, $category, isset($ticket['id']) ? (int)$ticket['id'] : 0);

        if (!$this->llm['enabled']) {
            return $this->buildOfflineDraft($ticket, $problem, $similar);
        }

        $lines = [];
        if ($ticket && !empty($ticket['title'])) {
            $lines[] = 'Ticket #' . (int)$ticket['id'] . ' - ' . $ticket['title'];
            $lines[] = 'Priority: ' . $ticket['priority'];
            $lines[] = 'Description: ' . $ticket['description'];
        } else {
            $lines[] = 'Problem: ' . $problem;
        }
        $lines[] = '';
        $lines[] = '=== Similar past cases to use as reference (verify, do not copy blindly) ===';
        if (!$similar) {
            $lines[] = '(none found)';
        }
        foreach ($similar as $s) {
            if ($s['source'] === 'past') {
                $lines[] = '- Past resolution for Ticket #' . $s['ticket_id'] . ' [' . $s['category'] . ']: ' . $s['snippet'];
            } else {
                $lines[] = '- Knowledge base [' . $s['category'] . ']: ' . $s['snippet'];
            }
        }
        $context = implode("\n", $lines);

        $system = <<<'EOT'
You are a senior ICT helpdesk support assistant for Mutare City Council.
Given a ticket problem and a list of similar past cases, write a clear, step-by-step
resolution a technician can follow. Structure the answer with short headings:
"1. Likely cause(s)", "2. Steps to diagnose", "3. Steps to fix".
Use plain text, no markdown tables. If the similar cases conflict, prefer the most recent.
Be concise and practical.
EOT;

        $payload = [
            'model'       => $this->llm['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $context],
            ],
            'temperature' => 0.3,
            'max_tokens'  => 900,
        ];

        $ch = curl_init($this->llm['endpoint']);
        $headers = ['Content-Type: application/json'];
        if ($this->llm['api_key'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->llm['api_key'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->llm['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => 'AI request failed: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'AI service returned HTTP ' . $code . ': ' . substr($body, 0, 300)];
        }

        $data = json_decode($body, true);
        $draft = $data['choices'][0]['message']['content'] ?? null;
        if (!$draft) {
            return ['success' => false, 'message' => 'AI returned no content. Response: ' . substr($body, 0, 300)];
        }

        return ['success' => true, 'draft' => trim($draft)];
    }

    /**
     * Compose a structured draft resolution from local history when no LLM is
     * configured. Guarantees "Generate Draft" always returns something useful.
     */
    private function buildOfflineDraft($ticket, $problem, $similar) {
        if ($ticket && !empty($ticket['title'])) {
            $meta = 'Ticket #' . (int)$ticket['id'] . ' - ' . $ticket['title']
                . ' (Priority: ' . $ticket['priority'] . ')';
        } else {
            $meta = 'Problem: ' . $problem;
        }

        $lines = [];
        $lines[] = 'Context: ' . $meta;

        if (!$similar) {
            $lines[] = '';
            $lines[] = 'No similar past solutions were found to build a draft from.';
            $lines[] = '';
            $lines[] = '1. Likely cause(s)';
            $lines[] = '- Check the ticket details and question the user for the exact symptoms.';
            $lines[] = '- Verify device power, connectivity and login credentials first.';
            $lines[] = '';
            $lines[] = '2. Steps to diagnose';
            $lines[] = '- Confirm the affected equipment/account and when the problem started.';
            $lines[] = '- Isolate whether it is hardware, network, or software related.';
            $lines[] = '';
            $lines[] = '3. Steps to fix';
            $lines[] = '- Apply the standard fix for the diagnosed cause, then test together with the user.';
            $lines[] = '- Record the resolution so future tickets benefit from the history.';
            $lines[] = '';
            $lines[] = '(Offline draft - no AI key configured, and no similar case is on file. Connect an AI API key for a tailored draft.)';
            return ['success' => true, 'draft' => implode("\n", $lines)];
        }

        $lines[] = '';
        $lines[] = '1. Likely cause(s)';
        $lines[] = 'The problem most likely shares a root cause with the top near-matches below - start there:';
        foreach (array_slice($similar, 0, 3) as $s) {
            $lines[] = '- ' . $s['title'] . ' (' . $s['category'] . ')';
        }
        $lines[] = '';
        $lines[] = '2. Steps to diagnose';
        $lines[] = '- Ask the user how long this has been happening and whether anything changed recently.';
        $lines[] = '- Walk through the highest-scoring case below and confirm each symptom matches.';
        foreach (array_slice($similar, 0, 3) as $i => $s) {
            $lines[] = '  ' . ($i + 1) . ') ' . $s['title'] . ' - ' . $s['snippet'];
        }
        $lines[] = '';
        $lines[] = '3. Steps to fix';
        $lines[] = '- Adapt the resolution of the best-matching case, verifying the steps as you go:';
        foreach (array_slice($similar, 0, 3) as $i => $s) {
            $lines[] = '  ' . ($i + 1) . ') [' . $s['category'] . '] ' . $s['snippet'];
        }
        $lines[] = '- Test the fix with the user and record the outcome in the ticket.';
        $lines[] = '';
        $lines[] = '(Offline draft auto-composed from your local history. Connect an AI API key for a tailored draft.)';

        return ['success' => true, 'draft' => implode("\n", $lines)];
    }

    private function tokenize($text) {
        $stop = [
            'the','a','an','and','or','but','for','with','without','from','into','onto','this','that',
            'these','those','my','your','our','their','his','her','its','i','we','you','me','us','to',
            'is','are','was','were','be','been','being','not','no','do','does','did','have','has','had',
            'get','got','can','cant','cannot','should','would','will','please','help','need','kindly',
            'when','how','what','why','where','who','it','there','then','than','of','at','on','in','by',
        ];
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($words as $w) {
            if (strlen($w) < 3 || in_array($w, $stop, true)) {
                continue;
            }
            $tokens[$w] = true;
        }
        return array_keys($tokens);
    }

    private function matches($token, $haystack) {
        if ($haystack === null || $haystack === '') {
            return false;
        }
        return (bool)preg_match('/\b' . preg_quote($token, '/') . '(?:s|es)?\b/i', $haystack);
    }
}