<main class="modern-reset-shell">
    <section class="modern-reset-brand">
        <a href="./" class="modern-login-brand"><span class="modern-login-mark" aria-hidden="true">N</span><span class="modern-login-brand-name">Nexa CRM</span></a>
        <div><p class="modern-login-kicker">Secure account recovery</p><h1>Choose a new password</h1><p>Use a strong password that you have not used for this account before.</p></div>
    </section>
    <section class="modern-reset-content">
        <div class="modern-reset-panel">
            {{#unless notFound}}
            <div class="password-change">
            <header><p class="modern-login-eyebrow">Final step</p><h2>Reset your password</h2><p>Your reset session is protected and can only be used once.</p></header>
            <div class="form-group"><label>{{translate 'newPassword' category='fields' scope='User'}}</label><div class="field" data-name="password">{{{password}}}</div></div>
            <div class="form-group"><label>{{translate 'newPasswordConfirm' category='fields' scope='User'}}</label><div class="field" data-name="passwordConfirm">{{{passwordConfirm}}}</div></div>
            <button type="button" class="btn btn-primary btn-submit modern-submit" id="btn-submit">{{translate 'Submit'}}</button>
            </div>
            {{else}}
            <div class="modern-reset-expired" role="status"><span class="fas fa-clock" aria-hidden="true"></span><h2>This reset session is unavailable</h2><p>The link may have expired or already been used. Request a new password reset to continue.</p><a class="btn btn-primary modern-submit" href="?login=1&amp;recovery=1">Return to sign in</a></div>
            {{/unless}}
            <div class="msg-box hidden" role="status" aria-live="polite"></div>
        </div>
    </section>
</main>
