<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Recursos del cliente autenticado: carrito, pedidos, perfil, direcciones y
 * favoritos. Todas las rutas usan el middleware 'auth'.
 */
final class CustomerApiController extends Controller
{
    // ------------------------------------------------------------- Carrito

    public function cart(Request $request): Response
    {
        return $this->ok($this->c->cart()->get());
    }

    public function cartAdd(Request $request): Response
    {
        return $this->ok($this->c->cart()->add($request->input()), 201);
    }

    public function cartUpdate(Request $request): Response
    {
        return $this->ok($this->c->cart()->update($request->param('id'), $request->input()));
    }

    public function cartRemove(Request $request): Response
    {
        return $this->ok($this->c->cart()->remove($request->param('id')));
    }

    public function cartClear(Request $request): Response
    {
        return $this->ok($this->c->cart()->clear());
    }

    // ------------------------------------------------------------- Pedidos

    public function orders(Request $request): Response
    {
        $page = (int) ($request->query['pagina'] ?? $request->query['page'] ?? 1);
        $estado = is_string($request->query['estado'] ?? null) ? $request->query['estado'] : null;
        return $this->paginated($this->c->orders()->list($page, 10, $estado));
    }

    public function order(Request $request): Response
    {
        return $this->ok($this->c->orders()->get($request->param('id')));
    }

    public function orderCreate(Request $request): Response
    {
        $canal = $this->user()->via === 'bearer' ? 'app' : 'web';
        return $this->ok($this->c->orders()->create($request->input(), $this->user()->id, $canal), 201);
    }

    public function orderCancel(Request $request): Response
    {
        return $this->ok($this->c->orders()->cancel($request->param('id')));
    }

    // -------------------------------------------------------------- Perfil

    public function profile(Request $request): Response
    {
        return $this->ok($this->c->account()->profile());
    }

    public function profileUpdate(Request $request): Response
    {
        return $this->ok($this->c->account()->updateProfile($request->input()));
    }

    public function passwordUpdate(Request $request): Response
    {
        $v = new Validator($request->input());
        $actual = $v->password('password_actual', false);
        $nueva = $v->password('password');
        $v->check();
        if ($actual === $nueva) {
            throw HttpException::validation(['password' => 'La nueva contraseña debe ser diferente a la actual.']);
        }
        $this->c->auth()->changePassword($this->user(), (string) $actual, (string) $nueva, $request);
        return $this->ok(['mensaje' => 'Contraseña actualizada.']);
    }

    // --------------------------------------------------------- Direcciones

    public function addresses(Request $request): Response
    {
        return $this->ok($this->c->account()->addresses());
    }

    public function address(Request $request): Response
    {
        return $this->ok($this->c->account()->address($request->param('id')));
    }

    public function addressCreate(Request $request): Response
    {
        return $this->ok($this->c->account()->createAddress($request->input()), 201);
    }

    public function addressUpdate(Request $request): Response
    {
        return $this->ok($this->c->account()->updateAddress($request->param('id'), $request->input()));
    }

    public function addressDelete(Request $request): Response
    {
        $this->c->account()->deleteAddress($request->param('id'));
        return $this->ok(['eliminado' => true]);
    }

    // ----------------------------------------------------------- Favoritos

    public function favorites(Request $request): Response
    {
        return $this->ok($this->c->account()->favorites());
    }

    public function favoriteAdd(Request $request): Response
    {
        $this->c->account()->addFavorite($request->get('producto_id'));
        return $this->ok(['favorito' => true], 201);
    }

    public function favoriteRemove(Request $request): Response
    {
        $this->c->account()->removeFavorite($request->param('id'));
        return $this->ok(['favorito' => false]);
    }
}
