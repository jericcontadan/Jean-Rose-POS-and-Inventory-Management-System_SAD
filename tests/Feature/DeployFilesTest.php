<?php

namespace Tests\Feature;

use Tests\TestCase;

// Deployment check (docs/07-deployment.md, section 9): reads the CI/CD files as text and checks the safety rules.
// It never runs the script and never connects to the server.
class DeployFilesTest extends TestCase
{
    public function test_deploy_script_can_run_on_ubuntu_and_always_puts_the_site_back_up(): void
    {
        $script = file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $script);
        $this->assertStringNotContainsString("\r", $script, 'deploy.sh must have LF line endings.');
        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('trap finish EXIT', $script);
        $this->assertStringContainsString('php artisan up', $script);
    }

    public function test_deploy_script_never_touches_the_database_or_env(): void
    {
        // Remove the comment lines first: they may say "no migrations".
        $commands = preg_replace('/^\s*#.*$/m', '', file_get_contents(base_path('scripts/deploy.sh')));

        foreach (['migrate', 'db:', 'mysql', '.sql', 'tinker', 'key:generate', '.env'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $commands);
        }
    }

    public function test_deploy_workflow_runs_only_after_tests_pass_on_a_push_to_main(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('workflows: [Tests]', $workflow);
        $this->assertStringContainsString("workflow_run.conclusion == 'success'", $workflow);
        $this->assertStringContainsString("workflow_run.event == 'push'", $workflow);
        $this->assertStringContainsString("workflow_run.head_branch == 'main'", $workflow);
        $this->assertStringContainsString('group: deploy-production', $workflow);
    }

    public function test_deploy_files_contain_no_real_server_address_or_key(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));
        $files = $workflow.file_get_contents(base_path('scripts/deploy.sh'));

        foreach (['DEPLOY_HOST', 'DEPLOY_USER', 'DEPLOY_SSH_KEY'] as $secret) {
            $this->assertStringContainsString('${{ secrets.'.$secret.' }}', $workflow);
        }

        $this->assertDoesNotMatchRegularExpression('/\d{1,3}(\.\d{1,3}){3}/', $files, 'No IP address in the files.');
        $this->assertStringNotContainsString('PRIVATE KEY', $files);
    }
}
