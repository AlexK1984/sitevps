<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallerStdinTest extends TestCase
{
    public function test_bootstrap_and_installer_read_interactive_input_from_the_controlling_terminal(): void
    {
        $bootstrap = file_get_contents(base_path('scripts/bootstrap.sh'));
        $installer = file_get_contents(base_path('scripts/install.sh'));

        $this->assertIsString($bootstrap);
        $this->assertIsString($installer);
        $this->assertStringContainsString('exec 3<>/dev/tty', $bootstrap);
        $this->assertStringContainsString('open_interactive_tty', $bootstrap);
        $this->assertStringContainsString('exec bash "$CHECKOUT_DIR/scripts/install.sh"', $bootstrap);
        $this->assertStringContainsString('exec 3<>/dev/tty', $installer);
        $this->assertStringContainsString('open_interactive_tty', $installer);

        foreach ([
            'Website domain',
            'VPS public IP address(es)',
            'Email address for Let\'s Encrypt',
            'Administrator email',
            'Confirm your VPS/provider firewall',
        ] as $prompt) {
            $this->assertMatchesRegularExpression(
                '/read -r -u 3 -p "[^"]*'.preg_quote($prompt, '/').'/',
                $installer,
                "Installer prompt '{$prompt}' must read from the controlling terminal.",
            );
        }

        $this->assertStringContainsString(
            'app:make-admin "$ADMIN_EMAIL" <&3',
            $installer,
            'Initial administrator setup must receive input from the controlling terminal.',
        );
        $this->assertStringContainsString('An interactive terminal is required.', $bootstrap);
        $this->assertStringContainsString('An interactive terminal is required.', $installer);
    }

    public function test_bootstrap_fast_forwards_only_a_verified_clean_existing_checkout(): void
    {
        $bootstrap = file_get_contents(base_path('scripts/bootstrap.sh'));

        $this->assertIsString($bootstrap);
        $cleanlinessCheck = strpos($bootstrap, 'git -C "$CHECKOUT_DIR" status --porcelain --untracked-files=all');
        $fetch = strpos($bootstrap, 'git -C "$CHECKOUT_DIR" fetch --no-tags origin "$REPOSITORY_BRANCH"');
        $ancestorCheck = strpos($bootstrap, 'git -C "$CHECKOUT_DIR" merge-base --is-ancestor HEAD FETCH_HEAD');
        $fastForward = strpos($bootstrap, 'git -C "$CHECKOUT_DIR" merge --ff-only FETCH_HEAD');
        $installerLaunch = strpos($bootstrap, 'exec bash "$CHECKOUT_DIR/scripts/install.sh"');

        $this->assertNotFalse($cleanlinessCheck);
        $this->assertNotFalse($fetch);
        $this->assertNotFalse($ancestorCheck);
        $this->assertNotFalse($fastForward);
        $this->assertNotFalse($installerLaunch);
        $this->assertLessThan($fetch, $cleanlinessCheck);
        $this->assertLessThan($ancestorCheck, $fetch);
        $this->assertLessThan($fastForward, $ancestorCheck);
        $this->assertLessThan($installerLaunch, $fastForward);
        $this->assertStringContainsString('refusing to replace or merge them', $bootstrap);
        $this->assertStringNotContainsString('reset --hard', $bootstrap);
    }
}
