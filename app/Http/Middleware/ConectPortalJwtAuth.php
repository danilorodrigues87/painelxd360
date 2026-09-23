<?php

namespace App\Http\Middleware;

use App\Common\Helpers\ConectCandidatoAuthHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Aceita JWT de candidato ou empresa para ações compartilhadas (ex.: comentários no blog).
 */
class ConectPortalJwtAuth {

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
		$user = $id > 0 ? User::getUserById($id) : null;
		if (!$user instanceof User) {
			throw new \Exception('Usuário não encontrado', 403);
		}

		$nivel = (string)($user->nivel ?? '');
		if ($nivel === 'Empresa') {
			$empresa = CjEmpresa::getByUsuarioId((int)$user->id);
			if (!$empresa instanceof CjEmpresa) {
				throw new \Exception('Perfil de empresa não encontrado', 403);
			}
			$request->user = $user;
			$request->empresa = $empresa;
			$request->conectRole = 'empresa';
			$request->jwtClaims = $decode;
			return $next($request);
		}

		if (!ConectCandidatoAuthHelper::podeAcessarConect($user)) {
			throw new \Exception('Acesso negado', 403);
		}
		$candidato = ConectCandidatoAuthHelper::resolverPerfil($user);
		if (!$candidato instanceof CjCandidato) {
			throw new \Exception('Perfil de candidato não encontrado', 403);
		}
		$request->user = $user;
		$request->candidato = $candidato;
		$request->conectRole = 'candidato';
		$request->jwtClaims = $decode;
		return $next($request);
	}
}
