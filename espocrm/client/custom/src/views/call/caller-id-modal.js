define('custom:views/call/caller-id-modal', [], function () {
    /**
     * Shared business-phone-number verification popup, used from both the
     * tenant dashboard banner and the Contact call-button gate. Twilio's
     * verification is call-based (no SMS option on this specific Twilio
     * resource) - it places an automated call immediately and reads out a
     * code the admin enters on their keypad, so this popup's job is mostly
     * just narrating that and then re-checking Twilio once they're done.
     */
    return class CallerIdVerifyModal {
        /** @param {{onVerified?: (callerNumber: string) => void}} handlers */
        constructor(handlers = {}) {
            this.handlers = handlers;
        }

        open() {
            this.close();
            const overlay = document.createElement('div');
            overlay.className = 'nexa-interaction-overlay nexa-caller-id-overlay';
            overlay.innerHTML = `
                <section class="nexa-interaction-dialog nexa-caller-id-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-caller-id-title">
                    <header>
                        <div><p>Voice calling</p><h2 id="nexa-caller-id-title">Verify your business phone number</h2></div>
                        <button type="button" class="nexa-dialog-close" data-nexa-caller-id-close aria-label="Close">
                            <span class="fas fa-times" aria-hidden="true"></span>
                        </button>
                    </header>
                    <div class="nexa-caller-id-body" data-nexa-caller-id-body>
                        <p>Enter the phone number your team calls customers from. This becomes the caller ID your customers see.</p>
                        <form data-nexa-caller-id-form>
                            <label><span>Business phone number</span>
                                <input class="form-control" type="tel" name="phoneNumber" placeholder="+14155551234" required>
                            </label>
                            <p class="nexa-interaction-error" data-nexa-caller-id-error role="alert" hidden></p>
                            <footer>
                                <button type="button" class="btn btn-default" data-nexa-caller-id-close>Cancel</button>
                                <button type="submit" class="btn btn-primary" data-nexa-caller-id-start>Verify</button>
                            </footer>
                        </form>
                    </div>
                </section>`;

            document.body.append(overlay);
            this.overlay = overlay;

            overlay.querySelectorAll('[data-nexa-caller-id-close]').forEach(button => {
                button.addEventListener('click', () => this.close());
            });
            overlay.addEventListener('mousedown', event => {
                if (event.target === overlay) this.close();
            });
            overlay.addEventListener('keydown', event => {
                if (event.key === 'Escape') this.close();
            });
            overlay.querySelector('[data-nexa-caller-id-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.start(new FormData(event.currentTarget).get('phoneNumber'));
            });
        }

        close() {
            this.overlay?.remove();
            this.overlay = null;
        }

        async start(phoneNumber) {
            const body = this.overlay?.querySelector('[data-nexa-caller-id-body]');
            const error = this.overlay?.querySelector('[data-nexa-caller-id-error]');
            const submit = this.overlay?.querySelector('[data-nexa-caller-id-start]');
            if (!body) return;

            const cleaned = String(phoneNumber || '').replace(/[^\d+]/g, '');
            if (!/^\+[1-9]\d{6,14}$/.test(cleaned)) {
                error.textContent = 'Enter the number in international format, starting with "+" and the country code.';
                error.hidden = false;
                return;
            }

            error.hidden = true;
            submit.disabled = true;

            try {
                const result = await Espo.Ajax.postRequest('Nexa/call/caller-id/start', {phoneNumber: cleaned});

                if (result.status === 'verified') {
                    this.renderVerified(cleaned);
                    return;
                }

                this.renderPendingCall(cleaned, result.validationCode);
            } catch (requestError) {
                error.textContent = 'Could not start verification. Check the number and try again.';
                error.hidden = false;
                submit.disabled = false;
            }
        }

        renderPendingCall(phoneNumber, validationCode) {
            const body = this.overlay?.querySelector('[data-nexa-caller-id-body]');
            if (!body) return;

            body.innerHTML = `
                <p>Twilio is calling <strong>${this.escape(phoneNumber)}</strong> now. Answer the call and enter the code it reads out on your keypad.</p>
                ${validationCode ? `
                <p class="nexa-caller-id-code-label">In case the call is hard to hear, the code is:</p>
                <p class="nexa-caller-id-code">${this.escape(validationCode)}</p>` : ''}
                <p class="nexa-interaction-error" data-nexa-caller-id-error role="alert" hidden></p>
                <footer>
                    <button type="button" class="btn btn-default" data-nexa-caller-id-close>Close</button>
                    <button type="button" class="btn btn-primary" data-nexa-caller-id-confirm>I've done it, check now</button>
                </footer>`;

            body.querySelector('[data-nexa-caller-id-close]').addEventListener('click', () => this.close());
            body.querySelector('[data-nexa-caller-id-confirm]').addEventListener('click', () => this.confirm(phoneNumber));
        }

        async confirm(phoneNumber) {
            const body = this.overlay?.querySelector('[data-nexa-caller-id-body]');
            const error = body?.querySelector('[data-nexa-caller-id-error]');
            const confirmButton = body?.querySelector('[data-nexa-caller-id-confirm]');
            if (!body) return;

            error.hidden = true;
            confirmButton.disabled = true;

            try {
                const result = await Espo.Ajax.postRequest('Nexa/call/caller-id/confirm', {});

                if (result.status === 'verified') {
                    this.renderVerified(phoneNumber);
                    return;
                }

                if (result.status === 'unverified') {
                    error.textContent = 'That verification attempt expired. Enter the number again to retry.';
                    error.hidden = false;
                    this.renderStartForm();
                    return;
                }

                error.textContent = 'Not verified yet - make sure you entered the code Twilio read out, then try again.';
                error.hidden = false;
                confirmButton.disabled = false;
            } catch (requestError) {
                error.textContent = 'Could not check verification status. Try again.';
                error.hidden = false;
                confirmButton.disabled = false;
            }
        }

        renderStartForm() {
            const body = this.overlay?.querySelector('[data-nexa-caller-id-body]');
            if (!body) return;

            body.innerHTML = `
                <p>Enter the phone number your team calls customers from. This becomes the caller ID your customers see.</p>
                <form data-nexa-caller-id-form>
                    <label><span>Business phone number</span>
                        <input class="form-control" type="tel" name="phoneNumber" placeholder="+14155551234" required>
                    </label>
                    <p class="nexa-interaction-error" data-nexa-caller-id-error role="alert" hidden></p>
                    <footer>
                        <button type="button" class="btn btn-default" data-nexa-caller-id-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" data-nexa-caller-id-start>Verify</button>
                    </footer>
                </form>`;

            body.querySelector('[data-nexa-caller-id-close]').addEventListener('click', () => this.close());
            body.querySelector('[data-nexa-caller-id-form]').addEventListener('submit', event => {
                event.preventDefault();
                this.start(new FormData(event.currentTarget).get('phoneNumber'));
            });
        }

        renderVerified(phoneNumber) {
            const body = this.overlay?.querySelector('[data-nexa-caller-id-body]');
            if (!body) return;

            body.innerHTML = `
                <p class="nexa-caller-id-success"><span class="fas fa-check-circle" aria-hidden="true"></span>
                    <strong>${this.escape(phoneNumber)}</strong> is verified and ready to use as your caller ID.</p>
                <footer><button type="button" class="btn btn-primary" data-nexa-caller-id-close>Done</button></footer>`;

            body.querySelector('[data-nexa-caller-id-close]').addEventListener('click', () => this.close());

            this.handlers.onVerified?.(phoneNumber);
        }

        escape(value) {
            const node = document.createElement('span');
            node.textContent = String(value ?? '');
            return node.innerHTML;
        }
    };
});
