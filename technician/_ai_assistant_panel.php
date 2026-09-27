<?php
/**
 * AI Help Assistant panel (reusable partial).
 *
 * Expected variables:
 *   $ai_ticket (array|null)  -> tickets row (id, title, description, category)
 *                               to pre-fill the panel context. Set to null on a
 *                               free-form page (e.g. the history page).
 *
 * Requires the 'ai_assistant.php' endpoint in the same directory, plus Tailwind
 * and Lucide icons. Styles are self-contained so the panel works on any page.
 *
 * Speed behaviour: retrieval answers locally in milliseconds. Draft generation
 * may call a language model, so the panel shows live elapsed time and always
 * renders a result - the server falls back to a locally composed triage plan
 * rather than returning an error when the model is unavailable.
 */
require_once '../config/auth_helper.php';

if (!isset($ai_ticket)) {
    $ai_ticket = null;
}
$ai_csrf   = generateCSRFToken();
$ai_suffix = substr(md5(uniqid('', true)), 0, 8);
$ai_problem = '';
if (is_array($ai_ticket)) {
    $ai_problem = trim(($ai_ticket['title'] ?? '') . ' - ' . ($ai_ticket['description'] ?? ''));
}
?>
<div class="cyber-card p-5" id="aiPanel-<?php echo $ai_suffix; ?>">
    <h3 class="text-sm font-semibold text-white mb-1 flex items-center gap-2">
        <i data-lucide="sparkles" class="w-4 h-4 text-[#a78bfa]"></i>
        AI Help Assistant
    </h3>
    <p class="text-[10px] text-[#666] mb-1">Similar past solutions &amp; draft resolution</p>
    <p class="text-[10px] text-[#555] mb-4 flex items-center gap-1.5">
        <span id="aiDot-<?php echo $ai_suffix; ?>" class="ai-dot"></span>
        <span id="aiEngine-<?php echo $ai_suffix; ?>">checking engine...</span>
    </p>

    <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2">Problem</label>
    <?php if (is_array($ai_ticket)): ?>
        <div class="text-xs text-[#888] bg-[#0f0f15] border border-[#1a1a2e] rounded-lg p-3 mb-3 max-h-28 overflow-y-auto whitespace-pre-wrap">
            <?php echo htmlspecialchars($ai_problem); ?>
        </div>
        <textarea id="aiProblem-<?php echo $ai_suffix; ?>" class="ai-textarea mb-3" rows="3" placeholder="Adjust the problem text if needed..."><?php echo htmlspecialchars($ai_problem); ?></textarea>
    <?php else: ?>
        <textarea id="aiProblem-<?php echo $ai_suffix; ?>" class="ai-textarea mb-3" rows="3" placeholder="Describe the problem to search similar past solutions..."></textarea>
    <?php endif; ?>

    <input type="hidden" id="aiCategory-<?php echo $ai_suffix; ?>" value="<?php echo htmlspecialchars(is_array($ai_ticket) ? ($ai_ticket['category'] ?? '') : ''); ?>">
    <input type="hidden" id="aiTicket-<?php echo $ai_suffix; ?>" value="<?php echo (int)(is_array($ai_ticket) ? ($ai_ticket['id'] ?? 0) : 0); ?>">

    <div class="flex gap-2 mb-3">
        <button type="button" class="ai-btn ai-btn-find"
                data-suffix="<?php echo $ai_suffix; ?>" data-label="Find Similar">
            <i data-lucide="search" class="w-3.5 h-3.5"></i> Find Similar
        </button>
        <button type="button" class="ai-btn ai-btn-generate"
                data-suffix="<?php echo $ai_suffix; ?>" data-label="Generate Draft">
            <i data-lucide="bot" class="w-3.5 h-3.5"></i> Generate Draft
        </button>
    </div>

    <div id="aiStatus-<?php echo $ai_suffix; ?>" class="ai-status hidden"></div>

    <div id="aiResults-<?php echo $ai_suffix; ?>" class="space-y-2"></div>

    <div id="aiDraftWrap-<?php echo $ai_suffix; ?>" class="hidden">
        <div class="flex items-center justify-between gap-2 mt-3 mb-2">
            <label class="block text-[10px] text-[#666] uppercase tracking-wider">Draft Resolution</label>
            <span id="aiSource-<?php echo $ai_suffix; ?>" class="ai-badge"></span>
        </div>
        <textarea id="aiDraft-<?php echo $ai_suffix; ?>" class="ai-textarea mb-2" rows="10"></textarea>
        <p id="aiNote-<?php echo $ai_suffix; ?>" class="text-[10px] text-[#666] mb-2"></p>
        <div class="flex gap-2">
            <button type="button" class="ai-btn ai-btn-outline ai-btn-copy"
                    data-suffix="<?php echo $ai_suffix; ?>" data-label="Copy to Clipboard">
                <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copy to Clipboard
            </button>
            <button type="button" class="ai-btn ai-btn-outline ai-btn-regen"
                    data-suffix="<?php echo $ai_suffix; ?>" data-label="Regenerate">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Regenerate
            </button>
        </div>
    </div>
</div>

<style>
    .ai-box { background: rgba(15,15,21,0.8); border: 1px solid #1a1a2e; border-radius: 8px; padding: 0.75rem; }
    .ai-textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(15, 15, 21, 0.8);
        border: 1px solid #1a1a2e;
        border-radius: 8px;
        color: #e0e0e0;
        font-size: 0.8rem;
        line-height: 1.5;
        resize: vertical;
        transition: border-color 0.2s;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .ai-textarea:focus { outline: none; border-color: #8b5cf6; }
    .ai-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        flex: 1;
        padding: 0.6rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        background: linear-gradient(135deg, #00ff88, #00cc6a);
        color: #050507;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .ai-btn:hover { box-shadow: 0 0 16px rgba(0, 255, 136, 0.25); }
    .ai-btn[disabled] { opacity: 0.55; cursor: not-allowed; }
    .ai-btn-generate { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #fff; }
    .ai-btn-generate:hover { box-shadow: 0 0 16px rgba(139, 92, 246, 0.35); }
    .ai-btn-outline { background: transparent; border: 1px solid #1a1a2e; color: #a78bfa; }
    .ai-btn-outline:hover { border-color: #8b5cf6; box-shadow: none; }
    .ai-status { padding: 0.6rem 0.75rem; border-radius: 8px; font-size: 0.75rem; margin-bottom: 0.75rem; }
    .ai-status.ai-success { background: rgba(0, 255, 136, 0.1); border: 1px solid rgba(0, 255, 136, 0.3); color: #00ff88; }
    .ai-status.ai-error   { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #ef4444; }
    .ai-status.ai-warn    { background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.35); color: #fbbf24; }
    .ai-badge {
        font-size: 9px; padding: 0.15rem 0.45rem; border-radius: 999px;
        text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap;
        background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.4); color: #c4b5fd;
    }
    .ai-badge.ai-badge-local { background: rgba(0,255,136,0.1); border-color: rgba(0,255,136,0.35); color: #00ff88; }
    .ai-dot { width: 6px; height: 6px; border-radius: 999px; background: #555; flex: none; }
    .ai-dot.ai-dot-on  { background: #00ff88; box-shadow: 0 0 6px rgba(0,255,136,0.8); }
    .ai-dot.ai-dot-off { background: #fbbf24; box-shadow: 0 0 6px rgba(251,191,36,0.6); }
    .ai-spin { display: inline-block; width: 11px; height: 11px; border-radius: 999px; flex: none;
        border: 2px solid rgba(255,255,255,0.25); border-top-color: currentColor; animation: ai-rot 0.7s linear infinite; }
    @keyframes ai-rot { to { transform: rotate(360deg); } }
    .ai-token { display:inline-block; font-size:9px; padding:0.05rem 0.3rem; margin:0.15rem 0.15rem 0 0;
        border-radius:4px; background:rgba(0,255,136,0.08); border:1px solid rgba(0,255,136,0.25); color:#00ff88; }
</style>

<script>
(function () {
    if (window.__aiAssistantBound) return;
    window.__aiAssistantBound = true;

    var CSRF = <?php echo json_encode($ai_csrf); ?>;

    function post(data, cb) {
        data.csrf_token = CSRF;
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch('ai_assistant.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body
        })
        .then(function (r) {
            return r.json().catch(function () {
                throw new Error('The assistant returned an unreadable response (HTTP ' + r.status + ').');
            });
        })
        .then(cb)
        .catch(function (err) {
            cb({ success: false, message: err.message || 'Network error. Could not reach the assistant.' });
        });
    }

    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function el(id) { return document.getElementById(id); }

    function setStatus(suffix, type, msg) {
        var e = el('aiStatus-' + suffix);
        if (!e) return;
        e.className = 'ai-status ai-' + type;
        e.textContent = msg;
    }

    function clearStatus(suffix) {
        var e = el('aiStatus-' + suffix);
        if (e) e.className = 'ai-status hidden';
    }

    function setBusy(btn, busy, busyLabel) {
        if (!btn) return;
        btn.disabled = busy;
        if (busy) {
            btn.innerHTML = '<span class="ai-spin"></span> ' + (busyLabel || 'Working...');
        } else {
            var label = btn.getAttribute('data-label');
            var icon = label === 'Find Similar' ? 'search'
                     : label === 'Regenerate' ? 'refresh-cw'
                     : label === 'Copy to Clipboard' ? 'copy' : 'bot';
            btn.innerHTML = '<i data-lucide="' + icon + '" class="w-3.5 h-3.5"></i> ' + label;
        }
        if (window.lucide) lucide.createIcons();
    }

    /**
     * Live elapsed-time counter so a slow model never looks like a hang.
     */
    function startTimer(suffix, msg) {
        var start = Date.now();
        setStatus(suffix, 'warn', msg);
        var tick = setInterval(function () {
            var s = Math.floor((Date.now() - start) / 1000);
            var note = s < 3
                ? ' (this is quick)'
                : ' (' + s + 's - still working)';
            setStatus(suffix, 'warn', msg + note);
        }, 1000);
        return function () { clearInterval(tick); };
    }

    function renderResults(suffix, results) {
        var box = el('aiResults-' + suffix);
        if (!box) return;
        box.innerHTML = '';
        if (!results || !results.length) {
            box.innerHTML = '<p class="text-xs text-[#666] text-center py-4">'
                + 'No similar past solutions found. Try rewording, or use Generate Draft.</p>';
            return;
        }
        var html = results.map(function (item) {
            var badge = '<span class="text-[10px] px-2 py-0.5 rounded-full capitalize" '
                + 'style="background: rgba(139,92,246,0.12); border:1px solid rgba(139,92,246,0.4); color:#c4b5fd;">'
                + esc(item.category || 'other') + '</span>';
            var source = item.source === 'past'
                ? 'Ticket #' + item.ticket_id + (item.tech ? ' &middot; ' + esc(item.tech) : '')
                : 'Knowledge Base';
            var tokens = '';
            if (item.matched && item.matched.length) {
                tokens = '<div>' + item.matched.map(function (m) {
                    return '<span class="ai-token">' + esc(m) + '</span>';
                }).join('') + '</div>';
            }
            return '<div class="ai-box">'
                + '<div class="flex items-center justify-between gap-2 mb-1">'
                +   '<p class="text-xs font-semibold text-white truncate">' + esc(item.title) + '</p>'
                +   badge
                + '</div>'
                + '<p class="text-[10px] text-[#666] mb-2">' + source + '</p>'
                + '<p class="text-xs text-[#aaa] whitespace-pre-wrap max-h-24 overflow-y-auto">' + esc(item.snippet) + '</p>'
                + tokens
                + '</div>';
        }).join('');
        box.innerHTML = html;
    }

    function renderDraft(suffix, res) {
        var wrap = el('aiDraftWrap-' + suffix);
        var ta   = el('aiDraft-' + suffix);
        var badge = el('aiSource-' + suffix);
        var note  = el('aiNote-' + suffix);
        if (!wrap || !ta) return;

        ta.value = res.draft || '';

        if (badge) {
            var local = res.source !== 'llm';
            badge.textContent = local ? 'From local history' : 'AI drafted';
            badge.className = 'ai-badge' + (local ? ' ai-badge-local' : '');
        }
        if (note) {
            var ms = res.elapsed_ms;
            var timing = (ms != null && ms >= 0) ? ' Took ' + ms + 'ms.' : '';
            note.textContent = (res.note || '') + timing;
        }

        wrap.classList.remove('hidden');
        ta.focus();
    }

    // ---- engine status -----------------------------------------------------

    document.querySelectorAll('[id^="aiEngine-"]').forEach(function (label) {
        var suffix = label.id.replace('aiEngine-', '');
        var dot = el('aiDot-' + suffix);
        post({ action: 'status' }, function (res) {
            if (!res.success || !res.status) {
                label.textContent = 'engine status unavailable';
                return;
            }
            var st = res.status;
            var rows = st.retrieval_rows || 0;
            if (st.llm_enabled) {
                label.textContent = st.model + ' (' + st.llm_kind + ') + ' + rows + ' past cases';
                if (dot) dot.className = 'ai-dot ai-dot-on';
            } else {
                label.textContent = 'Local mode - ' + rows + ' past cases indexed, no model called';
                if (dot) dot.className = 'ai-dot ai-dot-off';
            }
        });
    });

    // ---- find similar ------------------------------------------------------

    function onFind(btn) {
        var s = btn.getAttribute('data-suffix');
        var problem = el('aiProblem-' + s).value.trim();
        var category = el('aiCategory-' + s).value;
        var ticket = el('aiTicket-' + s).value;
        var resultsEl = el('aiResults-' + s);
        var draftWrap = el('aiDraftWrap-' + s);

        if (!problem) {
            setStatus(s, 'error', 'Please describe the problem first.');
            return;
        }

        setBusy(btn, true, 'Searching');
        setStatus(s, 'warn', 'Searching past resolutions and knowledge base...');
        resultsEl.innerHTML = '';
        if (draftWrap) draftWrap.classList.add('hidden');

        post({ action: 'similar', problem: problem, category: category, ticket_id: ticket },
            function (res) {
                setBusy(btn, false);
                if (!res.success) {
                    setStatus(s, 'error', res.message);
                    return;
                }
                renderResults(s, res.results);
                var n = (res.results || []).length;
                setStatus(s, 'success',
                    n + (n === 1 ? ' match' : ' matches') + ' in ' + res.elapsed_ms + 'ms');
            });
    }

    // ---- generate draft ----------------------------------------------------

    function generate(suffix, btn, force) {
        var problem = el('aiProblem-' + suffix).value.trim();
        var category = el('aiCategory-' + suffix).value;
        var ticket = parseInt(el('aiTicket-' + suffix).value, 10) || 0;

        if (!problem && !ticket) {
            setStatus(suffix, 'error', 'Please describe the problem first.');
            return;
        }

        setBusy(btn, true, 'Generating');
        var stop = startTimer(suffix, force
            ? 'Rebuilding the draft from scratch...'
            : 'Generating a draft...');

        var data = { action: 'generate', category: category, force: force ? 1 : 0 };
        if (ticket > 0) {
            data.ticket_id = ticket;
        } else {
            data.problem = problem;
        }

        post(data, function (res) {
            stop();
            setBusy(btn, false);
            if (!res.success) {
                setStatus(suffix, 'error', res.message);
                return;
            }
            clearStatus(suffix);
            renderDraft(suffix, res);
        });
    }

    document.querySelectorAll('.ai-btn-generate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            generate(btn.getAttribute('data-suffix'), btn, false);
        });
    });

    document.querySelectorAll('.ai-btn-regen').forEach(function (btn) {
        btn.addEventListener('click', function () {
            generate(btn.getAttribute('data-suffix'), btn, true);
        });
    });

    function onCopy(btn) {
        var s = btn.getAttribute('data-suffix');
        var ta = el('aiDraft-' + s);
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(ta.value).then(function () {
                setStatus(s, 'success', 'Copied to clipboard.');
            }).catch(function () {
                ta.select();
                setStatus(s, 'warn', 'Copy was blocked - the text is selected, press Ctrl+C.');
            });
        } else {
            ta.select();
            try {
                document.execCommand('copy');
                setStatus(s, 'success', 'Copied to clipboard.');
            } catch (e) {
                setStatus(s, 'warn', 'The text is selected, press Ctrl+C to copy.');
            }
        }
    }

    // Delegated so the panel still works when more than one copy is included
    // on the same page (the bound-script guard would otherwise leave the later
    // copies without listeners).
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.ai-btn') : null;
        if (!btn) return;
        if (btn.classList.contains('ai-btn-find')) {
            onFind(btn);
        } else if (btn.classList.contains('ai-btn-generate')) {
            generate(btn.getAttribute('data-suffix'), btn, false);
        } else if (btn.classList.contains('ai-btn-regen')) {
            generate(btn.getAttribute('data-suffix'), btn, true);
        } else if (btn.classList.contains('ai-btn-copy')) {
            onCopy(btn);
        }
    });
})();
</script>
