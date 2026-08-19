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

            sdkLoadPromise = this.loadLoglevel()
                .then(() => new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = `${this.basePath}client/custom/lib/twilio.js`;
                    script.async = true;
                    script.onload = () => {
                        if (window.Twilio && window.Twilio.Device) resolve();
                        else reject(new Error('Twilio Voice SDK failed to initialize.'));
                    };
                    script.onerror = () => reject(new Error('Twilio Voice SDK could not be loaded.'));
                    document.head.appendChild(script);
                }))
                .catch(error => {
                    sdkLoadPromise = null;
                    throw error;
                });

            return sdkLoadPromise;
        }

        /**
         * loglevel (a Twilio SDK dependency) ships as a raw UMD file. Its AMD
         * branch fires on any truthy `define`, including EspoCRM's own
         * unrelated global AMD-style module loader - so `define` has to be
         * hidden for the duration of this one script load, or the SDK ends up
         * with a broken logger and throws inside the Device constructor.
         */
        loadLoglevel() {
            if (window.log && window.log.levels) {
                return Promise.resolve();
            }

            return new Promise((resolve, reject) => {
                const originalDefine = window.define;
                window.define = undefined;

                const script = document.createElement('script');
                script.src = `${this.basePath}client/custom/lib/loglevel.js`;
                script.async = true;
                script.onload = () => {
                    window.define = originalDefine;
                    if (window.log && window.log.levels) resolve();
                    else reject(new Error('loglevel failed to initialize.'));
                };
                script.onerror = () => {
                    window.define = originalDefine;
                    reject(new Error('loglevel could not be loaded.'));
                };
                document.head.appendChild(script);
            });
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
