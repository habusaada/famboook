<?php

namespace Tests\Feature\Auth;

use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * The API is JSON-only: an unauthenticated request to a protected /api route
 * answers 401 {"message": "Unauthenticated."} WHATEVER its Accept header —
 * never a redirect to a web login route, which does not exist in this
 * backend (it used to throw RouteNotFoundException: HTTP 500 for any client
 * that did not ask for JSON). The fix is central (bootstrap/app.php,
 * shouldRenderJsonWhen for api/*); the sweep below proves it for every
 * auth:sanctum route. Synthetic data only.
 */
class ApiUnauthenticatedTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const UNAUTHENTICATED = ['message' => 'Unauthenticated.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function person(): Person
    {
        return Person::factory()->create(['mobile' => '0591234567']);
    }

    public function test_unauthenticated_mobile_trust_read_is_401_json_not_500(): void
    {
        $uri = '/api/v1/people/'.$this->person()->person_code.'/mobile-trust';

        // A client that does not ask for JSON (browser, curl, fetch default).
        $this->get($uri)->assertStatus(401)->assertExactJson(self::UNAUTHENTICATED);
        $this->get($uri, ['Accept' => 'text/html'])->assertStatus(401)->assertExactJson(self::UNAUTHENTICATED);
        // And one that does: unchanged.
        $this->getJson($uri)->assertStatus(401)->assertExactJson(self::UNAUTHENTICATED);
    }

    public function test_unauthenticated_protected_post_is_401_json_not_a_redirect(): void
    {
        $uri = '/api/v1/people/'.$this->person()->person_code.'/mobile-trust';

        $response = $this->post($uri, ['verification_method' => 'IN_PERSON']);

        $response->assertStatus(401)->assertExactJson(self::UNAUTHENTICATED);
        $this->assertFalse($response->isRedirect());
        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(0, PersonMobileTrust::count());
    }

    public function test_every_protected_api_route_answers_401_json_without_a_json_accept_header(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/')
                && in_array('auth:sanctum', $route->gatherMiddleware(), true));
        $this->assertGreaterThan(50, $routes->count(), 'The sweep must actually cover the protected API.');

        foreach ($routes as $route) {
            // A parameter value the route accepts: the first literal of a
            // constrained alternation (e.g. a report name), else a uuid.
            $uri = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route) {
                $pattern = $route->wheres[$m[1]] ?? null;

                return $pattern !== null && preg_match('/\A[a-z][a-z\-]*(\|[a-z][a-z\-]*)+\z/', $pattern) === 1
                    ? explode('|', $pattern)[0]
                    : str_replace('-', '', (string) Str::uuid());
            }, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->app['auth']->forgetGuards();
                $response = $this->call($method, $uri);
                $this->assertSame(401, $response->getStatusCode(), "{$method} {$uri}");
                $this->assertSame(self::UNAUTHENTICATED, $response->json(), "{$method} {$uri}");
            }
        }
    }

    public function test_an_authorized_staff_request_is_unchanged(): void
    {
        $person = $this->person();
        $admin = User::factory()->create()->assignRole('ADMINISTRATOR');

        $this->actingAs($admin)->get('/api/v1/people/'.$person->person_code.'/mobile-trust')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/people/'.$person->person_code.'/mobile-trust')->assertOk();
    }

    public function test_the_staff_side_boundary_and_permissions_still_apply(): void
    {
        $person = $this->person();
        $uri = '/api/v1/people/'.$person->person_code.'/mobile-trust';

        // A family-side account: refused by staff.side.
        $family = $this->activatedHead('123456789')['user'];
        $this->actingAs($family)->get($uri)->assertStatus(403);

        // A Staff account without the permission: refused by can:.
        $this->app['auth']->forgetGuards();
        $entry = User::factory()->create()->assignRole('DATA_ENTRY');
        $this->actingAs($entry)->get($uri)->assertStatus(403);
    }

    public function test_non_api_routes_keep_their_own_behaviour(): void
    {
        // The JSON rule is scoped to /api/*: the health route is untouched.
        $this->get('/up')->assertOk();
    }
}
