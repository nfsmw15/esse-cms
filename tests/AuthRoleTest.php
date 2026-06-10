<?php

declare(strict_types=1);

use Esse\Auth;

// Setzt den privaten statischen "currentUser"-State direkt per Reflection, um
// rollenbasierte Logik (meetsRole/can/role/...) ohne DB- bzw. Session-Login zu testen.
function setAuthUser(?array $user): void
{
    $prop = new ReflectionProperty(Auth::class, 'currentUser');
    $prop->setValue(null, $user);
}

return [
    'meetsRole: Gast erfuellt nur "guest"' => function () {
        setAuthUser(null);
        Assert::true(Auth::meetsRole('guest'));
        Assert::false(Auth::meetsRole('member'));
        Assert::false(Auth::meetsRole('forge'));
    },

    'meetsRole: Rollen-Hierarchie greift (editor erfuellt niedrigere, nicht hoehere)' => function () {
        setAuthUser(['id' => 1, 'role' => 'editor']);
        Assert::true(Auth::meetsRole('guest'));
        Assert::true(Auth::meetsRole('member'));
        Assert::true(Auth::meetsRole('author'));
        Assert::true(Auth::meetsRole('editor'));
        Assert::false(Auth::meetsRole('admin'));
        Assert::false(Auth::meetsRole('forge'));
        setAuthUser(null);
    },

    'meetsRole: Forge erfuellt jede Rolle' => function () {
        setAuthUser(['id' => 1, 'role' => 'forge']);
        foreach (Auth::ROLES as $role) {
            Assert::true(Auth::meetsRole($role), "forge sollte '{$role}' erfuellen");
        }
        setAuthUser(null);
    },

    'role()/check()/guest()/id(): spiegeln den aktuellen Benutzer' => function () {
        setAuthUser(null);
        Assert::same('guest', Auth::role());
        Assert::false(Auth::check());
        Assert::true(Auth::guest());
        Assert::same(null, Auth::id());

        setAuthUser(['id' => 42, 'role' => 'admin']);
        Assert::same('admin', Auth::role());
        Assert::true(Auth::check());
        Assert::false(Auth::guest());
        Assert::same(42, Auth::id());
        setAuthUser(null);
    },

    'can(): Forge hat immer alle Berechtigungen, auch ohne DB' => function () {
        setAuthUser(['id' => 1, 'role' => 'forge']);
        Assert::true(Auth::can('manage_settings'));
        Assert::true(Auth::can('irgendein_unbekanntes_recht'));
        setAuthUser(null);
    },

    'can(): Gast (kein eingeloggter Benutzer) hat keine Berechtigungen' => function () {
        setAuthUser(null);
        Assert::false(Auth::can('manage_settings'));
        Assert::false(Auth::canAny(['manage_settings', 'manage_users']));
    },
];
