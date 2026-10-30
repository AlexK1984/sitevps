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
}
