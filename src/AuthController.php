<?php
declare(strict_types=1);

class AuthController
{
    public function register(Request $request, array $params): void
    {
        $data = $request->json();
        $nombre = trim((string) ($data['nombre'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        $errors = [];
        if ($nombre === '' || mb_strlen($nombre) > 80) {
            $errors['nombre'] = 'Obligatorio, máximo 80 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
            $errors['email'] = 'Correo inválido.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Mínimo 8 caracteres.';
        }
        if ($errors) {
            throw new ApiException(422, 'Datos inválidos.', $errors);
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO usuarios (nombre, email, password_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([$nombre, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiException(409, 'Ya existe una cuenta con ese correo.');
            }
            throw $e;
        }

        respond(201, ['usuario' => [
            'id' => (int) db()->lastInsertId(),
            'nombre' => $nombre,
            'email' => $email,
        ]]);
    }

    public function login(Request $request, array $params): void
    {
        $data = $request->json();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        $stmt = db()->prepare('SELECT id, nombre, password_hash FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // Si el correo no existe se verifica contra un hash bcrypt válido (de una clave
        // que nadie usa), para que el tiempo de respuesta no delate qué correos existen.
        $hash = $usuario['password_hash']
            ?? '$2y$10$bP.uDE9JOLDT9O4f5LRGDesivvw4xfAE.2oSZ6IR1L98/DsnV8Ws2';
        $valido = password_verify($password, $hash);

        if (!$usuario || !$valido) {
            throw new ApiException(401, 'Correo o contraseña incorrectos.');
        }

        $token = bin2hex(random_bytes(32));
        $horas = (int) (config()['token_horas'] ?? 12);
        $stmt = db()->prepare(
            'INSERT INTO tokens (usuario_id, token_hash, expira_en)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))'
        );
        $stmt->execute([$usuario['id'], hash('sha256', $token), $horas]);

        respond(200, [
            'token' => $token,
            'tipo' => 'Bearer',
            'expira_en_horas' => $horas,
            'usuario' => ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre']],
        ]);
    }

    public function logout(Request $request, array $params): void
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            $stmt = db()->prepare('DELETE FROM tokens WHERE token_hash = ?');
            $stmt->execute([hash('sha256', $token)]);
        }
        respond(200, ['ok' => true]);
    }

    /** Devuelve el id del usuario dueño del token, o lanza 401. */
    public static function authenticate(Request $request): int
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(401, 'Falta el token. Usa la cabecera Authorization: Bearer <token>.');
        }

        $stmt = db()->prepare(
            'SELECT usuario_id FROM tokens WHERE token_hash = ? AND expira_en > NOW()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException(401, 'Token inválido o vencido.');
        }
        return (int) $row['usuario_id'];
    }
}
