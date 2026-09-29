<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Motor de plantillas PHP nativo. Toda salida dinámica en las vistas debe
 * pasar por e() (htmlspecialchars) para prevenir XSS.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile('partials/' . $template, $data);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/\-]+$#', $template)) {
            throw new \InvalidArgumentException('Nombre de plantilla inválido.');
        }
        $file = $this->basePath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vista no encontrada: $template");
        }

        $view = $this;
        $vars = $data + $this->shared;
        ob_start();
        try {
            (static function () use ($file, $vars, $view): void {
                extract($vars, EXTR_SKIP);
                require $file;
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
