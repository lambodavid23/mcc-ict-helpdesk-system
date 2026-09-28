<?php
/**
 * AI Assistant Service
 * Smart ICT Helpdesk System - Mutare City Council
 *
 * Built to be fast and always useful, with or without a language model:
 *
 *  1. Local retrieval engine (always on, no API key, no network)
 *     Scored keyword search across past resolutions (fault_history) and
 *     Knowledge Base articles. Scoring uses one compiled regex per field
 *     instead of one regex per token, so a full corpus scan costs a few
 *     milliseconds rather than tens of thousands of preg_match calls.
 *
 *  2. LLM draft generation (optional, progressive enhancement)
 *     Sends the ticket plus its best matching past cases to any
 *     OpenAI-compatible chat-completions endpoint. It runs under a hard time
 *     budget. If the model is absent, slow or unreachable the service
 *     degrades to the local composer instead of surfacing an error.
 *
 *  3. Draft cache
 *     Generated drafts are cached per ticket so repeat calls are instant.
 *     Degrades silently if the cache table has not been created yet.
 *
 * Configure the LLM with AI_ENABLED, AI_ENDPOINT, AI_API_KEY, AI_MODEL,
 * AI_TIMEOUT and AI_MAX_TOKENS environment variables.
 */

require_once 'database.php';

class AIAssistantService {
    /** Bump when the local composer or prompt changes, to invalidate cache. */
    const ENGINE_VERSION = '2';

    /** Seconds a cached draft stays fresh. */
    const CACHE_TTL = 21600; // 6 hours

    /** Seconds an "AI unreachable" verdict is trusted before probing again. */
    const PROBE_COOLDOWN = 30;

    private $conn;
    private $cacheReady = null;
    private $corpus = null;
    private $preflight = null;

    // LLM configuration. findSimilarSolutions() never needs this; only
    // generateResolution() does.
    //
    // Defaults target a LOCAL Ollama installation:
    //   AI_ENDPOINT = http://localhost:11434/v1/chat/completions
    //   AI_MODEL    = llama3.2:3b
    //   AI_API_KEY  = ''            (no key needed for local models)
    //
    // Set AI_ENABLED=0 to skip the model entirely and use only local
    // retrieval, which is the fastest and most reliable mode.
    private $llm = [
        'enabled'        => true,
        'endpoint'       => 'http://localhost:11434/v1/chat/completions',
        'api_key'        => '',
        'model'          => 'llama3.2:3b',
        'connect_timeout'=> 3,
        'timeout'        => 20,
        'max_tokens'     => 450,
    ];

    // Keyword -> [human label, diagnostic steps]. Drives the local composer so
    // an offline draft is a real triage plan rather than a blank template.
    private $signals = [
        'wifi' => ['Wireless / Wi-Fi link problem', [
            'Confirm the SSID and the exact WPA2 passphrase the user is entering.',
            'Compare signal strength at the user desk against a known-good location.',
            'Check the AP is powered, in service, and not at a channel-saturation limit.',
            'Look for a rogue or competing AP on the same channel.',
        ]],
        'wireless' => ['Wireless / Wi-Fi link problem', [
            'Confirm the SSID and the exact WPA2 passphrase the user is entering.',
            'Compare signal strength at the user desk against a known-good location.',
            'Check the AP is powered, in service, and not at a channel-saturation limit.',
        ]],
        'lan' => ['Wired LAN / switch port problem', [
            'Confirm link and activity LEDs on the NIC and the switch port.',
            'Test the same port with a known-good cable and a known-good device.',
            'Verify the port is enabled and not in an error / disabled state on the switch.',
        ]],
        'cable' => ['Patch or drop cable fault', [
            'Inspect both terminations for bent pins, damage or dirt.',
            'Swap to a known-good patch lead and re-test link.',
            'Check the run length against the cable category limit.',
        ]],
        'switch' => ['Switch / port fault', [
            'Confirm the port state on the switch (enabled, correct VLAN, no errors).',
            'Check the switch uplink and whether other devices on the same switch are affected.',
            'Clear counters and watch for errors while the user reproduces the fault.',
        ]],
        'router' => ['Router / gateway fault', [
            'Confirm the default gateway responds to a ping from the client.',
            'Check for interface errors, high CPU or a saturated WAN link on the router.',
            'Verify DNS resolution on the gateway before blaming the client.',
        ]],
        'dns' => ['Name resolution failure', [
            'Resolve the failing hostname directly to confirm whether it is DNS.',
            'Test resolution against an external resolver to separate DNS from the site.',
            'Check record expiry and zone configuration for the affected name.',
        ]],
        'ip' => ['IP addressing / DHCP', [
            'Confirm the client received a valid address in the expected subnet.',
            'Check the DHCP scope for exhaustion and lease conflicts.',
            'Verify the gateway and DNS values handed out by DHCP.',
        ]],
        'vpn' => ['VPN tunnel / remote access', [
            'Confirm the tunnel establishes and note exactly where it stalls.',
            'Verify credentials are still valid and the account is not locked.',
            'Check the gateway and firewall rules allowing the VPN port.',
        ]],
        'slow' => ['Performance / saturation', [
            'Measure whether the slowdown is bandwidth, latency or a saturated host.',
            'Correlate the slow period with backups, patching or other scheduled jobs.',
            'Check for a single user or a whole subnet to scope the problem.',
        ]],
        'printer' => ['Printer fault', [
            'Confirm the printer has power, is online and has paper and toner.',
            'Clear any queued jobs on the device and on the workstation.',
            'Check the driver and queue on the print server if networked.',
        ]],
        'jam' => ['Printer paper jam', [
            'Clear the jam per the device access path and check for torn paper.',
            'Inspect the feed rollers and the paper tray for obstruction.',
            'Run the device head-clean cycle if the feed keeps failing.',
        ]],
        'monitor' => ['Display / monitor fault', [
            'Test the same PC on a second display to isolate monitor vs. PC.',
            'Check the cable, the input source and the OSD menu.',
            'Reseat the graphics output and verify the display is detected.',
        ]],
        'keyboard' => ['Input device fault', [
            'Test the keyboard on another machine and that machine on this port.',
            'Clean the key contacts and check for stuck or liquid-damaged keys.',
            'Confirm the port is delivering data at the USB or PS/2 layer.',
        ]],
        'mouse' => ['Input device fault', [
            'Test the pointing device on another machine to isolate the fault.',
            'Check the surface, wireless battery charge and the receiver connection.',
        ]],
        'laptop' => ['Endpoint hardware fault', [
            'Confirm the fault follows the device by testing a spare unit.',
            'Check power, battery health and whether the chassis is overheating.',
            'Collect a hardware diagnostic report for the asset record.',
        ]],
        'overheat' => ['Thermal shutdown', [
            'Check air vents and fans for dust blockage.',
            'Verify the device is on a hard surface and not blocking ventilation.',
            'Monitor temperatures under load to confirm the shutdown trigger.',
        ]],
        'hard drive' => ['Storage fault', [
            'Read the SMART / disk health data and check for reallocated sectors.',
            'Confirm free space and run a filesystem check.',
            'Back up the user data before any repair attempt.',
        ]],
        'battery' => ['Power / battery fault', [
            'Confirm the charger, its LED and the voltage being delivered.',
            'Check the battery charge percentage and whether it drops under load.',
            'Verify the battery is recognised in the firmware or BIOS.',
        ]],
        'virus' => ['Malware infection', [
            'Run a full anti-malware scan and quarantine anything detected.',
            'Check for autoruns, unknown startup items and scheduled tasks.',
            'Review recent downloads and email attachments with the user.',
        ]],
        'malware' => ['Malware infection', [
            'Run a full anti-malware scan and quarantine anything detected.',
            'Check for autoruns, unknown startup items and scheduled tasks.',
        ]],
        'crash' => ['Application or system crash', [
            'Note the exact error text, timing and which application was in use.',
            'Check Windows Event Viewer or the equivalent log for the faulting module.',
            'Test the affected application on a second user profile.',
        ]],
        'install' => ['Software installation failure', [
            'Capture the exact installer error and where it stops.',
            'Confirm the account has rights to install software.',
            'Verify free disk space and the installer source integrity.',
        ]],
        'update' => ['Update / patch failure', [
            'Check whether the pending update is blocking or has partially applied.',
            'Confirm the machine can reach the update source and the time service.',
            'Review pending restarts and any update that previously failed.',
        ]],
        'slow computer' => ['Endpoint performance', [
            'Measure startup time and check which processes dominate CPU and RAM.',
            'Check for autorunning third-party software and pending updates.',
            'Confirm disk health and free space.',
        ]],
        'office' => ['Office suite problem', [
            'Test the document in WordPad or a second profile to isolate Office.',
            'Check add-ins and disable them one at a time.',
            'Verify the licence activation state.',
        ]],
        'password' => ['Credential / authentication', [
            'Confirm the exact error message and whether the account is locked.',
            'Verify the account is enabled and the password has not expired.',
            'Attempt a reset through the standard account recovery path.',
        ]],
        'locked' => ['Account locked out', [
            'Confirm the lockout is at the directory level rather than the workstation.',
            'Check for repeated failed attempts and reset the account.',
            'Ask the user to wait out any lockout window and change the password.',
        ]],
        'login' => ['Sign-in failure', [
            'Determine whether the failure is at sign-in, after sign-in, or on the desktop.',
            'Test the same credentials on another machine to separate profile from account.',
            'Check the clock on the client, which breaks domain authentication.',
        ]],
        'permission' => ['Permissions / access denied', [
            'Confirm whether the denial is on a file share, a folder or an application.',
            'Compare the effective permissions against the parent folder.',
            'Check group membership and whether the change to rights has replicated.',
        ]],
    ];

    // Per-category base triage. Keeps the offline draft concrete.
    private $categoryGuides = [
        'network' => [
            'cause' => 'Client-side configuration, a faulty patch or drop cable, a switch port fault, or an upstream gateway/DNS issue.',
            'diagnose' => [
                'Establish the blast radius: one user, one device, or the whole department.',
                'Confirm the client has link and gets an address in the expected subnet.',
                'Check whether the gateway and DNS both respond from the client.',
            ],
            'fix' => 'Repair the identified layer, then confirm the service works from the user desk.',
            'escalate' => 'Escalate to the network team if the AP or switch is unreachable from other ports, or if replacing the cable does not restore link.',
        ],
        'hardware' => [
            'cause' => 'A failing peripheral, power supply, cable or the endpoint device itself.',
            'diagnose' => [
                'Swap in a known-good device to establish whether the fault travels.',
                'Confirm power is actually reaching the device, not just the wall socket.',
                'Note the asset tag and any physical damage before proceeding.',
            ],
            'fix' => 'Repair or replace the faulty part, test with the user, and update the asset record.',
            'escalate' => 'Raise a hardware replacement request if the fault follows the device across swaps.',
        ],
        'software' => [
            'cause' => 'A corrupt install, a conflicting add-in, a pending update, low disk space or malware.',
            'diagnose' => [
                'Capture the exact error text and whether it affects every user or one profile.',
                'Check pending updates, restarts and recently installed software.',
                'Test the application on a second profile or a second machine.',
            ],
            'fix' => 'Repair, update or reinstall the affected application and confirm with the user.',
            'escalate' => 'Escalate to the systems team if multiple users are affected or it survives a clean reinstall.',
        ],
        'login' => [
            'cause' => 'Wrong credentials, an expired or locked account, a profile corruption, or a domain time skew.',
            'diagnose' => [
                'Confirm the exact error message shown at sign-in.',
                'Test the same credentials on a second machine to separate account from profile.',
                'Check the client clock is synchronised with the time service.',
            ],
            'fix' => 'Reset the credentials or repair the profile, then confirm the user can sign in.',
            'escalate' => 'Escalate to the directory administrator if the account is locked at domain level or the profile will not load.',
        ],
    ];

    public function __construct() {
        $this->conn = (new Database())->getConnection();
        $this->applyEnvConfig();
    }

    private function applyEnvConfig() {
        $map = [
            'AI_ENABLED'         => 'enabled',
            'AI_ENDPOINT'        => 'endpoint',
            'AI_API_KEY'         => 'api_key',
            'AI_MODEL'           => 'model',
            'AI_TIMEOUT'         => 'timeout',
            'AI_CONNECT_TIMEOUT' => 'connect_timeout',
            'AI_MAX_TOKENS'      => 'max_tokens',
        ];
        foreach ($map as $env => $key) {
            $val = getenv($env);
            if ($val === false) {
                continue;
            }
            if ($key === 'enabled') {
                $this->llm['enabled'] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($key, ['timeout', 'connect_timeout', 'max_tokens'], true)) {
                $n = (int)$val;
                if ($n > 0) {
                    $this->llm[$key] = $n;
                }
            } else {
                $this->llm[$key] = trim($val);
            }
        }
        // Auto-enable when a key is configured and no explicit toggle was set.
        if (getenv('AI_ENABLED') === false && $this->llm['api_key'] !== '') {
            $this->llm['enabled'] = true;
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function isLlmEnabled() {
        return (bool)$this->llm['enabled'];
    }

    public function getLlmConfig() {
        return $this->llm;
    }

    /**
     * Safe capability report for the UI and for debugging configuration.
     * Never exposes the API key or the endpoint host.
     */
    public function getStatus() {
        $corpus = $this->loadCorpus();
        return [
            'llm_enabled'     => (bool)$this->llm['enabled'],
            'llm_kind'        => $this->isLocalEndpoint() ? 'local' : 'hosted',
            'model'           => $this->llm['model'],
            'has_api_key'     => $this->llm['api_key'] !== '',
            'timeout'         => (int)$this->llm['timeout'],
            'retrieval_rows'  => count($corpus['rows']),
            'past_cases'      => $corpus['past'],
            'kb_articles'     => $corpus['kb'],
            'cache_ready'     => $this->cacheAvailable(),
        ];
    }

    /**
     * Cheap reachability probe run before the cURL call.
     *
     * On Windows a connect to a closed local port returns WSAETIMEDOUT rather
     * than WSAECONNREFUSED, so cURL sits there for ~1.7s before reporting
     * anything. That is exactly the "nothing is happening" delay technicians
     * complained about. A fsockopen probe answers in milliseconds instead, and
     * a DNS lookup covers hosted endpoints.
     *
     * A negative verdict is cached for AI_PROBE_COOLDOWN seconds so only the
     * first request after a model is stopped pays the probe. When nothing is
     * listening this turns a ~1.8s stall into a sub-10ms response.
     *
     * @return string|null null when reachable, otherwise a short reason.
     */
    private function preflightLlm() {
        if ($this->preflight !== null) {
            return $this->preflight === '' ? null : $this->preflight;
        }

        $cacheFile = $this->probeCacheFile();
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $parts = explode('|', trim($cached), 2);
            if (isset($parts[1]) && (time() - (int)$parts[0]) < self::PROBE_COOLDOWN) {
                $this->preflight = $parts[1];
                return $this->preflight;
            }
        }

        $parts = parse_url($this->llm['endpoint']);
        $host  = $parts['host'] ?? 'localhost';
        $port  = isset($parts['port']) ? (int)$parts['port'] : (isset($parts['scheme']) && $parts['scheme'] === 'https' ? 443 : 80);
        $ip    = $host === 'localhost' ? '127.0.0.1' : $host;
        $why   = null;

        if ($this->isLocalEndpoint()) {
            $sock = @fsockopen($ip, $port, $errno, $errstr, $this->probeTimeout());
            if ($sock === false) {
                $why = 'nothing is listening on ' . $host . ':' . $port;
            } else {
                fclose($sock);
            }
        } elseif (gethostbyname($host) === $host) {
            $why = 'cannot resolve ' . $host;
        }

        @file_put_contents($cacheFile, time() . '|' . ($why !== null ? $why : 'ok'));
        $this->preflight = $why === null ? '' : $why;

        return $this->preflight === '' ? null : $this->preflight;
    }

    private function probeTimeout() {
        $v = getenv('AI_PROBE_TIMEOUT');
        $f = ($v === false) ? 0.25 : (float)$v;
        return ($f > 0 && $f <= 5) ? $f : 0.25;
    }

    private function probeCacheFile() {
        $dir = rtrim(sys_get_temp_dir(), '/\\');
        return $dir . DIRECTORY_SEPARATOR . 'mcc_ai_probe_' . md5($this->llm['endpoint']) . '.txt';
    }

    private function isLocalEndpoint() {
        $host = parse_url($this->llm['endpoint'], PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);
    }

    /**
     * Find the most similar past resolutions and knowledge base articles for a
     * given problem description. Pure local scoring - no network, no API key.
     *
     * @param string      $problem          Problem text to match against.
     * @param int         $limit            Max results to return.
     * @param string|null $category         Optional category; boosts exact matches.
     * @param int         $excludeTicketId  Ticket to exclude (the current one).
     * @return array
     */
    public function findSimilarSolutions($problem, $limit = 5, $category = null, $excludeTicketId = 0) {
        $started = microtime(true);
        $tokens = $this->tokenize($problem);
        if (!$tokens) {
            return [];
        }

        // One alternation regex per field, longest token first so the pattern
        // backtracks as little as possible. Replaces tokens x rows preg_match
        // calls with a single preg_match_all per row.
        $regex = $this->buildMatchRegex($tokens);

        $exclude = (int)$excludeTicketId;
        $category = ($category === null || $category === '') ? null : $category;

        $scored = [];
        foreach ($this->loadCorpus()['rows'] as $row) {
            if ($exclude > 0 && (int)$row['ticket_id'] === $exclude) {
                continue;
            }

            $score = 0;
            $matched = [];

            $strongHits = $this->countMatches($regex, $row['search_strong']);
            $weakHits   = $this->countMatches($regex, $row['search_weak']);
            if (!$strongHits && !$weakHits) {
                continue;
            }

            // Problem text and ticket titles are the strongest signal, the
            // recorded fix is weaker but still useful.
            $score += count($strongHits) * 3;
            $score += count($weakHits);
            $matched = array_slice(array_unique(array_merge($strongHits, $weakHits)), 0, 8);

            if ($category !== null && strcasecmp((string)$row['category'], $category) === 0) {
                $score += 4;
            }

            $row['score'] = $score;
            $row['matched'] = $matched;
            $scored[] = $row;
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] - $a['score'];
            }
            $at = (string)($a['resolved_at'] ?? '');
            $bt = (string)($b['resolved_at'] ?? '');
            return strcmp($bt, $at);
        });

        // Reject incidental matches. A score of 1-2 means a single token hit in
        // the weak field only (a solution body), which happens to share a common
        // word like "correct" or "error" with the query. Those are noise, not a
        // relevant past case. A score of 3 is one real hit in the strong field
        // (problem text or article keyword), or a strong category agreement.
        $MIN_SCORE = 3;
        $scored    = array_values(array_filter($scored, function ($r) use ($MIN_SCORE) {
            return (int)$r['score'] >= $MIN_SCORE;
        }));

        $clean = [];
        foreach (array_slice($scored, 0, max(1, (int)$limit)) as $r) {
            $clean[] = [
                'source'      => $r['source'],
                'ticket_id'   => (int)$r['ticket_id'],
                'title'       => $r['title'],
                'category'    => $r['category'],
                'snippet'     => $r['snippet'],
                'tech'        => $r['tech'],
                'resolved_at' => $r['resolved_at'],
                'score'       => (int)$r['score'],
                'matched'     => $r['matched'],
            ];
        }

        unset($started);
        return $clean;
    }

    /**
     * Produce a draft resolution for a problem/ticket.
     *
     * Resolution order:
     *   1. fresh cache hit                     -> instant
     *   2. LLM within the time budget          -> best quality
     *   3. locally composed triage plan        -> instant, always available
     *
     * The function never returns success:false for an unavailable model - it
     * degrades to the local composer and flags the result as degraded.
     *
     * @param string     $problem   Problem text (title + description).
     * @param string     $category  Ticket category, for context.
     * @param array|null $ticket    Optional tickets row.
     * @param bool       $force     Bypass the cache.
     * @return array
     */
    public function generateResolution($problem, $category = '', $ticket = null, $force = false) {
        $started  = microtime(true);
        $ticketId = isset($ticket['id']) ? (int)$ticket['id'] : 0;
        $problem  = trim((string)$problem);
        $category = trim((string)$category);

        $cacheKey = sha1(self::ENGINE_VERSION . '|' . $this->llm['model'] . '|'
            . $this->llm['enabled'] . '|' . $ticketId . '|' . $category . '|'
            . strtolower(preg_replace('/\s+/', ' ', $problem)));

        if (!$force) {
            $hit = $this->cacheGet($cacheKey);
            if ($hit !== null) {
                $hit['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
                $hit['cached'] = true;
                return $hit;
            }
        }

        $similar = $this->findSimilarSolutions($problem, 5, $category, $ticketId);
        $result  = null;

        if ($this->llm['enabled']) {
            $unreachable = $this->preflightLlm();
            if ($unreachable !== null) {
                $result = $this->composeLocalDraft($ticket, $problem, $category, $similar);
                $result['degraded'] = true;
                $result['note'] = 'No AI model is reachable (' . $unreachable . '), so this triage plan was '
                    . 'built from your local history instead.';
            } else {
                $llm = $this->requestLlm($problem, $category, $ticket, $similar);
                if ($llm['success']) {
                    $result = [
                        'success'   => true,
                        'draft'     => $llm['draft'],
                        'source'    => 'llm',
                        'degraded'  => false,
                        'note'      => 'Drafted by ' . $this->llm['model'] . '. Verify every step before applying it.',
                    ];
                } else {
                    $result = $this->composeLocalDraft($ticket, $problem, $category, $similar);
                    $result['degraded'] = true;
                    $result['note'] = 'The AI model did not respond in time, so this triage plan was built '
                        . 'from your local history instead. (' . $llm['message'] . ')';
                }
            }
        } else {
            $result = $this->composeLocalDraft($ticket, $problem, $category, $similar);
            $result['note'] = 'Built from your local history and the knowledge base. '
                . 'No AI model was called.';
        }

        $result['cached'] = false;
        $result['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
        $result['similar'] = $similar;

        $this->cacheSet($cacheKey, $ticketId, $category, $result);

        return $result;
    }

    /**
     * Call the configured chat-completions endpoint under a hard time budget.
     */
    private function requestLlm($problem, $category, $ticket, $similar) {
        $context = $this->buildContext($problem, $category, $ticket, $similar);
        $guide   = $this->guideFor($category);
        $signals = $this->detectSignals($problem, $category);
        $hints   = $this->hintLines($guide, $signals);

        $system = "You are a senior ICT helpdesk support assistant for Mutare City Council.\n"
            . "Given a ticket and similar past cases, write a short, practical resolution a technician can follow.\n"
            . "Use exactly these headings:\n"
            . "1. Likely cause(s)\n2. Steps to diagnose\n3. Steps to fix\n"
            . "Rules: plain text, no markdown, no tables, no greeting, no closing summary. "
            . "Short bullet points. Never invent ticket numbers. "
            . "If the past cases conflict, trust the most recent one.";

        if ($hints !== '') {
            $system .= "\nVerified local guidance for this category, use it and do not contradict it:\n" . $hints;
        }

        $payload = [
            'model'       => $this->llm['model'],
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $context],
            ],
            'temperature' => 0.2,
            'max_tokens'  => (int)$this->llm['max_tokens'],
            'stream'      => false,
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
            CURLOPT_CONNECTTIMEOUT => (int)$this->llm['connect_timeout'],
            CURLOPT_TIMEOUT        => (int)$this->llm['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '') {
            $msg = stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false
                ? 'timed out after ' . (int)$this->llm['timeout'] . 's'
                : $err;
            return ['success' => false, 'message' => 'AI service ' . $msg];
        }
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'AI service returned HTTP ' . $code];
        }

        $data  = json_decode($body, true);
        $draft = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($draft) || trim($draft) === '') {
            return ['success' => false, 'message' => 'AI service returned an empty reply'];
        }

        return ['success' => true, 'draft' => trim($draft)];
    }

    private function buildContext($problem, $category, $ticket, $similar) {
        $lines = [];
        if ($ticket && !empty($ticket['title'])) {
            $lines[] = 'Ticket #' . (int)$ticket['id'] . ' - ' . $ticket['title'];
            $lines[] = 'Category: ' . $ticket['category'] . '   Priority: ' . $ticket['priority'];
            $lines[] = 'Reported by: ' . ($ticket['created_by_name'] ?? 'a staff member');
            $lines[] = 'Description: ' . $ticket['description'];
        } else {
            $lines[] = 'Problem: ' . $problem;
            $lines[] = 'Category: ' . ($category !== '' ? $category : 'unspecified');
        }

        $lines[] = '';
        $lines[] = 'Similar past cases (reference only - verify, do not copy blindly):';
        if (!$similar) {
            $lines[] = '(none on file)';
        }
        foreach ($similar as $s) {
            $ref = $s['source'] === 'past'
                ? 'Ticket #' . $s['ticket_id']
                : 'Knowledge base';
            $lines[] = '- ' . $ref . ' [' . $s['category'] . '] ' . $s['title'] . ' -> ' . $s['snippet'];
        }

        return implode("\n", $lines);
    }

    private function guideFor($category) {
        $key = strtolower(trim((string)$category));
        if (isset($this->categoryGuides[$key])) {
            return $this->categoryGuides[$key];
        }
        return [
            'cause'    => 'An unknown number of causes, most likely something recently changed.',
            'diagnose' => [
                'Establish when the problem started and what changed just before it.',
                'Narrow the scope: one user, one device, or everyone affected.',
            ],
            'fix'      => 'Apply the standard fix for the diagnosed cause and confirm with the user.',
            'escalate' => 'Escalate if the fault is not resolved after the standard checks.',
        ];
    }

    private function hintLines($guide, $signals) {
        $lines = [];
        foreach ($signals as $sig) {
            $lines[] = '- ' . $sig['label'] . ': ' . $sig['checks'][0];
        }
        if ($lines === []) {
            return '';
        }
        return implode("\n", $lines);
    }

    /**
     * Match the problem text against the known signal vocabulary. Longest
     * keyword first so a specific phrase wins over a shorter one it contains,
     * and duplicate labels are collapsed.
     */
    private function detectSignals($text, $category) {
        $hay   = strtolower((string)$text);
        $found = [];
        $seen = [];

        $keys = array_keys($this->signals);
        usort($keys, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        // Words that appear in almost any helpdesk ticket, whatever the fault.
        // "office" in "the office WiFi" says nothing about an Office-suite
        // problem, so these never trigger a signal on their own.
        $ambiguous = array_flip([
            'office', 'install', 'update', 'crash', 'permission', 'login', 'ip', 'lan',
        ]);

        foreach ($keys as $key) {
            $s = $this->signals[$key];
            if (isset($seen[$s[0]]) || isset($ambiguous[$key])) {
                continue;
            }
            // Match on whole words only. A bare substring test fires on
            // "office" inside "the office WiFi in Registry", which then adds
            // the Office-suite checklist to a wireless fault.
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($key, '/') . '(?![\p{L}\p{N}])/u';
            if (!preg_match($pattern, $hay)) {
                continue;
            }
            $seen[$s[0]] = true;
            $found[] = ['label' => $s[0], 'checks' => $s[1]];
        }

        if (!$found && $category !== '') {
            $guide = $this->guideFor($category);
            $found[] = ['label' => ucfirst($category) . ' issue', 'checks' => $guide['diagnose']];
        }

        return array_slice($found, 0, 4);
    }

    /**
     * Compose a structured triage plan from local history. This is the
     * always-available floor: it runs in milliseconds and needs nothing but
     * the database.
     */
    private function composeLocalDraft($ticket, $problem, $category, $similar) {
        $started = microtime(true);
        $guide   = $this->guideFor($category);
        $signals = $this->detectSignals($problem, $category);
        $top     = array_slice($similar, 0, 3);

        $L = [];
        $L[] = 'TICKET';
        if ($ticket && !empty($ticket['title'])) {
            $L[] = '#' . (int)$ticket['id'] . '  ' . $ticket['title'];
            $L[] = 'Category: ' . $ticket['category'] . '   Priority: ' . $ticket['priority']
                 . '   Reported by: ' . ($ticket['created_by_name'] ?? 'staff');
        } else {
            $L[] = $problem !== '' ? $problem : '(no problem text supplied)';
        }

        $L[] = '';
        $L[] = '1. LIKELY CAUSE(S)';
        if ($signals) {
            foreach ($signals as $s) {
                $L[] = '- ' . $s['label'];
            }
        } else {
            $L[] = '- ' . $guide['cause'];
        }
        foreach ($top as $s) {
            $ref = $s['source'] === 'past' ? 'closed in Ticket #' . $s['ticket_id'] : 'knowledge base';
            $L[] = '- Recurring: ' . $s['title'] . '  [' . $s['category'] . ', ' . $ref . ']';
        }

        $L[] = '';
        $L[] = '2. STEPS TO DIAGNOSE';
        // The signal fallback reuses the category checklist, so dedupe or the
        // same step gets listed twice.
        $steps = $this->dedupe(array_merge(
            $guide['diagnose'],
            $this->signalChecks($signals)
        ));
        $n = 0;
        foreach (array_slice($steps, 0, 9) as $step) {
            $L[] = ($n++ + 1) . ') ' . $step;
        }
        foreach ($top as $s) {
            $ref = $s['source'] === 'past' ? 'Ticket #' . $s['ticket_id'] : 'knowledge base';
            $L[] = ($n++ + 1) . ') Compare against the ' . $ref . ' entry: ' . $s['title']
                 . '  [matched on: ' . implode(', ', $s['matched']) . ']';
        }

        $L[] = '';
        $L[] = '3. STEPS TO FIX';
        if ($top) {
            $L[] = '- Worked example from ' . ($top[0]['source'] === 'past' ? 'Ticket #' . $top[0]['ticket_id'] : 'the knowledge base') . ':';
            foreach ($this->asBullets($top[0]['snippet']) as $b) {
                $L[] = '    ' . $b;
            }
        } else {
            $L[] = '- ' . $guide['fix'];
        }
        foreach (array_slice($top, 1, 2) as $s) {
            $L[] = '- Alternative worth checking:';
            foreach ($this->asBullets($s['snippet']) as $b) {
                $L[] = '    ' . $b;
            }
        }
        $L[] = '- Confirm the fix with the user before closing the ticket.';
        $L[] = '- Record the resolution on this ticket so future matches improve.';

        $L[] = '';
        $L[] = 'IF THIS DOES NOT WORK';
        $L[] = '- ' . $guide['escalate'];

        $confidence = $this->confidence($similar, $signals);

        $L[] = '';
        $L[] = str_repeat('-', 58);
        $L[] = 'Confidence: ' . $confidence['label'] . '  |  based on ' . $confidence['basis'];
        $L[] = 'Generated locally in ' . (int)round((microtime(true) - $started) * 1000) . 'ms. '
             . 'Review and adapt before applying.';

        return [
            'success'    => true,
            'draft'      => implode("\n", $L),
            'source'     => 'local',
            'degraded'   => false,
            'confidence' => $confidence['label'],
        ];
    }

    /** Flatten signal checks into one list, at most 2 per signal. */
    private function signalChecks($signals) {
        $out = [];
        foreach ($signals as $s) {
            foreach (array_slice($s['checks'], 0, 2) as $c) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Case-insensitive dedupe that preserves order. */
    private function dedupe($items) {
        $seen = [];
        $out  = [];
        foreach ($items as $i) {
            $k = strtolower(trim($i));
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $i;
        }
        return $out;
    }

    private function confidence($similar, $signals) {
        $top = $similar[0]['score'] ?? 0;
        if ($top >= 12 && count($similar) >= 3) {
            $label = 'HIGH';
            $basis = count($similar) . ' close past cases';
        } elseif ($top >= 5 || $signals) {
            $label = 'MEDIUM';
            $basis = $signals ? 'recognised problem pattern' : 'one partial past case';
        } else {
            $label = 'LOW';
            $basis = 'no strong past match - rely on the standard checks';
        }
        return ['label' => $label, 'basis' => $basis];
    }

    /**
     * Turn a stored resolution into clean bullet lines.
     *
     * Recorded solutions frequently arrive as markdown containing literal
     * "\r\n" escape sequences and backslash-escaped quotes, so restore real
     * line breaks first, strip the markdown, then split. Doing it the other
     * way round collapses the whole solution onto a single line.
     */
    private function asBullets($text) {
        $text = (string)$text;

        // Literal escape sequences stored as text -> real whitespace.
        $text = str_replace(['\\r\\n', '\\n', '\\r', '\\t'], ["\n", "\n", "\n", ' '], $text);
        $text = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $text);

        // Markdown.
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = preg_replace('/`([^`]*)`/', '$1', $text);
        $text = str_replace('*', '', $text);

        $parts = preg_split('/\n+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out   = [];
        foreach ($parts as $p) {
            // Drop list bullets and bare numbering like "1." or "Step 3:".
            $p = preg_replace('/^\s*[-*•]\s*/', '', $p);
            $p = preg_replace('/^\s*(?:step\s*)?\d+[.):]?\s*/i', '', $p);
            $p = trim($p, " \t-.\r\n");
            if ($p === '') {
                continue;
            }
            // A heading with nothing under it is not a step.
            if (stripos($p, 'resolution for') === 0 || stripos($p, 'steps to') === 0) {
                continue;
            }
            // Knowledge base entries and free-text resolutions are often one
            // prose paragraph rather than a list. Split a long run of sentences
            // so each becomes its own actionable step instead of one wall of
            // text. Short fragments are left alone so genuine list items that
            // happen to contain a full stop are not chopped mid-sentence.
            if (substr_count($p, '. ') >= 1 && strlen($p) > 90) {
                $sentences = preg_split('/(?<=\.)\s+(?=[A-Z])/', $p, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($sentences as $s) {
                    $s = trim($s, " \t-.\r\n");
                    if ($s !== '') {
                        $out[] = '- ' . $s;
                    }
                }
            } else {
                $out[] = '- ' . $p;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        $out = array_slice($out, 0, 5);

        return $out ?: ['- ' . trim(preg_replace('/\s+/', ' ', $text))];
    }

    /**
     * Load the searchable corpus once per request instance.
     */
    private function loadCorpus() {
        if ($this->corpus !== null) {
            return $this->corpus;
        }

        $rows  = [];
        $past  = 0;
        $kb    = 0;

        $history = @$this->conn->query(
            "SELECT fh.ticket_id, fh.problem, fh.solution, fh.resolved_at,
                    t.title, t.category, tech.name AS tech
             FROM fault_history fh
             JOIN tickets t ON fh.ticket_id = t.id
             LEFT JOIN technicians tech ON fh.resolved_by = tech.id
             WHERE fh.deleted_at IS NULL
               AND fh.solution IS NOT NULL AND fh.solution <> ''
             ORDER BY fh.resolved_at DESC
             LIMIT 400"
        );
        if ($history) {
            while ($h = $history->fetch_assoc()) {
                $past++;
                $rows[] = [
                    'source'        => 'past',
                    'ticket_id'     => (int)$h['ticket_id'],
                    'category'      => $h['category'],
                    'title'         => $h['title'],
                    'snippet'       => $h['solution'],
                    'tech'          => $h['tech'],
                    'resolved_at'   => $h['resolved_at'],
                    // Title is a strong signal and was previously never scored.
                    'search_strong' => $this->flatten($h['problem'] . ' ' . $h['title']),
                    'search_weak'   => $this->flatten($h['solution']),
                ];
            }
            $history->free();
        }

        $kbRows = @$this->conn->query(
            "SELECT id, issue_keyword, recommended_solution, category
             FROM knowledge_base"
        );
        if ($kbRows) {
            while ($k = $kbRows->fetch_assoc()) {
                $kb++;
                $rows[] = [
                    'source'        => 'kb',
                    'ticket_id'     => 0,
                    'category'      => $k['category'],
                    'title'         => $k['issue_keyword'],
                    'snippet'       => $k['recommended_solution'],
                    'tech'          => null,
                    'resolved_at'   => null,
                    'search_strong' => $this->flatten($k['issue_keyword']),
                    'search_weak'   => $this->flatten($k['recommended_solution']),
                ];
            }
            $kbRows->free();
        }

        $this->corpus = ['rows' => $rows, 'past' => $past, 'kb' => $kb];
        return $this->corpus;
    }

    private function flatten($text) {
        return strtolower(preg_replace('/\s+/', ' ', trim((string)$text)));
    }

    /**
     * Build one alternation regex covering every token. Longest first so the
     * engine prefers the more specific match.
     */
    private function buildMatchRegex($tokens) {
        $tokens = array_slice($tokens, 0, 40);
        usort($tokens, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $parts = array_map(function ($t) {
            return preg_quote($t, '/');
        }, $tokens);
        return '/\b(' . implode('|', $parts) . ')(?:e?s)?\b/i';
    }

    /**
     * Return the distinct tokens matched in $text, or an empty array.
     */
    private function countMatches($regex, $text) {
        if ($text === null || $text === '') {
            return [];
        }
        if (!preg_match_all($regex, $text, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }

    private function tokenize($text) {
        $stop = [
            'the','a','an','and','or','but','for','with','without','from','into','onto','this','that',
            'these','those','my','your','our','their','his','her','its','i','we','you','me','us','to',
            'is','are','was','were','be','been','being','not','no','do','does','did','have','has','had',
            'get','got','can','cant','cannot','should','would','will','please','help','need','kindly',
            'when','how','what','why','where','who','it','there','then','than','of','at','on','in','by',
            'also','just','like','every','some','any','much','many','after','before','because','user',
            'users','please','unable','cannot','issue','problem','complain','complaint',
            // Vague wording that appears in almost every ticket and article.
            // Left in, a single hit on one of these outranks or pollutes a
            // genuine match on the actual fault.
            'correct','wrong','right','good','bad','error','errors','failed','fails','fail',
            'thing','things','staff','member','employee','computer','machine','device','system',
            'working','work','works','network','office','floor','since','still','keep','keeps',
            'trying','try','close','check','checking','see','saw','new','one','two','way',
            // Present in so many network article titles that they match each
            // other rather than the reported fault.
            'access','point','remote','join','dropping',
        ];
        $words = preg_split('/[^a-z0-9]+/', strtolower((string)$text), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($words as $w) {
            if (strlen($w) < 3 || isset($stop[$w]) || in_array($w, $stop, true)) {
                continue;
            }
            $tokens[$w] = true;
        }
        return array_keys($tokens);
    }

    // ---------------------------------------------------------------- cache

    private function cacheAvailable() {
        if ($this->cacheReady !== null) {
            return $this->cacheReady;
        }
        $this->cacheReady = false;
        try {
            $r = @$this->conn->query("SHOW TABLES LIKE 'ai_draft_cache'");
            if ($r && $r->num_rows > 0) {
                $this->cacheReady = true;
                $r->free();
            }
        } catch (\Throwable $e) {
            $this->cacheReady = false;
        }
        return $this->cacheReady;
    }

    private function cacheGet($key) {
        if (!$this->cacheAvailable()) {
            return null;
        }
        try {
            $r = @$this->conn->query(
                "SELECT draft, source, created_at
                 FROM ai_draft_cache
                 WHERE cache_key = '" . $this->conn->real_escape_string($key) . "'
                   AND created_at > (NOW() - INTERVAL " . (int)self::CACHE_TTL . " SECOND)
                 LIMIT 1"
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!$r || !($row = $r->fetch_assoc())) {
            return null;
        }
        $r->free();
        return [
            'success'   => true,
            'draft'     => $row['draft'],
            'source'    => $row['source'],
            'degraded'  => false,
            'note'      => 'Reused a recent draft for this ticket (' . $row['created_at'] . '). '
                         . 'Use Regenerate to rebuild it.',
            'confidence'=> 'cached',
        ];
    }

    private function cacheSet($key, $ticketId, $category, $result) {
        if (!$this->cacheAvailable() || empty($result['draft'])) {
            return;
        }
        try {
            $draft = $this->conn->real_escape_string($result['draft']);
            $note  = $this->conn->real_escape_string(isset($result['note']) ? $result['note'] : '');
            @$this->conn->query(
                "INSERT INTO ai_draft_cache
                    (cache_key, ticket_id, category, draft, source, note)
                 VALUES ('" . $this->conn->real_escape_string($key) . "',
                         " . (int)$ticketId . ",
                         '" . $this->conn->real_escape_string((string)$category) . "',
                         '" . $draft . "',
                         '" . $this->conn->real_escape_string((string)$result['source']) . "',
                         '" . $note . "')
                 ON DUPLICATE KEY UPDATE
                    draft = VALUES(draft),
                    source = VALUES(source),
                    note = VALUES(note),
                    created_at = NOW()"
            );
        } catch (\Throwable $e) {
            // Caching is best-effort only.
        }
    }
}
