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

    public function test_installer_validates_both_email_prompts_with_crlf_safe_validator(): void
    {
        $installer = file_get_contents(base_path('scripts/install.sh'));
        $validator = base_path('scripts/validate-email.py');
        $validatorSource = file_get_contents($validator);

        $this->assertIsString($installer);
        $this->assertIsString($validatorSource);
        $this->assertStringContainsString('CERT_EMAIL="$(normalize_email "$CERT_EMAIL")"', $installer);
        $this->assertStringContainsString('ADMIN_EMAIL="$(normalize_email "$ADMIN_EMAIL")"', $installer);
        $this->assertStringContainsString('email = email[:-1]', $validatorSource);
        $this->assertStringContainsString('email.count("@") != 1', $validatorSource);

        $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
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
            $this->markTestSkipped('Python 3 is required to exercise the installer email validator.');
        }
        fclose($probePipes[0]);
        fclose($probePipes[1]);
        fclose($probePipes[2]);
        if (proc_close($probe) !== 0) {
            $this->markTestSkipped('Python 3 is required to exercise the installer email validator.');
        }

        foreach ([
            ['kryukov.lex@gmail.com', 'kryukov.lex@gmail.com', 0],
            ["kryukov.lex@gmail.com\r", 'kryukov.lex@gmail.com', 0],
            ['kryukov. lex@gmail.com', '', 1],
            ['kryukov.lex@gmail', '', 1],
        ] as [$input, $expectedOutput, $expectedStatus]) {
            $process = proc_open(
                [$python, $validator, $input],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            $this->assertIsResource($process, 'The email validator process must start.');
            fclose($pipes[0]);
            $output = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame($expectedStatus, proc_close($process));
            $this->assertSame($expectedOutput, $output);
        }
    }

    public function test_certbot_nginx_plugin_is_installed_and_existing_partial_nginx_state_is_reused(): void
    {
        $installer = file_get_contents(base_path('scripts/install.sh'));

        $this->assertIsString($installer);
        $this->assertStringContainsString('certbot composer git nginx python3-certbot-nginx', $installer);

        $partialResumeCheck = strpos($installer, 'if [[ "$PARTIAL_INSTALL" -eq 1 ]]; then');
        $siteConfigValidation = strpos($installer, '! grep -Fqx "    server_name $RECORDED_DOMAIN;" "$NGINX_SITE"');
        $enabledLinkValidation = strpos($installer, '[[ ! -L "$NGINX_ENABLED" ]] || [[ "$(readlink "$NGINX_ENABLED")" != "$NGINX_SITE" ]]');
        $installingMarkerCreation = strpos($installer, 'printf \'%s %s\\n\' "$OWNER_MARKER" "$DOMAIN" >"$DATA_DIR/.installing"');
        $nginxConfigValidation = strpos($installer, 'nginx -t');
        $certbotInvocation = strpos($installer, 'certbot --nginx --non-interactive');

        $this->assertNotFalse($partialResumeCheck);
        $this->assertNotFalse($siteConfigValidation);
        $this->assertNotFalse($enabledLinkValidation);
        $this->assertNotFalse($installingMarkerCreation);
        $this->assertNotFalse($nginxConfigValidation);
        $this->assertNotFalse($certbotInvocation);
        $this->assertLessThan($installingMarkerCreation, $partialResumeCheck);
        $this->assertLessThan($nginxConfigValidation, $enabledLinkValidation);
        $this->assertLessThan($certbotInvocation, $nginxConfigValidation);
        $this->assertStringContainsString('if [[ "$PARTIAL_INSTALL" -eq 1 && "$DOMAIN" != "$RECORDED_DOMAIN" ]]', $installer);
        $this->assertStringContainsString('This partial installation belongs to $RECORDED_DOMAIN', $installer);
        $this->assertStringContainsString('[[ "$conflicting_config" == "$NGINX_ENABLED" ]] && continue', $installer);
        $this->assertStringContainsString('if [[ ! -L "$NGINX_ENABLED" ]]; then', $installer);
    }

    public function test_php_fpm_uses_the_same_user_as_the_sqlite_worker_and_keeps_nginx_socket_access(): void
    {
        $installer = file_get_contents(base_path('scripts/install.sh'));

        $this->assertIsString($installer);
        $this->assertStringContainsString('readonly PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"', $installer);
        $this->assertStringContainsString('sed -i -E "s/^[[:space:]]*user[[:space:]]*=.*/user = $APP_USER/" "$PHP_FPM_POOL"', $installer);
        $this->assertStringContainsString('group[[:space:]]*=[[:space:]]*www-data', $installer);
        $this->assertStringContainsString('listen.owner|listen.group) current_value="www-data"', $installer);
        $this->assertStringContainsString('listen.mode) current_value="0660"', $installer);
        $this->assertStringContainsString('php-fpm8.3 -t', $installer);
        $this->assertStringContainsString('systemctl restart php8.3-fpm', $installer);
        $this->assertStringContainsString('chmod 3770 "$DATA_DIR"', $installer);
        $this->assertStringContainsString('chown "$APP_USER":www-data "$DATA_DIR/database.sqlite"', $installer);

        $createAppUser = strpos($installer, 'useradd --system --gid www-data --home-dir "$APP_DIR"');
        $configurePool = strpos($installer, 'configure_php_fpm_pool'."\nphp-fpm8.3 -t");
        $this->assertNotFalse($createAppUser);
        $this->assertNotFalse($configurePool);
        $this->assertLessThan($configurePool, $createAppUser);
    }
}
