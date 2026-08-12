<main class="modern-login-shell">
    <section class="modern-login-visual" aria-label="A bright collaborative workspace">
        <a class="modern-login-brand" href="../" data-action="nexaHome" aria-label="Nexa CRM home">
            <span class="modern-login-mark" aria-hidden="true">N</span>
            <span class="modern-login-brand-name">Nexa CRM</span>
        </a>

        <div class="modern-login-visual-copy">
            <p class="modern-login-kicker">One customer workspace</p>
            <h2>Turn every customer signal into your next best action.</h2>
            <p>Sales, marketing and service teams move together in one secure workspace.</p>
            <div class="modern-login-proof" aria-label="Workspace highlights">
                <span><strong>89</strong> connected capabilities</span>
                <span><strong>24/7</strong> customer context</span>
            </div>
        </div>
    </section>

    <section class="modern-login-auth">
        <a class="modern-login-mobile-brand" href="../" data-action="nexaHome" aria-label="Nexa CRM home">
            <span class="modern-login-mark" aria-hidden="true">N</span>
            <span class="modern-login-brand-name">Nexa CRM</span>
        </a>

        <div id="login" class="modern-login-panel">
            <header class="modern-login-header">
                <p class="modern-login-eyebrow">Your Nexa workspace</p>
                <h1>Good to see you again</h1>
                <p>Sign in once. We will securely find the right workspace.</p>
            </header>

            <div class="modern-recovery-message" data-login-message role="alert" hidden></div>
            <form id="login-form" class="modern-login-form">
                <div class="modern-social-providers" data-auth-providers hidden aria-label="Social account sign in"></div>
                <div class="modern-auth-divider" data-auth-divider hidden><span>or continue with your account</span></div>
                {{#if hasSignIn}}
                <div class="cell modern-login-provider" data-name="sign-in">
                    <button class="btn btn-default" id="sign-in" type="button">{{signInText}}</button>
                    {{#if hasFallback}}
                    <a role="button" tabindex="0" class="btn btn-link btn-icon" data-action="showFallback" title="{{translate 'Show'}}"><span class="fas fa-chevron-down"></span></a>
                    {{/if}}
                </div>
                {{/if}}

                <div class="form-group cell" data-name="username">
                    <label for="field-userName">{{translate 'Username'}}</label>
                    <div class="modern-login-input">
                        <span class="far fa-user" aria-hidden="true"></span>
                        <input type="text" name="username" id="field-userName" class="form-control" autocapitalize="off" spellcheck="false" tabindex="1" autocomplete="username" maxlength="255">
                    </div>
                </div>

                <div class="form-group cell" data-name="password">
                    <label for="field-password">{{translate 'Password'}}</label>
                    <div class="modern-login-input" data-role="password-input-container">
                        <span class="fas fa-lock" aria-hidden="true"></span>
                        <input type="password" name="password" id="field-password" class="form-control" tabindex="2" autocomplete="current-password" maxlength="255">
                        <a role="button" tabindex="0" data-action="toggleShowPassword" class="modern-login-password-toggle" title="{{translate 'View'}}"><span class="far fa-eye"></span></a>
                    </div>
                </div>

                {{#if anotherUser}}
                <div class="form-group cell modern-login-another-user">
                    <span>{{translate 'Log in as'}}</span>
                    <strong>{{anotherUser}}</strong>
                </div>
                {{/if}}

                <div class="cell modern-login-actions" data-name="submit">
                    <button type="button" class="modern-login-forgot" data-action="nexaRecovery" tabindex="4">Forgot password?</button>
                    <button type="submit" class="btn btn-primary" id="btn-login" tabindex="3">{{logInText}} <span class="fas fa-arrow-right" aria-hidden="true"></span></button>
                </div>
            </form>

            <p class="modern-create-account">New to Nexa? <a href="?signup=growth">Create an account</a></p>
            <p class="modern-login-security"><span class="fas fa-shield-alt" aria-hidden="true"></span>Tenant-aware sign in protected by Nexa</p>
        </div>

        <div class="modern-login-panel modern-recovery-panel" data-recovery-panel hidden>
            <button class="modern-back-button" type="button" data-recovery-back><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to sign in</button>
            <header class="modern-login-header">
                <p class="modern-login-eyebrow">Secure account recovery</p>
                <h1>Let us get you back in</h1>
                <p>Enter the email address connected to your account.</p>
            </header>
            <form class="modern-login-form" data-recovery-form novalidate>
                <div class="modern-recovery-message" data-recovery-message role="status" aria-live="polite" hidden></div>

                <div class="form-group">
                    <label for="recovery-email">Email address</label>
                    <div class="modern-login-input"><span class="far fa-envelope" aria-hidden="true"></span><input class="form-control" id="recovery-email" name="email" type="email" autocomplete="email" maxlength="190" required></div>
                </div>
                <button class="btn btn-primary modern-submit" type="submit"><span>Send reset instructions</span></button>
            </form>
        </div>
    </section>
</main>
<footer class="modern-login-footer">&copy; Nexa CRM <span data-nexa-current-year></span></footer>
