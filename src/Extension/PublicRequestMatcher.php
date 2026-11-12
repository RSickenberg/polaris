<?php

declare(strict_types=1);

namespace Polaris\Extension;

use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Says which requests are open to anonymous visitors, besides the public pages of Polaris itself.
 *
 * `access_control` in `config/packages/security.yaml` has one rule for it, after the public rules and before the
 * catch-all that requires a signed-in user. The self-hosted default matches nothing, so the install stays closed.
 * The hosted layer replaces it (service decoration or a new alias) to open its own pages: a request it matches is
 * public, every other one is still protected by the catch-all.
 */
interface PublicRequestMatcher extends RequestMatcherInterface
{
}
