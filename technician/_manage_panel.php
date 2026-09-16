<?php
// Inline Manage options panel. Expects: $ticket, $tech_id, $conn, $deletions_remaining.
$mp_ticket_id = (int)$ticket['id'];

$mp_updates = $conn->query(
    "SELECT id, previous_status, new_status, notes, assigned_at
     FROM ticket_assignments
     WHERE ticket_id = $mp_ticket_id AND technician_id = $tech_id AND deleted_at IS NULL
     ORDER BY assigned_at DESC"
);

$mp_solutions = $conn->query(
    "SELECT id, solution, resolved_at
     FROM fault_history
     WHERE ticket_id = $mp_ticket_id AND resolved_by = $tech_id AND deleted_at IS NULL
     ORDER BY resolved_at DESC"
);

$mp_has_own = false;
?>
<div id="manage-<?php echo $mp_ticket_id; ?>" class="hidden mt-3" style="border: 1px solid rgba(0,255,136,0.15); border-radius: 10px; background: rgba(255,255,255,0.02);">
    <div style="padding: 0.75rem;">
        <p class="text-xs font-semibold text-white mb-2" style="display: flex; align-items: center; gap: 0.4rem;">
            <i data-lucide="edit" class="w-3 h-3 text-[#00ff88]"></i> Update Resolution / Status
        </p>
        <p class="text-[10px] mb-2" style="color: #f59e0b;">Current status: <span class="capitalize text-white"><?php echo str_replace('_', ' ', $ticket['status']); ?></span><?php echo $ticket['status'] !== 'resolved' ? ' - still in progress, not resolved yet' : ''; ?></p>
        <?php if ($ticket['status'] !== 'resolved'): ?>
        <form method="POST" action="" style="display: flex; flex-direction: column; gap: 0.5rem;">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="ticket_id" value="<?php echo $mp_ticket_id; ?>">
            <select name="new_status" style="background: #0f0f15; border: 1px solid #1e293b; color: #e2e8f0; border-radius: 6px; padding: 0.4rem 0.6rem; font-size: 0.75rem; width: 100%;">
                <option value="open" <?php echo $ticket['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="in_progress" <?php echo $ticket['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            </select>
            <textarea name="resolution" rows="2" style="background: #0f0f15; border: 1px solid #1e293b; color: #e2e8f0; border-radius: 6px; padding: 0.4rem 0.6rem; font-size: 0.75rem; width: 100%;" placeholder="Resolution / action taken (required)..." required></textarea>
            <button type="submit" class="cyber-btn w-full justify-center text-xs py-1">Save Update</button>
        </form>
        <?php else: ?>
            <p class="text-xs text-[#666]">Ticket is already resolved.</p>
        <?php endif; ?>

        <hr style="border-color: #1e293b; margin: 0.75rem 0;">

        <p class="text-xs font-semibold text-white mb-2" style="display: flex; align-items: center; gap: 0.4rem; justify-content: space-between;">
            <span style="display: flex; align-items: center; gap: 0.4rem;"><i data-lucide="trash-2" class="w-3 h-3 text-[#00ff88]"></i> Solutions / Status Updates</span>
            <span class="text-[10px] text-[#666]"><?php echo $deletions_remaining; ?>/2 deletes left today</span>
        </p>

        <?php if ($mp_solutions && $mp_solutions->num_rows > 0):
            while ($mp_s = $mp_solutions->fetch_assoc()): $mp_has_own = true; ?>
            <div style="border-bottom: 1px solid #1e293b; padding: 0.4rem 0;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.7rem; color: #cbd5e1;">
                    <button type="button" onclick="toggleSolutionExpand(<?php echo $mp_s['id']; ?>)" style="background: none; border: none; color: #00ff88; cursor: pointer; font-size: 0.7rem; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <span class="badge badge-resolved" style="margin-right: 0.3rem;">Solution</span>
                        <?php echo htmlspecialchars($mp_s['solution']); ?>
                    </button>
                    <form method="POST" action="" onsubmit="return confirm('Permanently delete this resolution? You have <?php echo $deletions_remaining; ?>/2 deletions left today.');">
                        <input type="hidden" name="action" value="delete_solution">
                        <input type="hidden" name="ticket_id" value="<?php echo $mp_ticket_id; ?>">
                        <input type="hidden" name="history_id" value="<?php echo $mp_s['id']; ?>">
                        <button type="submit" style="background: #7f1d1d; color: #fff; border: none; border-radius: 6px; padding: 0.25rem 0.6rem; font-size: 0.7rem; cursor: pointer;">Delete</button>
                    </form>
                </div>
                <div id="sol-view-<?php echo $mp_s['id']; ?>" class="hidden" style="margin-top: 0.5rem;">
                    <div style="background: #0f0f15; border: 1px solid #1e293b; border-radius: 6px; padding: 0.5rem 0.6rem; font-size: 0.75rem; color: #e2e8f0; white-space: pre-wrap;"><?php echo htmlspecialchars($mp_s['solution']); ?></div>
                    <button type="button" onclick="toggleSolutionEdit(<?php echo $mp_s['id']; ?>)" class="cyber-btn text-xs py-1" style="margin-top: 0.4rem;">Edit Solution</button>
                    <div id="sol-edit-<?php echo $mp_s['id']; ?>" class="hidden" style="margin-top: 0.4rem;">
                        <form method="POST" action="" style="display: flex; flex-direction: column; gap: 0.4rem;">
                            <input type="hidden" name="action" value="edit_solution">
                            <input type="hidden" name="ticket_id" value="<?php echo $mp_ticket_id; ?>">
                            <input type="hidden" name="history_id" value="<?php echo $mp_s['id']; ?>">
                            <textarea name="solution" rows="3" style="background: #0f0f15; border: 1px solid #1e293b; color: #e2e8f0; border-radius: 6px; padding: 0.4rem 0.6rem; font-size: 0.75rem; width: 100%;" required><?php echo htmlspecialchars($mp_s['solution']); ?></textarea>
                            <button type="submit" class="cyber-btn w-full justify-center text-xs py-1">Save Solution / Additional Info</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endwhile; endif; ?>

        <?php if ($mp_updates && $mp_updates->num_rows > 0):
            while ($mp_u = $mp_updates->fetch_assoc()): $mp_has_own = true; ?>
            <div style="border-bottom: 1px solid #1e293b; padding: 0.4rem 0;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.7rem; color: #cbd5e1;">
                    <button type="button" onclick="toggleUpdateView(<?php echo $mp_u['id']; ?>)" style="background: none; border: none; color: #00ff88; cursor: pointer; font-size: 0.7rem; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?php if ($mp_u['previous_status'] !== null): ?>
                            <?php echo ucfirst($mp_u['previous_status']); ?> &rarr; <?php echo ucfirst($mp_u['new_status']); ?>
                        <?php else: ?>
                            Claimed / Started ticket
                        <?php endif; ?>
                        <?php if ($mp_u['notes']): ?> &middot; <?php echo htmlspecialchars($mp_u['notes']); ?><?php endif; ?>
                    </button>
                    <form method="POST" action="" onsubmit="return confirm('Permanently delete this status update? You have <?php echo $deletions_remaining; ?>/2 deletions left today.');">
                        <input type="hidden" name="action" value="delete_status_update">
                        <input type="hidden" name="ticket_id" value="<?php echo $mp_ticket_id; ?>">
                        <input type="hidden" name="assignment_id" value="<?php echo $mp_u['id']; ?>">
                        <button type="submit" style="background: #7f1d1d; color: #fff; border: none; border-radius: 6px; padding: 0.25rem 0.6rem; font-size: 0.7rem; cursor: pointer;">Delete</button>
                    </form>
                </div>
                <div id="upd-view-<?php echo $mp_u['id']; ?>" class="hidden" style="margin-top: 0.5rem;">
                    <div style="background: #0f0f15; border: 1px solid #1e293b; border-radius: 6px; padding: 0.5rem 0.6rem; font-size: 0.75rem; color: #e2e8f0; white-space: pre-wrap;">
                        <strong>Status:</strong> <?php echo $mp_u['previous_status'] !== null ? ucfirst($mp_u['previous_status']) . ' &rarr; ' . ucfirst($mp_u['new_status']) : 'Ticket claimed / started'; ?>
                        <?php if ($mp_u['notes']): ?><br><strong>Resolution:</strong> <?php echo htmlspecialchars($mp_u['notes']); ?><?php else: ?>No resolution notes<?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endwhile; endif; ?>

        <?php if (!$mp_has_own): ?>
            <p class="text-xs text-[#666]">No status updates or resolutions created by you.</p>
        <?php endif; ?>
    </div>
</div>
<script>
    function toggleManage(id) {
        const panel = document.getElementById('manage-' + id);
        if (panel) panel.classList.toggle('hidden');
    }
    function toggleSolutionEdit(id) {
        const panel = document.getElementById('sol-edit-' + id);
        if (panel) panel.classList.toggle('hidden');
    }
    function toggleSolutionExpand(id) {
        const panel = document.getElementById('sol-view-' + id);
        if (panel) panel.classList.toggle('hidden');
    }
    function toggleUpdateView(id) {
        const panel = document.getElementById('upd-view-' + id);
        if (panel) panel.classList.toggle('hidden');
    }
</script>