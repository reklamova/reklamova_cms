<?php

declare(strict_types=1);

namespace Reklamova\Cms\Auth;

final class SuperAdminIdentity
{
    public const EMAIL = 'biuro@reklamova.pl';

    /** @var list<string> */
    public const LEGACY_EMAILS = ['admin@reklamova.pl'];
}
