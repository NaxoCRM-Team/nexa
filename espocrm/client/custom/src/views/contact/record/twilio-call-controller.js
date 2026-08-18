define('custom:views/contact/record/twilio-call-controller', [], function () {
    let sdkLoadPromise = null;

    /**
     * Owns the Twilio Voice SDK Device lifecycle for one Contact detail page.
     * Loads the vendored SDK on first use (plain <script> tag - this app has no
     * AMD-loader source available to verify against, so this avoids guessing at
     * that API and just loads window.Twilio directly, matching how the SDK
     * file itself exposes a global).
     */
    return class TwilioCallController {
        constructor(basePath) {
            this.basePath = basePath || '';
            this.device = null;
            this.activeCall = null;
        }

        loadSdk() {
            if (window.Twilio && window.Twilio.Device) {
                return Promise.resolve();
            }
            if (sdkLoadPromise) {
                return sdkLoadPromise;
            }

            sdkLoadPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = `${this.basePath}client/custom/lib/twilio.js`;
                script.async = true;
                script.onload = () => {
                    if (window.Twilio && window.Twilio.Device) resolve();
                    else reject(new Error('Twilio Voice SDK failed to initialize.'));
                };
                script.onerror = () => {
                    sdkLoadPromise = null;
                    reject(new Error('Twilio Voice SDK could not be loaded.'));
                };
                document.head.appendChild(script);
            });

            return sdkLoadPromise;
        }

        async ensureDevice(token) {
            await this.loadSdk();

            if (this.device) {
                this.device.updateToken(token);
                return this.device;
            }

            this.device = new window.Twilio.Device(token, {
                codecPreferences: ['opus', 'pcmu'],
            });

            return this.device;
        }

        /**
         * @param {string} token Twilio Access Token (from POST Nexa/call/token).
         * @param {string} correlationId From POST Nexa/call/initiate - sent as a
         *   custom TwiML param so the /call/twiml webhook can resolve the session.
         * @param {string} toNumber E.164 number to dial.
         * @param {object} handlers onAccept, onDisconnect, onCancel, onReject, onError, onDeviceError.
         */
        async startCall(token, correlationId, toNumber, handlers = {}) {
            const device = await this.ensureDevice(token);

            if (handlers.onDeviceError) {
                device.removeAllListeners('error');
                device.on('error', handlers.onDeviceError);
            }

            const call = await device.connect({params: {correlationId, To: toNumber}});
            this.activeCall = call;

            if (handlers.onAccept) call.on('accept', handlers.onAccept);
            if (handlers.onDisconnect) call.on('disconnect', () => { this.activeCall = null; handlers.onDisconnect(); });
            if (handlers.onCancel) call.on('cancel', () => { this.activeCall = null; handlers.onCancel(); });
            if (handlers.onReject) call.on('reject', () => { this.activeCall = null; handlers.onReject(); });
            if (handlers.onError) call.on('error', handlers.onError);

            return call;
        }

        hangUp() {
            if (this.activeCall) {
                this.activeCall.disconnect();
                this.activeCall = null;
            }
        }

        destroy() {
            this.hangUp();
            if (this.device) {
                this.device.destroy();
                this.device = null;
            }
        }
    };
});
