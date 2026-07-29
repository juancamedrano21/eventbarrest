<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Actions\CreateTenantUser;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Platform\Actions\CreateTenant;
use App\Domains\Platform\Enums\TenantType;
use App\Domains\Platform\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * TEMPORAL - auditoria del ciclo de vida de permisos. Borrar al terminar.
 */
class TmpLifecycleProbe extends Command
{
    protected $signature = 'tmp:lifecycle-probe';

    protected $description = 'TEMP probe';

    /** @var array<int, string> */
    private array $sql = [];

    private string $phase = 'boot';

    public function handle(): int
    {
        $marker = 'probe-'.Str::random(6);

        // ---- datos de prueba: cuenta de organizador + owner -----------------
        $tenant = app(CreateTenant::class)($marker.' Organizer', null, TenantType::Organizer);
        $user = app(CreateTenantUser::class)(
            $tenant,
            'Probe Owner',
            $marker.'@probe.test',
            'Secreta-2026',
            RoleEnum::Owner,
        );

        $this->info("tenant_id={$tenant->id} user_id={$user->id} class=".$tenant::class);

        // ---- sesion autenticada real (driver database) ----------------------
        $sessionId = Str::random(40);
        $token = Str::random(40);
        $payload = [
            '_token' => $token,
            'login_web_'.sha1(\Illuminate\Auth\SessionGuard::class) => $user->id,
            'password_hash_web' => $user->password,
        ];
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'probe',
            'payload' => base64_encode(serialize($payload)),
            'last_activity' => time(),
        ]);

        $cookieName = config('session.cookie');
        $cookie = Crypt::encrypt(
            CookieValuePrefix::create($cookieName, Crypt::getKey()).$sessionId,
            false,
        );

        // ---- instrumentacion no invasiva ------------------------------------
        DB::listen(function ($q): void {
            if (str_contains($q->sql, 'model_has_roles') || str_contains($q->sql, '`roles`')) {
                $this->sql[] = $this->phase.' | '.preg_replace('/\s+/', ' ', $q->sql)
                    .' || bindings='.json_encode($q->bindings);
            }
        });

        Event::listen(RouteMatched::class, function (RouteMatched $e): void {
            $mw = collect($e->route->gatherMiddleware())
                ->map(fn ($m) => is_string($m) ? class_basename(Str::before($m, ':')) : 'Closure')
                ->implode(', ');
            $this->line("  [{$this->phase}] route=".$e->route->uri().' middleware='.$mw);
        });

        $kernel = app(Kernel::class);

        // ---- FASE 1: carga completa de /app/vendors -------------------------
        $this->phase = 'GET /app/vendors';
        $this->newLine();
        $this->info('=== FASE 1: '.$this->phase.' ===');

        $req = Request::create('/app/vendors', 'GET');
        $req->cookies->set($cookieName, $cookie);
        $res = $kernel->handle($req);
        $html = $res->getContent();

        $this->line('  status='.$res->getStatusCode());
        $this->line('  menu contiene "Negocios": '.(str_contains($html, '>Negocios<') ? 'SI' : 'NO'));
        $this->line('  teamId al final de la peticion: '.var_export(getPermissionsTeamId(), true));

        // snapshot del componente de la pagina
        preg_match('/wire:snapshot="([^"]*)"/', $html, $m);
        $snapshot = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        $this->line('  snapshot capturado: '.(strlen($snapshot) > 0 ? 'si ('.strlen($snapshot).' bytes)' : 'NO'));

        preg_match('/"csrfToken":"([^"]+)"/', $html, $c);
        $csrf = $c[1] ?? $token;

        foreach ($this->sql as $line) {
            $this->line('  SQL '.$line);
        }
        $this->sql = [];

        // ---- FASE 2: peticion de Livewire sobre el MISMO componente ---------
        $this->phase = 'POST livewire/update';
        $this->newLine();
        $this->info('=== FASE 2: '.$this->phase.' ===');

        $updateUri = app(\Livewire\Mechanisms\HandleRequests\HandleRequests::class)->getUpdateUri();
        $this->line('  uri='.$updateUri);

        $body = [
            '_token' => $csrf,
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ];

        $req2 = Request::create($updateUri, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LIVEWIRE' => '1',
            'HTTP_X_CSRF_TOKEN' => $csrf,
            'HTTP_REFERER' => config('app.url').'/app/vendors',
        ], json_encode($body));
        $req2->cookies->set($cookieName, $cookie);

        $res2 = $kernel->handle($req2);
        $this->line('  status='.$res2->getStatusCode());
        $this->line('  teamId al final: '.var_export(getPermissionsTeamId(), true));
        $this->line('  body='.Str::limit(strip_tags((string) $res2->getContent()), 220));

        foreach ($this->sql as $line) {
            $this->line('  SQL '.$line);
        }

        // ---- limpieza -------------------------------------------------------
        DB::table('sessions')->where('id', $sessionId)->delete();
        $this->cleanup($tenant, $user);

        return self::SUCCESS;
    }

    private function cleanup(Tenant $tenant, User $user): void
    {
        DB::table('model_has_roles')->where('model_id', $user->id)->delete();
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->where('tenant_id', $tenant->id)->pluck('id'))
            ->delete();
        DB::table('roles')->where('tenant_id', $tenant->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
        DB::table('tenants')->where('id', $tenant->id)->delete();
        $this->newLine();
        $this->info('datos de prueba borrados');
    }
}
