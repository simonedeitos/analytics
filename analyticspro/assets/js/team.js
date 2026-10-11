(function () {
    'use strict';

    const modal = document.getElementById('team-disable-modal');
    if (!modal) return;
    const recipient = document.getElementById('team-recipient');
    const confirm = document.getElementById('team-disable-confirm');
    let form = null;

    function updateMode() {
        const reassign = modal.querySelector('input[name="team_assignment_mode"]:checked').value === 'reassign';
        document.getElementById('team-recipient-field').hidden = !reassign;
        recipient.disabled = !reassign;
        confirm.disabled = !form || (reassign && !recipient.value);
    }

    modal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        form = button ? button.closest('[data-team-disable-form]') : null;
        if (!form) {
            event.preventDefault();
            return;
        }
        document.getElementById('team-disable-name').textContent = button.dataset.teamName;
        document.getElementById('team-disable-count').textContent = button.dataset.teamCount;
        document.getElementById('team-mode-keep').checked = true;
        recipient.value = '';
        let available = 0;
        Array.from(recipient.options).forEach(function (option) {
            const self = option.value === button.dataset.teamId;
            option.hidden = self;
            option.disabled = self;
            if (option.value && !self) available++;
        });
        document.getElementById('team-mode-reassign').disabled = available === 0;
        document.getElementById('team-recipient-empty').hidden = available > 0;
        updateMode();
    });
    modal.addEventListener('change', updateMode);
    modal.addEventListener('hidden.bs.modal', function () {
        form = null;
        confirm.disabled = true;
    });
    confirm.addEventListener('click', function () {
        if (!form || confirm.disabled) return;
        const mode = modal.querySelector('input[name="team_assignment_mode"]:checked').value;
        form.elements.assignment_mode.value = mode;
        form.elements.recipient_id.value = mode === 'reassign' ? recipient.value : '';
        confirm.disabled = true;
        form.requestSubmit();
    });
}());
