<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\AuthService;
use App\Auth\CurrentUser;
use App\Auth\JwtVerifier;
use App\Auth\SupabaseAuthClient;
use App\Services\AccountService;
use App\Services\Admin\AdminCatalogService;
use App\Services\Admin\AdminInventoryService;
use App\Services\Admin\AdminOrderService;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\ReportService;
use App\Services\Admin\SettingsService;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Storage\ImageStorage;
use App\Storage\ImageValidator;
use App\Storage\LocalImageStorage;
use App\Storage\SupabaseImageStorage;

/**
 * Contenedor de servicios con instanciación perezosa (una instancia por petición).
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];
    public ?CurrentUser $user = null;

    public function __construct(public readonly Config $config)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function once(string $id, callable $factory): object
    {
        return $this->instances[$id] ??= $factory();
    }

    public function db(): Database { return $this->once(Database::class, fn () => new Database($this->config)); }
    public function session(): Session { return $this->once(Session::class, fn () => new Session($this->config)); }
    public function csrf(): Csrf { return $this->once(Csrf::class, fn () => new Csrf($this->session())); }
    public function http(): HttpClient { return $this->once(HttpClient::class, fn () => new HttpClient()); }
    public function limiter(): RateLimiter { return $this->once(RateLimiter::class, fn () => new RateLimiter($this->db(), $this->config)); }
    public function view(): View { return $this->once(View::class, fn () => new View(APP_ROOT . '/views')); }

    public function jwt(): JwtVerifier
    {
        return $this->once(JwtVerifier::class, fn () => new JwtVerifier($this->config, $this->http(), APP_ROOT . '/storage/cache'));
    }

    public function authClient(): SupabaseAuthClient
    {
        return $this->once(SupabaseAuthClient::class, fn () => new SupabaseAuthClient($this->config, $this->http()));
    }

    public function auth(): AuthService
    {
        return $this->once(AuthService::class, fn () => new AuthService(
            $this->config, $this->authClient(), $this->jwt(), $this->db(), $this->session(), $this->limiter(),
        ));
    }

    public function storage(): ImageStorage
    {
        return $this->once(ImageStorage::class, fn () => $this->config->storageDriver === 'local'
            ? new LocalImageStorage(APP_ROOT . '/public')
            : new SupabaseImageStorage($this->config, $this->http()));
    }

    /**
     * Datos globales de las vistas (usuario, navegación, carrito, mensajes).
     * Se calculan una sola vez por petición y nunca incluyen secretos.
     */
    public function shareViewGlobals(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $view = $this->view();
        $view->share('currentUser', $this->user);
        $view->share('config', $this->config);
        try {
            $view->share('storeConfig', $this->catalog()->publicConfig());
            $view->share('navCategories', $this->catalog()->categories());
            $view->share('cartCount', $this->user !== null ? $this->cart()->count() : 0);
        } catch (\Throwable $e) {
            error_log('[view] ' . $e->getMessage());
            $view->share('storeConfig', []);
            $view->share('navCategories', []);
            $view->share('cartCount', 0);
        }
        $view->share('flash', $this->session()->isStarted() ? $this->session()->pullFlash() : []);
    }

    public function catalog(): CatalogService { return $this->once(CatalogService::class, fn () => new CatalogService($this->db())); }
    public function cart(): CartService { return $this->once(CartService::class, fn () => new CartService($this->db())); }
    public function orders(): OrderService { return $this->once(OrderService::class, fn () => new OrderService($this->db(), $this->limiter())); }
    public function account(): AccountService { return $this->once(AccountService::class, fn () => new AccountService($this->db())); }

    public function adminCatalog(): AdminCatalogService
    {
        return $this->once(AdminCatalogService::class, fn () => new AdminCatalogService(
            $this->db(), $this->storage(), new ImageValidator($this->config->uploadMaxBytes),
        ));
    }

    public function adminInventory(): AdminInventoryService { return $this->once(AdminInventoryService::class, fn () => new AdminInventoryService($this->db())); }
    public function adminOrders(): AdminOrderService { return $this->once(AdminOrderService::class, fn () => new AdminOrderService($this->db())); }
    public function adminUsers(): AdminUserService { return $this->once(AdminUserService::class, fn () => new AdminUserService($this->db())); }
    public function reports(): ReportService { return $this->once(ReportService::class, fn () => new ReportService($this->db())); }
    public function settings(): SettingsService { return $this->once(SettingsService::class, fn () => new SettingsService($this->db())); }
}
