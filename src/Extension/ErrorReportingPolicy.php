<?php

declare(strict_types=1);

namespace Polaris\Extension;

/**
 * Whether a new user sends error reports by default, once the owner of the instance turned error reporting on.
 *
 * The self-hosted default is yes: the owner is the user, and chose to opt in. The hosted layer replaces it (service
 * decoration or a new alias) with a "no", as the Tesla Fleet API agreement asks for a prior opt-in of the user.
 */
interface ErrorReportingPolicy
{
    public function defaultForNewUser(): bool;
}
