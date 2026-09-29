<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Área del cliente: perfil, seguridad, pedidos, direcciones y favoritos.
 */
final class AccountController extends Controller
{
    public function profile(Request $request): Response
    {
        $orders = $this->c->orders()->list(1, 3);
        return $this->view('account/profile', [
            'title' => 'Mi perfil',
            'section' => 'perfil',
            'profile' => $this->c->account()->profile(),
            'recentOrders' => $orders['items'],
            'ordersCount' => $orders['total'],
            'addressesCount' => count($this->c->account()->addresses()),
        ]);
    }

    public function profileUpdate(Request $request): Response
    {
        $this->c->account()->updateProfile($request->post);
        return $this->redirect('/cuenta', 'success', 'Tus datos fueron actualizados.');
    }

    public function passwordUpdate(Request $request): Response
    {
        $v = new Validator($request->post);
        $actual = $v->password('password_actual', false);
        $nueva = $v->password('password');
        if (($request->post['password_confirmacion'] ?? null) !== $nueva) {
            $v->addError('password_confirmacion', 'Las contraseñas no coinciden.');
        }
        $v->check();
        $this->c->auth()->changePassword($this->user(), (string) $actual, (string) $nueva, $request);
        return $this->redirect('/cuenta', 'success', 'Tu contraseña fue actualizada.');
    }

    public function orders(Request $request): Response
    {
        $page = (int) ($request->query['pagina'] ?? 1);
        $estado = $request->queryString('estado');
        return $this->view('account/orders', [
            'title' => 'Mis pedidos',
            'section' => 'pedidos',
            'result' => $this->c->orders()->list($page, 10, $estado ?: null),
            'estado' => $estado,
            'statuses' => $this->c->adminOrders()->statuses(),
        ]);
    }

    public function order(Request $request): Response
    {
        $order = $this->c->orders()->get($request->param('id'));
        return $this->view('account/order', ['title' => 'Pedido ' . numero_pedido($order['numero']), 'section' => 'pedidos', 'order' => $order]);
    }

    public function receipt(Request $request): Response
    {
        $order = $this->c->orders()->get($request->param('id'));
        return $this->view('account/receipt', ['title' => 'Comprobante ' . numero_pedido($order['numero']), 'order' => $order, 'hideFooter' => true]);
    }

    public function cancelOrder(Request $request): Response
    {
        $id = $request->param('id');
        try {
            $this->c->orders()->cancel($id);
        } catch (HttpException $e) {
            if ($e->status >= 500) {
                throw $e;
            }
            return $this->redirect('/cuenta/pedidos/' . rawurlencode($id), 'danger', $e->getMessage());
        }
        return $this->redirect('/cuenta/pedidos/' . rawurlencode($id), 'success', 'Tu pedido fue cancelado.');
    }

    public function addresses(Request $request): Response
    {
        return $this->view('account/addresses', [
            'title' => 'Mis direcciones',
            'section' => 'direcciones',
            'addresses' => $this->c->account()->addresses(),
        ]);
    }

    public function addressSave(Request $request): Response
    {
        $id = $request->post['id'] ?? '';
        if (is_string($id) && $id !== '') {
            $this->c->account()->updateAddress($id, $request->post);
            return $this->redirect('/cuenta/direcciones', 'success', 'Dirección actualizada.');
        }
        $this->c->account()->createAddress($request->post);
        return $this->redirect('/cuenta/direcciones', 'success', 'Dirección agregada.');
    }

    public function addressDelete(Request $request): Response
    {
        $this->c->account()->deleteAddress($request->param('id'));
        return $this->redirect('/cuenta/direcciones', 'success', 'Dirección eliminada.');
    }

    public function favorites(Request $request): Response
    {
        $favorites = $this->c->account()->favorites();
        return $this->view('account/favorites', [
            'title' => 'Favoritos',
            'section' => 'favoritos',
            'favorites' => $favorites,
            'favIds' => array_map(static fn ($p) => (int) $p['id'], $favorites),
        ]);
    }
}
