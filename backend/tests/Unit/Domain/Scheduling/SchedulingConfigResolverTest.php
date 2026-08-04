<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\SchedulingConfigResolver;
use App\Domain\Scheduling\SchedulingConfiguration;
use App\Models\Tenant;
use App\Models\TenantToolConfig;
use App\Models\WhatsAppLine;
use App\Support\Tenancy\TenantScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingConfigResolverTest extends TestCase
{
    use RefreshDatabase;

    private SchedulingConfigResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SchedulingConfigResolver();
    }

    private function makeTenant(string $slug = 'acme'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Acme',
            'slug' => $slug,
        ]);
    }

    private function makeLine(Tenant $tenant, string $phoneNumberId = 'acme-phone-number-id'): WhatsAppLine
    {
        return WhatsAppLine::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Primary Line',
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+573001112233',
            'status' => 'active',
            'is_enabled' => true,
        ]);
    }

    public function test_no_config_at_all_returns_defaults(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        $config = $this->resolver->forLine($line);

        $this->assertSame(SchedulingConfiguration::DEFAULT_CALENDAR_ID, $config->calendarId);
        $this->assertSame(SchedulingConfiguration::DEFAULT_TIMEZONE, $config->timezone);
        $this->assertSame([], $config->windowsForWeekday('monday'));
    }

    public function test_tenant_scoped_overrides_apply_when_no_line_override_exists(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => SchedulingConfigResolver::CONFIG_TOOL_NAME,
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'tenant-calendar@example.com',
                'timezone' => 'America/Bogota',
                'business_hours' => [
                    'monday' => [['start' => '09:00', 'end' => '17:00']],
                ],
            ],
        ]);

        $config = $this->resolver->forLine($line);

        $this->assertSame('tenant-calendar@example.com', $config->calendarId);
        $this->assertCount(1, $config->windowsForWeekday('monday'));
    }

    public function test_line_scoped_overrides_win_over_tenant_scoped_overrides(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => SchedulingConfigResolver::CONFIG_TOOL_NAME,
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'tenant-calendar@example.com',
                'business_hours' => [
                    'monday' => [['start' => '09:00', 'end' => '13:00']],
                ],
            ],
        ]);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => $line->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($line),
            'tool_name' => SchedulingConfigResolver::CONFIG_TOOL_NAME,
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'line-calendar@example.com',
                'business_hours' => [
                    'monday' => [['start' => '10:00', 'end' => '18:00']],
                ],
            ],
        ]);

        $config = $this->resolver->forLine($line);

        // Whole-record fallback, not a per-field merge: the line row wins
        // entirely, including for fields it also set (see design decision
        // "line scope inheritance is all-or-nothing").
        $this->assertSame('line-calendar@example.com', $config->calendarId);
        $windows = $config->windowsForWeekday('monday');
        $this->assertSame('10:00', $windows[0]->start);
    }

    public function test_a_different_tools_config_is_ignored(): void
    {
        $tenant = $this->makeTenant();
        $line = $this->makeLine($tenant);

        TenantToolConfig::query()->create([
            'tenant_id' => $tenant->id,
            'whatsapp_line_id' => null,
            'scope_type' => 'tenant',
            'scope_key' => TenantScopeKey::forTenant($tenant),
            'tool_name' => 'search_knowledge',
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'should-not-be-used@example.com',
            ],
        ]);

        $config = $this->resolver->forLine($line);

        $this->assertSame(SchedulingConfiguration::DEFAULT_CALENDAR_ID, $config->calendarId);
    }

    public function test_a_different_tenants_line_scoped_config_is_ignored(): void
    {
        $tenantA = $this->makeTenant('acme');
        $lineA = $this->makeLine($tenantA, 'acme-phone-number-id');

        $tenantB = $this->makeTenant('other-co');
        $lineB = $this->makeLine($tenantB, 'other-co-phone-number-id');

        TenantToolConfig::query()->create([
            'tenant_id' => $tenantB->id,
            'whatsapp_line_id' => $lineB->id,
            'scope_type' => 'whatsapp_line',
            'scope_key' => TenantScopeKey::forWhatsAppLine($lineB),
            'tool_name' => SchedulingConfigResolver::CONFIG_TOOL_NAME,
            'enabled' => true,
            'overrides' => [
                'calendar_id' => 'other-co-calendar@example.com',
            ],
        ]);

        $config = $this->resolver->forLine($lineA);

        $this->assertSame(SchedulingConfiguration::DEFAULT_CALENDAR_ID, $config->calendarId);
    }
}
