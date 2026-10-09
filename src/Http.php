<?php
declare(strict_types=1);

/** Error controlado de la API: se convierte en una respuesta JSON con su código HTTP. */
class ApiException extends Exception
{
    public function __construct(
        public int $status,
        string $message,
        public array $errors = []
    ) {
        parent::__construct($message);
    }
}

/** Datos de la petición actual. */
class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        private ?array $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $_GET,
            null
        );
    }

    /** Cuerpo JSON como arreglo. Lanza 400 si no es un JSON válido. */
    public function json(): array
    {
        if ($this->body === null) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw === false ? '' : $raw, true);
            if (!is_array($data)) {
                throw new ApiException(400, 'El cuerpo debe ser un JSON válido.');
            }
            $this->body = $data;
        }
        return $this->body;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
