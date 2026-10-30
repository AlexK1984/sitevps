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

    public function test_ipv4_mapped_ipv6_dns_address_matches_its_ipv4_address(): void
    {
        $installer = file_get_contents(base_path('scripts/install.sh'));
        $normalizer = base_path('scripts/normalize-public-ip.py');
        $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';

        $this->assertIsString($installer);
        $normalizerSource = file_get_contents($normalizer);
        $this->assertIsString($normalizerSource);
        $this->assertStringContainsString('address.ipv4_mapped', $normalizerSource);
        $this->assertStringContainsString(
            'normalized_dns_address="$(normalize_public_ip "$dns_address")"',
            $installer,
        );

        $probe = proc_open(
            [$python, '--version'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $probePipes,
        );
        if (! is_resource($probe)) {
            $this->markTestSkipped('Python 3 is required to exercise the installer IP normalizer.');
        }
        fclose($probePipes[0]);
        fclose($probePipes[1]);
        fclose($probePipes[2]);
        if (proc_close($probe) !== 0) {
            $this->markTestSkipped('Python 3 is required to exercise the installer IP normalizer.');
        }

        foreach ([
            '::ffff:157.228.138.15' => '157.228.138.15',
            '157.228.138.15' => '157.228.138.15',
        ] as $input => $expected) {
            $process = proc_open(
                [$python, $normalizer, $input],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            $this->assertIsResource($process, 'The IP normalizer process must start.');
            fclose($pipes[0]);
            $output = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $error);
            $this->assertSame($expected, $output);
        }
    }
}
