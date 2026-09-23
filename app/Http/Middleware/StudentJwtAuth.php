<?php

namespace App\Http\Middleware;

use App\Model\Entity\User;
use App\Model\Entity\ClientesAssinantes;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class StudentJwtAuth {

	public function handle($request, $next) {
		$headers = $request->getHeaders();
		$auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
		$jwt = is_string($auth) ? preg_replace('/^Bearer\s+/i', '', $auth) : '';
		if ($jwt === '') {
			throw new \Exception('Token ausente', 401);
		}
		try {
			$decode = (array)JWT::decode($jwt, new Key(getenv('JWT_KEY') ?: 'change-me', 'HS256'));
		} catch (\Throwable $e) {
			throw new \Exception('Token inválido', 401);
		}

		$id = (int)($decode['sub'] ?? 0);
		$email = (string)($decode['email'] ?? '');
		$obUser = $id > 0 ? User::getUserById($id) : ($email !== '' ? User::getUserByEmail($email) : null);
		if (!$obUser instanceof User) {
			throw new \Exception('Usuário inválido', 401);
		}
		if (($obUser->nivel ?? '') !== 'Cliente') {
			throw new \Exception('Acesso apenas para alunos', 403);
		}
		$escola = ClientesAssinantes::getEscolaById((int)$obUser->id_admin);
		if (!$escola || !$escola->isAtiva()) {
			throw new \Exception('Escola inativa', 403);
		}

		$request->user = $obUser;
		$request->jwtClaims = $decode;
		return $next($request);
	}
}
