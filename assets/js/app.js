$(function () {
    var debtForm = document.querySelector('.js-debt-form');
    if (debtForm) {
        var emailInput = debtForm.querySelector('.js-debt-email');
        var submitButtons = Array.prototype.slice.call(debtForm.querySelectorAll('.js-debt-submit'));
        var checkboxes = Array.prototype.slice.call(debtForm.querySelectorAll('.js-debt-checkbox'));
        var summary = debtForm.querySelector('.js-debt-summary');
        var paymentContainer = debtForm.querySelector('.payment-platforms');

        var formatCurrency = function (amount) {
            try {
                return new Intl.NumberFormat('es-CL', {
                    style: 'currency',
                    currency: 'CLP',
                    minimumFractionDigits: 0,
                }).format(amount);
            } catch (error) {
                return '$' + amount.toLocaleString('es-CL');
            }
        };

        var validateEmail = function (value) {
            return value !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        };

        var isPaymentMethodAvailable = function (element) {
            if (!element) {
                return true;
            }

            var attr = element.getAttribute('data-payment-available');
            if (!attr) {
                return true;
            }

            attr = attr.toLowerCase();

            return !(attr === 'false' || attr === '0');
        };

        // Habilita el botón sólo cuando hay selección + correo válido y actualiza el resumen.
        var updateState = function () {
            var selected = checkboxes.filter(function (checkbox) {
                return checkbox.checked;
            });

            var total = selected.reduce(function (sum, checkbox) {
                var amount = parseInt(checkbox.getAttribute('data-amount') || '0', 10);
                return sum + (isNaN(amount) ? 0 : amount);
            }, 0);

            var emailValue = (emailInput ? emailInput.value : '').trim();
            var emailValid = emailInput ? validateEmail(emailValue) : true;

            if (emailInput) {
                emailInput.classList.toggle('is-invalid', emailValue !== '' && !emailValid);
                emailInput.classList.toggle('is-valid', emailValid && emailValue !== '');
            }

            if (summary) {
                if (selected.length === 0) {
                    summary.textContent = 'Selecciona una o más deudas.';
                } else {
                    summary.textContent = selected.length + ' deuda(s) seleccionada(s) · Total: ' + formatCurrency(total);
                }
            }

            var canSubmit = selected.length > 0 && emailValid;

            var hasAvailableMethod = submitButtons.some(function (button) {
                return isPaymentMethodAvailable(button);
            });

            submitButtons.forEach(function (button) {
                var methodAvailable = isPaymentMethodAvailable(button);
                var shouldEnable = canSubmit && methodAvailable;
                button.disabled = !shouldEnable;
                button.setAttribute('aria-disabled', shouldEnable ? 'false' : 'true');
            });

            if (paymentContainer) {
                var containerEnabled = canSubmit && hasAvailableMethod;
                paymentContainer.classList.toggle('is-enabled', containerEnabled);
                paymentContainer.setAttribute('aria-disabled', containerEnabled ? 'false' : 'true');
            }
        };

        if (emailInput) {
            emailInput.addEventListener('input', updateState);
            emailInput.addEventListener('blur', updateState);
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', updateState);
        });

        updateState();
    }

    var $rutInput = $('.js-rut');
    var $rutForm = $('.js-rut-form');
    var $feedback = $('.js-rut-feedback');

    if (!$rutInput.length) {
        return;
    }

    var cleanRut = function (value) {
        return (value || '').replace(/[^0-9kK]/g, '').toUpperCase();
    };

    var computeDv = function (body) {
        var sum = 0;
        var multiplier = 2;

        for (var i = body.length - 1; i >= 0; i--) {
            sum += parseInt(body.charAt(i), 10) * multiplier;
            multiplier = multiplier === 7 ? 2 : multiplier + 1;
        }

        var remainder = 11 - (sum % 11);

        if (remainder === 11) {
            return '0';
        }

        if (remainder === 10) {
            return 'K';
        }

        return String(remainder);
    };

    var isValidRut = function (rut) {
        var clean = cleanRut(rut);

        if (clean.length < 2) {
            return false;
        }

        var body = clean.slice(0, -1);
        var dv = clean.slice(-1);

        if (!/^\d+$/.test(body)) {
            return false;
        }

        return computeDv(body) === dv;
    };

    var formatRut = function (rut) {
        var clean = cleanRut(rut);

        if (clean.length < 2) {
            return clean;
        }

        var body = clean.slice(0, -1);
        var dv = clean.slice(-1);

        var formattedBody = body.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return formattedBody + '-' + dv;
    };

    var updateValidity = function (isValid, hasValue) {
        if (!hasValue) {
            $rutInput.removeClass('is-invalid is-valid');
            $rutInput.attr('aria-invalid', 'false');
            $feedback.text('');
            return true;
        }

        if (isValid) {
            $rutInput.removeClass('is-invalid').addClass('is-valid');
            $rutInput.attr('aria-invalid', 'false');
            $feedback.text('');
        } else {
            $rutInput.removeClass('is-valid').addClass('is-invalid');
            $rutInput.attr('aria-invalid', 'true');
            $feedback.text('El R.U.T ingresado no es válido.');
        }

        return isValid;
    };

    $rutInput.on('input', function () {
        var current = $rutInput.val();
        var clean = cleanRut(current);

        if (current !== clean) {
            $rutInput.val(clean);
        }

        if (!clean.length) {
            updateValidity(true, false);
            return;
        }

        updateValidity(isValidRut(clean), true);
    });

    $rutInput.on('blur', function () {
        var clean = cleanRut($rutInput.val());

        if (!clean.length) {
            updateValidity(true, false);
            $rutInput.val('');
            return;
        }

        $rutInput.val(formatRut(clean));
        updateValidity(isValidRut(clean), true);
    });

    $rutForm.on('submit', function (event) {
        var clean = cleanRut($rutInput.val());

        if (!clean.length || !isValidRut(clean)) {
            event.preventDefault();
            updateValidity(false, clean.length > 0);
            $rutInput.focus();
            return;
        }

        var formatted = formatRut(clean);
        if (window.console && console.log) {
            console.log('Consulta de deudas – payload', {
                rutIngresado: $rutInput.val(),
                rutNormalizado: clean,
                rutFormateado: formatted
            });
        }
        $rutInput.val(formatted);
    });

    (function initialize() {
        var initial = cleanRut($rutInput.val());

        if (!initial.length) {
            updateValidity(true, false);
            $rutInput.val('');
            return;
        }

        $rutInput.val(formatRut(initial));
        updateValidity(isValidRut(initial), true);
    }());
});
