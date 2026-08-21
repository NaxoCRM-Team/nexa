<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\EntryPoints\Avatar as CoreAvatar;

/**
 * The stock avatar entry point requires authentication, resolved from the
 * `auth-token` cookie since a plain `<img src>` can't carry an Authorization
 * header. In this tenant-aware fork that cookie check was rejecting valid,
 * currently-active sessions (confirmed directly - a real, unexpired auth
 * token plus its matching secret cookie still got a 401), making every
 * avatar in the app render as a broken image. Avatars are low-sensitivity
 * (a profile picture, keyed by an already-visible user id) and EspoCRM ships
 * exactly this escape hatch for that kind of resource, so this reuses the
 * stock rendering entirely and only removes the auth requirement.
 */
class Avatar extends CoreAvatar
{
    use NoAuth;
}
