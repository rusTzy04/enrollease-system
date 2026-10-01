// ---------------------------------------------------------------
// Custom confirm modal + toast notifications (replaces native confirm()/alert())
// ---------------------------------------------------------------
function injectDialogUI() {
    if (document.getElementById('app-modal-overlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'app-modal-overlay';
    overlay.className = 'app-modal-overlay';
    overlay.innerHTML = `
        <div class="app-modal" role="alertdialog" aria-modal="true">
            <div class="app-modal-message" id="app-modal-message"></div>
            <div class="app-modal-actions">
                <button type="button" class="btn btn-outline btn-sm" id="app-modal-cancel">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="app-modal-confirm">Confirm</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const toastHost = document.createElement('div');
    toastHost.id = 'app-toast-host';
    toastHost.className = 'app-toast-host';
    document.body.appendChild(toastHost);
}

/** Promise-based replacement for confirm(). Resolves true/false. */
function appConfirm(message) {
    injectDialogUI();
    const overlay = document.getElementById('app-modal-overlay');
    const msgEl = document.getElementById('app-modal-message');
    const btnConfirm = document.getElementById('app-modal-confirm');
    const btnCancel = document.getElementById('app-modal-cancel');

    msgEl.textContent = message;
    overlay.classList.add('open');

    return new Promise((resolve) => {
        const cleanup = (result) => {
            overlay.classList.remove('open');
            btnConfirm.removeEventListener('click', onConfirm);
            btnCancel.removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onOverlay);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const onConfirm = () => cleanup(true);
        const onCancel = () => cleanup(false);
        const onOverlay = (e) => { if (e.target === overlay) cleanup(false); };
        const onKey = (e) => { if (e.key === 'Escape') cleanup(false); };

        btnConfirm.addEventListener('click', onConfirm);
        btnCancel.addEventListener('click', onCancel);
        overlay.addEventListener('click', onOverlay);
        document.addEventListener('keydown', onKey);
        btnConfirm.focus();
    });
}

/** Replacement for alert() — a small toast that disappears on its own. */
function appToast(message, type = 'info') {
    injectDialogUI();
    const host = document.getElementById('app-toast-host');
    const toast = document.createElement('div');
    toast.className = `app-toast app-toast-${type}`;
    toast.textContent = message;
    host.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 250);
    }, 3200);
}

// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // Auto-dismiss flash alerts after 5s
    document.querySelectorAll('.alert[data-autohide]').forEach(el => {
        setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 5000);
    });

    // Confirm-before-submit for destructive actions (custom modal, not native confirm())
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', async (e) => {
            e.preventDefault();
            const ok = await appConfirm(el.getAttribute('data-confirm'));
            if (ok) {
                const form = el.closest('form');
                if (form) {
                    if (form.requestSubmit) form.requestSubmit(el);
                    else form.submit();
                }
            }
        });
    });
});

/**
 * Client-side registration form validation.
 * NOTE: this is a UX convenience only — the server (auth/register.php) re-validates
 * everything and is the real security boundary, since client-side checks can be bypassed.
 */
function validateRegisterForm(form) {
    let valid = true;
    const setError = (id, msg) => {
        const el = form.querySelector(`#err-${id}`);
        if (el) el.textContent = msg || '';
        if (msg) valid = false;
    };

    const firstName = form.first_name.value.trim();
    const lastName = form.last_name.value.trim();
    const email = form.email.value.trim();
    const phone = form.phone.value.trim();
    const password = form.password.value;
    const confirm = form.confirm_password.value;

    setError('first_name', firstName ? '' : 'First name is required.');
    setError('last_name', lastName ? '' : 'Last name is required.');
    setError('email', /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? '' : 'Enter a valid email address.');
    setError('phone', /^0\d{10}$/.test(phone) ? '' : 'Enter a valid 11-digit mobile number (e.g. 09171234567).');
    setError('password',
        password.length >= 8 && /[A-Za-z]/.test(password) && /[0-9]/.test(password)
            ? '' : 'Password must be at least 8 characters and include a letter and a number.'
    );
    setError('confirm_password', password === confirm ? '' : 'Passwords do not match.');

    return valid;
}

/** Live running total for the subject/section selection page */
function recalcEnrollmentTotals() {
    const checked = document.querySelectorAll('.subject-checkbox:checked');
    let totalUnits = 0;
    checked.forEach(cb => totalUnits += parseFloat(cb.dataset.units || 0));

    const unitsEl = document.getElementById('running-units');
    const warnEl = document.getElementById('unit-warning');
    if (unitsEl) unitsEl.textContent = totalUnits.toFixed(1);

    const maxUnits = parseFloat(document.getElementById('max-units')?.dataset.max || 30);
    if (warnEl) {
        warnEl.style.display = totalUnits > maxUnits ? 'block' : 'none';
    }
    const submitBtn = document.getElementById('submit-enrollment-btn');
    if (submitBtn) submitBtn.disabled = totalUnits === 0 || totalUnits > maxUnits;
}

/**
 * Live validation for an 11-digit PH mobile number field, with an inline error message
 * that appears as the person types — no need to submit the form first to see it.
 * Usage: attachPhoneLiveValidation('phone', 'err-phone');
 */
function attachPhoneLiveValidation(inputId, errorId) {
    const input = document.getElementById(inputId);
    const errorEl = document.getElementById(errorId);
    if (!input || !errorEl) return;

    const setError = (message) => {
        errorEl.textContent = message;
        errorEl.classList.toggle('show', !!message);
    };

    const validate = () => {
        let digits = input.value.replace(/[^0-9]/g, ''); // strip anything typed/pasted that isn't a digit
        const exceeded = digits.length > 11;
        if (exceeded) digits = digits.slice(0, 11); // hard-cap at 11, matching maxlength
        input.value = digits;

        if (digits.length === 0) {
            setError('');
        } else if (exceeded) {
            setError('11 digits only. Please input a valid number.');
        } else if (digits.length === 11 && digits[0] !== '0') {
            setError('Mobile number must start with 0.');
        } else {
            setError(''); // still typing, valid so far — don't nag yet
        }
    };

    const validateOnBlur = () => {
        const digits = input.value.replace(/[^0-9]/g, '');
        if (digits.length > 0 && digits.length < 11) {
            setError('Please enter all 11 digits.');
        }
    };

    input.addEventListener('input', validate);
    input.addEventListener('paste', () => setTimeout(validate, 0));
    input.addEventListener('blur', validateOnBlur);
}