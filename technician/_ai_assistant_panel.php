<?php
/**
 * AI Help Assistant panel (reusable partial).
 *
 * Expected variables:
 *   $ai_ticket (array|null)  -> tickets row (id, title, description, category)
 *                               to pre-fill the panel context. Set to null on a
 *                               free-form page (e.g. history page).
 *
 * Requires: an AJAX endpoint at 'ai_assistant.php' (same directory),
 * Tailwind, Lucide icons, and 'ai_assistant.php' endpoint. Fully self-contained
 * styles, so it works on any page regardless of page-level CSS.
 */
if (!isset($ai_ticket)) {
    $ai_ticket = null;
}
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
    <p class="text-[10px] text-[#666] mb-4">Similar past solutions &amp; AI draft resolution</p>

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
        <label class="block text-[10px] text-[#666] uppercase tracking-wider mb-2 mt-1">AI Draft Resolution</label>
        <textarea id="aiDraft-<?php echo $ai_suffix; ?>" class="ai-textarea mb-2" rows="7"></textarea>
        <button type="button" class="ai-btn ai-btn-outline ai-btn-copy w-full"
                data-suffix="<?php echo $ai_suffix; ?>" data-label="Copy to Clipboard">
            <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copy to Clipboard
        </button>
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
        resize: vertical;
        transition: border-color 0.2s;
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
    }
    .ai-btn:hover { box-shadow: 0 0 16px rgba(0, 255, 136, 0.25); }
    .ai-btn[disabled] { opacity: 0.6; cursor: wait; }
    .ai-btn-generate { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #fff; }
    .ai-btn-generate:hover { box-shadow: 0 0 16px rgba(139, 92, 246, 0.35); }
    .ai-btn-outline { background: transparent; border: 1px solid #1a1a2e; color: #a78bfa; }
    .ai-btn-outline:hover { border-color: #8b5cf6; box-shadow: none; }
    .ai-status { padding: 0.6rem 0.75rem; border-radius: 8px; font-size: 0.75rem; margin-bottom: 0.75rem; }
    .ai-status.ai-success { background: rgba(0, 255, 136, 0.1); border: 1px solid rgba(0, 255, 136, 0.3); color: #00ff88; }
    .ai-status.ai-error   { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #ef4444; }
    .ai-status.ai-warn    { background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.35); color: #fbbf24; }
</style>

<script>
(function () {
    if (window.__aiAssistantBound) return;
    window.__aiAssistantBound = true;

    function post(data, cb) {
        fetch('ai_assistant.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data)
        })
        .then(function (r) { return r.json(); })
        .then(cb)
        .catch(function () {
            cb({ success: false, message: 'Network error. Could not reach the assistant.' });
        });
    }

    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function setStatus(el, type, msg) {
        el.classList.remove('hidden', 'ai-success', 'ai-error', 'ai-warn');
        el.className = 'ai-status ai-' + type;
        el.textContent = msg;
    }

    function setBusy(btn, busy) {
        if (!btn) return;
        btn.disabled = busy;
        if (busy) {
            btn.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5"></i> Working...';
        } else {
            var label = btn.getAttribute('data-label');
            var icon = label === 'Find Similar' ? 'search' : (label === 'Generate Draft' ? 'bot' : 'copy');
            btn.innerHTML = '<i data-lucide="' + icon + '" class="w-3.5 h-3.5"></i> ' + label;
        }
        if (window.lucide) lucide.createIcons();
    }

    function renderResults(container, results) {
        container.innerHTML = '';
        if (!results || !results.length) {
            container.innerHTML = '<p class="text-xs text-[#666] text-center py-4">No similar past solutions found. Try rewording, or use Generate Draft.</p>';
            return;
        }
        var html = results.map(function (item) {
            var badge = '<span class="text-[10px] px-2 py-0.5 rounded-full capitalize" '
                + 'style="background: rgba(139,92,246,0.12); border:1px solid rgba(139,92,246,0.4); color:#c4b5fd;">'
                + esc(item.category || 'other') + '</span>';
            var source = item.source === 'past'
                ? 'Ticket #' + item.ticket_id + (item.tech ? ' &middot; ' + esc(item.tech) : '')
                : 'Knowledge Base';
            return '<div class="ai-box">'
                + '<div class="flex items-center justify-between gap-2 mb-1">'
                +   '<p class="text-xs font-semibold text-white truncate">' + esc(item.title) + '</p>'
                +   badge
                + '</div>'
                + '<p class="text-[10px] text-[#666] mb-2">' + source + '</p>'
                + '<p class="text-xs text-[#aaa] whitespace-pre-wrap max-h-24 overflow-y-auto">' + esc(item.snippet) + '</p>'
                + '</div>';
        }).join('');
        container.innerHTML = html;
    }

    document.querySelectorAll('.ai-btn-find').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var s = btn.getAttribute('data-suffix');
            var problem = document.getElementById('aiProblem-' + s).value.trim();
            var category = document.getElementById('aiCategory-' + s).value;
            var ticket = document.getElementById('aiTicket-' + s).value;
            var statusEl = document.getElementById('aiStatus-' + s);
            var resultsEl = document.getElementById('aiResults-' + s);
            var draftWrap = document.getElementById('aiDraftWrap-' + s);

            if (!problem) {
                setStatus(statusEl, 'error', 'Please describe the problem first.');
                return;
            }
            setBusy(btn, true);
            setStatus(statusEl, 'warn', 'Searching past resolutions and knowledge base...');
            resultsEl.innerHTML = '';

            post({ action: 'similar', problem: problem, category: category, ticket_id: ticket }, function (res) {
                setBusy(btn, false);
                if (!res.success) {
                    setStatus(statusEl, 'error', res.message);
                    return;
                }
                statusEl.classList.add('hidden');
                renderResults(resultsEl, res.results);
                if (draftWrap && !draftWrap.classList.contains('hidden')) {
                    draftWrap.classList.add('hidden');
                }
            });
        });
    });

    document.querySelectorAll('.ai-btn-generate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var s = btn.getAttribute('data-suffix');
            var problem = document.getElementById('aiProblem-' + s).value.trim();
            var category = document.getElementById('aiCategory-' + s).value;
            var ticket = parseInt(document.getElementById('aiTicket-' + s).value, 10) || 0;
            var statusEl = document.getElementById('aiStatus-' + s);
            var draftWrap = document.getElementById('aiDraftWrap-' + s);
            var draftTa = document.getElementById('aiDraft-' + s);

            if (!problem && !ticket) {
                setStatus(statusEl, 'error', 'Please describe the problem first.');
                return;
            }
            setBusy(btn, true);
            setStatus(statusEl, 'warn', 'Contacting AI to generate a draft resolution... This can take a moment.');
            draftWrap.classList.add('hidden');

            var data = { action: 'generate', category: category };
            if (ticket > 0) {
                data.ticket_id = ticket;
            } else {
                data.problem = problem;
            }

            post(data, function (res) {
                setBusy(btn, false);
                if (!res.success) {
                    setStatus(statusEl, 'error', res.message);
                    return;
                }
                statusEl.classList.add('hidden');
                draftTa.value = res.draft || '';
                draftWrap.classList.remove('hidden');
                draftTa.focus();
            });
        });
    });

    document.querySelectorAll('.ai-btn-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var s = btn.getAttribute('data-suffix');
            var ta = document.getElementById('aiDraft-' + s);
            var statusEl = document.getElementById('aiStatus-' + s);
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(ta.value).then(function () {
                    setStatus(statusEl, 'success', 'Copied to clipboard.');
                });
            } else {
                ta.select();
                document.execCommand('copy');
                setStatus(statusEl, 'success', 'Copied to clipboard.');
            }
        });
    });
})();
</script>