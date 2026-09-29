<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\CurrentUser;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Response;

abstract class Controller
{
    public function __construct(protected readonly Container $c)
    {
    }

    protected function user(): CurrentUser
    {
        return $this->c->user ?? throw HttpException::unauthorized();
    }

    /** @param array<string, mixed>|null $meta */
    protected function ok(mixed $data = null, int $status = 200, ?array $meta = null): Response
    {
        return Response::success($data, $status, $meta);
    }

    /** @param array{items: list<mixed>, total: int, page: int, per_page: int, pages: int} $page */
    protected function paginated(array $page): Response
    {
        return Response::success($page['items'], 200, [
            'total' => $page['total'],
            'pagina' => $page['page'],
            'por_pagina' => $page['per_page'],
            'paginas' => $page['pages'],
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], string $layout = 'shop', int $status = 200): Response
    {
        $this->c->shareViewGlobals();
        return Response::html($this->c->view()->render($template, $data, $layout), $status);
    }

    protected function redirect(string $to, ?string $flashType = null, ?string $message = null): Response
    {
        if ($flashType !== null && $message !== null) {
            $this->c->session()->flash($flashType, $message);
        }
        return Response::redirect($to);
    }
}
